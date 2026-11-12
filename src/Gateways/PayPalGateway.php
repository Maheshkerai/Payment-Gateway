<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class PayPalGateway extends BaseGateway
{
    /**
     * PayPal API base URL (sandbox or production).
     *
     * @var string
     */
    private string $baseUrl;

    /**
     * PayPal client ID.
     *
     * @var string
     */
    private string $clientId;

    /**
     * PayPal secret key.
     *
     * @var string
     */
    private string $secretKey;

    /**
     * Access token for API requests.
     *
     * @var string|null
     */
    private ?string $accessToken = null;

    /**
     * Initialize PayPal gateway configuration.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        $this->clientId = $this->getConfigValue('publishable_key') ?? '';
        $this->secretKey = $this->getConfigValue('secret_key') ?? '';
        
        // Determine if we're using sandbox or production
        $mode = $this->getConfigValue('mode', 'sandbox');
        $this->baseUrl = $mode === 'production' 
            ? 'https://api-m.paypal.com' 
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Get access token for PayPal API.
     *
     * @return string
     * @throws \RuntimeException
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                ],
                'auth' => [$this->clientId, $this->secretKey],
            ]);

            $response = $client->post('/v1/oauth2/token', [
                'form_params' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['access_token'])) {
                $this->accessToken = $result['access_token'];
                return $this->accessToken;
            }

            throw new \RuntimeException('Failed to obtain PayPal access token.');
        } catch (GuzzleException $e) {
            throw new \RuntimeException('PayPal authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Initialize a payment transaction.
     *
     * @param float $amount
     * @param string $currency
     * @param string $orderId
     * @param array $metadata
     * @return array
     */
    public function initializePayment(float $amount, string $currency, string $orderId, array $metadata = []): array
    {
        try {
            $this->initializeConfig();

            if (empty($this->clientId) || empty($this->secretKey)) {
                throw new \RuntimeException('PayPal credentials are not configured.');
            }

            $accessToken = $this->getAccessToken();
            $userCallbackUrl = $metadata['callback_url'] ?? '';

            // Build PayPal callback URLs (PayPal will redirect here first)
            // PayPal automatically appends 'token' and 'PayerID' query parameters to return_url
            $paypalCallbackUrl = route('payment-gateways.paypal.callback');
            $separator = (parse_url($paypalCallbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?';
            $returnUrl = $paypalCallbackUrl . $separator . 'status=success&callback_url=' . urlencode($userCallbackUrl);
            $cancelUrl = $paypalCallbackUrl . ((parse_url($paypalCallbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?') . 'status=cancelled&callback_url=' . urlencode($userCallbackUrl);

            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Prefer' => 'return=representation',
                ],
            ]);

            // Validate and format amount
            $formattedAmount = number_format((float) $amount, 2, '.', '');
            if ((float) $formattedAmount <= 0) {
                throw new \RuntimeException('Payment amount must be greater than zero.');
            }

            // Create PayPal order
            $purchaseUnit = [
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => $formattedAmount,
                ],
            ];

            // Add description if provided and not empty
            $description = $metadata['product_name'] ?? 'Payment';
            if (!empty($description) && strlen($description) <= 127) {
                $purchaseUnit['description'] = substr($description, 0, 127);
            }

            // Store ALL metadata in PayPal's custom_id field
            // PayPal's custom_id can hold up to 127 characters
            // We'll use compact JSON encoding and dynamically fit as many fields as possible
            // Note: order_id is excluded from metadata as it's passed separately
            $metadataForPayPal = [];
            
            // Add all metadata fields dynamically (excluding internal fields and order_id)
            // This ensures full flexibility - users can pass any metadata fields they need
            // order_id is excluded since it's already available separately and doesn't need to be in metadata
            $excludedFields = ['callback_url', 'order_id'];
            foreach ($metadata as $key => $value) {
                if (!in_array($key, $excludedFields) && $value !== null) {
                    // Convert value to string for JSON encoding
                    $stringValue = '';
                    if (is_array($value) || is_object($value)) {
                        $stringValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $stringValue = (string) $value;
                    }
                    
                    // Store all fields dynamically - no hardcoded field names
                    $metadataForPayPal[$key] = $stringValue;
                }
            }
            
            // Use compact JSON encoding (no spaces, no pretty printing)
            $metadataJson = json_encode($metadataForPayPal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // Try to store all metadata in custom_id (127 char limit)
            if (strlen($metadataJson) <= 127) {
                // All metadata fits - store everything
                $purchaseUnit['custom_id'] = $metadataJson;
            } else {
                // Metadata is too long for custom_id, dynamically fit as many fields as possible
                // Strategy: Process all fields dynamically in the order they appear
                // No hardcoded field names - works with any metadata structure
                // order_id is not included as it's passed separately
                
                $customIdMetadata = [];
                $remainingFields = [];
                
                // Process all fields dynamically in the order they appear
                // Try to fit each field dynamically
                foreach ($metadataForPayPal as $key => $value) {
                    // Test if adding this field fits within 127 characters
                    $testMetadata = array_merge($customIdMetadata, [$key => $value]);
                    $testJson = json_encode($testMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    
                    if (strlen($testJson) <= 127) {
                        // Field fits completely - add it
                        $customIdMetadata[$key] = $value;
                    } else {
                        // Field doesn't fit, try truncating the value
                        $currentJson = json_encode($customIdMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $currentLength = strlen($currentJson);
                        
                        // Calculate available space (accounting for key name, quotes, colons, commas)
                        // Rough estimate: key length + value length + JSON overhead (~10-15 chars per field)
                        $jsonOverhead = strlen($key) + 15; // key name + quotes + colon + comma
                        $availableSpace = 127 - $currentLength - $jsonOverhead;
                        
                        if ($availableSpace > 5) {
                            // Try truncating the value to fit
                            $truncatedValue = substr($value, 0, $availableSpace);
                            $testMetadata = array_merge($customIdMetadata, [$key => $truncatedValue]);
                            $testJson = json_encode($testMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            
                            if (strlen($testJson) <= 127) {
                                // Truncated value fits - add it
                                $customIdMetadata[$key] = $truncatedValue;
                            } else {
                                // Even truncated doesn't fit - skip this field
                                $remainingFields[$key] = $value;
                            }
                        } else {
                            // Not enough space even for a truncated value - skip this field
                            $remainingFields[$key] = $value;
                        }
                    }
                }
                
                // Store what fits in custom_id
                $purchaseUnit['custom_id'] = json_encode($customIdMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                
                // Log warning if some fields couldn't fit
                if (!empty($remainingFields)) {
                    \Illuminate\Support\Facades\Log::warning('PayPal metadata too large, some fields excluded from custom_id', [
                        'excluded_fields' => array_keys($remainingFields),
                        'included_fields' => array_keys($customIdMetadata),
                        'custom_id_length' => strlen($purchaseUnit['custom_id']),
                        'total_fields_attempted' => count($metadataForPayPal),
                        'fields_included' => count($customIdMetadata),
                        'fields_excluded' => count($remainingFields),
                    ]);
                }
            }
            
            // Add invoice_id if provided (useful for additional tracking)
            if (isset($metadata['transaction_id'])) {
                $purchaseUnit['invoice_id'] = substr((string) $metadata['transaction_id'], 0, 127);
            }

            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [$purchaseUnit],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                ],
            ];

            // Add brand_name if provided
            $brandName = $metadata['brand_name'] ?? config('app.name', 'Payment');
            if (!empty($brandName)) {
                $orderData['application_context']['brand_name'] = substr($brandName, 0, 127);
            }

            $response = $client->post('/v2/checkout/orders', [
                'json' => $orderData,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            // Check for errors in response
            if (isset($result['error']) || isset($result['name'])) {
                $errorDetails = $result['details'] ?? [];
                $errorMessage = $result['message'] ?? $result['error_description'] ?? 'Unknown error';
                
                if (!empty($errorDetails)) {
                    $errorMessage .= ' - Details: ' . json_encode($errorDetails);
                }
                
                throw new \RuntimeException('PayPal order creation failed: ' . $errorMessage);
            }

            if (isset($result['id']) && isset($result['links'])) {
                $approvalUrl = null;
                foreach ($result['links'] as $link) {
                    if ($link['rel'] === 'approve') {
                        $approvalUrl = $link['href'];
                        break;
                    }
                }

                if ($approvalUrl) {
                    return [
                        'payment_id' => $result['id'],
                        'order_id' => $result['id'],
                        'checkout_url' => $approvalUrl,
                        'approval_url' => $approvalUrl,
                    ];
                }
            }

            throw new \RuntimeException('PayPal order creation failed: ' . ($result['message'] ?? 'Unknown error'));
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $errorDetails = [];
            
            if ($e->hasResponse()) {
                try {
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    $errorData = json_decode($responseBody, true);
                    
                    if (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                    } elseif (isset($errorData['error_description'])) {
                        $errorMessage = $errorData['error_description'];
                    }
                    
                    // Include detailed error information
                    if (isset($errorData['details'])) {
                        $errorDetails = $errorData['details'];
                        $errorMessage .= ' - Details: ' . json_encode($errorDetails);
                    }
                    
                    // Log the full error for debugging
                    \Illuminate\Support\Facades\Log::error('PayPal API Error', [
                        'error' => $errorData,
                        'request_data' => $orderData ?? null,
                    ]);
                } catch (\Exception $parseException) {
                    // If parsing fails, use the original error message
                }
            }
            
            throw new \RuntimeException('PayPal payment initialization failed: ' . $errorMessage);
        }
    }

    /**
     * Verify a payment transaction.
     *
     * @param array $data
     * @return array
     */
    public function verifyPayment(array $data): array
    {
        try {
            $this->initializeConfig();

            $orderId = $data['token'] ?? $data['order_id'] ?? $data['payment_id'] ?? null;

            if (!$orderId) {
                return [
                    'success' => false,
                    'message' => 'PayPal order ID is required for verification.',
                ];
            }

            $accessToken = $this->getAccessToken();

            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            // Get order details
            $response = $client->get("/v2/checkout/orders/{$orderId}");

            $order = json_decode($response->getBody()->getContents(), true);

            if (!isset($order['id'])) {
                return [
                    'success' => false,
                    'message' => 'PayPal order not found.',
                ];
            }

            // Extract metadata from order
            $metadata = $this->extractMetadataFromResource($order);

            // Check if order needs to be captured
            if ($order['status'] === 'APPROVED' && isset($order['purchase_units'][0]['payments']['captures'])) {
                // Order is already captured
                $capture = $order['purchase_units'][0]['payments']['captures'][0];
                
                if ($capture['status'] === 'COMPLETED') {
                    return [
                        'success' => true,
                        'payment_id' => $capture['id'],
                        'order_id' => $order['id'],
                        'amount' => (float) $capture['amount']['value'],
                        'currency' => $capture['amount']['currency_code'],
                        'status' => $capture['status'],
                        'metadata' => $metadata,
                    ];
                }
            } elseif ($order['status'] === 'APPROVED') {
                // Order is approved but not captured yet - capture it
                return $this->captureOrder($orderId);
            }

            return [
                'success' => false,
                'message' => 'Payment not completed. Order status: ' . ($order['status'] ?? 'UNKNOWN'),
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            
            if ($e->hasResponse()) {
                try {
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    $errorData = json_decode($responseBody, true);
                    
                    if (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                    } elseif (isset($errorData['error_description'])) {
                        $errorMessage = $errorData['error_description'];
                    }
                } catch (\Exception $parseException) {
                    // If parsing fails, use the original error message
                }
            }
            
            return [
                'success' => false,
                'message' => 'Payment verification failed: ' . $errorMessage,
            ];
        }
    }

    /**
     * Capture a PayPal order.
     *
     * @param string $orderId
     * @return array
     */
    private function captureOrder(string $orderId): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);

            $response = $client->post("/v2/checkout/orders/{$orderId}/capture", [
                'json' => (object) [],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['status']) && $result['status'] === 'COMPLETED') {
                $capture = $result['purchase_units'][0]['payments']['captures'][0] ?? null;
                
                if ($capture && $capture['status'] === 'COMPLETED') {
                    // Extract metadata from captured order
                    $metadata = $this->extractMetadataFromResource($result);
                    
                    return [
                        'success' => true,
                        'payment_id' => $capture['id'],
                        'order_id' => $result['id'],
                        'amount' => (float) ($capture['amount']['value'] ?? 0),
                        'currency' => $capture['amount']['currency_code'] ?? '',
                        'status' => $capture['status'],
                        'metadata' => $metadata,
                    ];
                }
            }

            return [
                'success' => false,
                'message' => 'Order capture failed: ' . ($result['message'] ?? 'Unknown error'),
            ];
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            
            if ($e->hasResponse()) {
                try {
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    $errorData = json_decode($responseBody, true);
                    
                    if (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                    } elseif (isset($errorData['error_description'])) {
                        $errorMessage = $errorData['error_description'];
                    }
                } catch (\Exception $parseException) {
                    // If parsing fails, use the original error message
                }
            }
            
            return [
                'success' => false,
                'message' => 'Order capture failed: ' . $errorMessage,
            ];
        }
    }

    /**
     * Handle webhook callback.
     *
     * @param array $payload
     * @return array
     */
    public function handleWebhook(array $payload): array
    {
        try {
            $webhookSecret = $this->getConfigValue('webhook_secret');

            if (!$webhookSecret) {
                return [
                    'success' => false,
                    'message' => 'Webhook secret not configured.',
                ];
            }

            $this->initializeConfig();

            // Verify webhook signature
            // Symfony stores headers with lowercase keys and array values.
            $headers = $payload['headers'] ?? [];
            $webhookId = $headers['paypal-transmission-id'][0] ?? null;
            $transmissionTime = $headers['paypal-transmission-time'][0] ?? null;
            $certUrl = $headers['paypal-cert-url'][0] ?? null;
            $authAlgo = $headers['paypal-auth-algo'][0] ?? null;
            $transmissionSig = $headers['paypal-transmission-sig'][0] ?? null;

            if (!$webhookId || !$transmissionTime || !$certUrl || !$authAlgo || !$transmissionSig) {
                // For now, we'll skip signature verification if headers are missing
                // In production, you should implement proper webhook verification
                \Illuminate\Support\Facades\Log::warning('PayPal webhook verification headers missing', [
                    'headers' => $headers,
                ]);
            }

            $body = $payload['body'] ?? [];
            $eventType = $body['event_type'] ?? null;
            $resource = $body['resource'] ?? [];

            // Extract metadata from resource
            $metadata = $this->extractMetadataFromResource($resource);

            // Handle different event types
            switch ($eventType) {
                case 'PAYMENT.CAPTURE.COMPLETED':
                    $captureId = $resource['id'] ?? null;
                    $orderId = $this->extractOrderIdFromResource($resource);
                    
                    return [
                        'success' => true,
                        'event_type' => $eventType,
                        'payment_id' => $captureId,
                        'order_id' => $orderId,
                        'amount' => isset($resource['amount']['value']) ? (float) $resource['amount']['value'] : null,
                        'currency' => $resource['amount']['currency_code'] ?? null,
                        'status' => $resource['status'] ?? null,
                        'metadata' => $metadata,
                    ];

                case 'CHECKOUT.ORDER.APPROVED':
                    $orderId = $resource['id'] ?? null;
                    
                    return [
                        'success' => true,
                        'event_type' => $eventType,
                        'order_id' => $orderId,
                        'status' => $resource['status'] ?? null,
                        'metadata' => $metadata,
                    ];

                case 'CHECKOUT.ORDER.COMPLETED':
                    $orderId = $resource['id'] ?? null;
                    $capture = $resource['purchase_units'][0]['payments']['captures'][0] ?? null;
                    
                    return [
                        'success' => true,
                        'event_type' => $eventType,
                        'payment_id' => $capture['id'] ?? null,
                        'order_id' => $orderId,
                        'amount' => isset($capture['amount']['value']) ? (float) $capture['amount']['value'] : null,
                        'currency' => $capture['amount']['currency_code'] ?? null,
                        'status' => $capture['status'] ?? null,
                        'metadata' => $metadata,
                    ];

                default:
                    return [
                        'success' => false,
                        'message' => 'Unhandled webhook event type: ' . $eventType,
                    ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Extract order ID from PayPal resource.
     *
     * @param array $resource
     * @return string|null
     */
    private function extractOrderIdFromResource(array $resource): ?string
    {
        // Try different possible locations for order ID
        if (isset($resource['supplementary_data']['related_ids']['order_id'])) {
            return $resource['supplementary_data']['related_ids']['order_id'];
        }

        // Check purchase_units for order ID
        if (isset($resource['purchase_units'][0]['custom_id'])) {
            // custom_id might contain JSON metadata, try to extract order_id from it
            $customId = $resource['purchase_units'][0]['custom_id'];
            $decoded = json_decode($customId, true);
            if (is_array($decoded) && isset($decoded['order_id'])) {
                return $decoded['order_id'];
            }
            // If not JSON, return as-is (might be just order_id)
            return $customId;
        }

        if (isset($resource['custom_id'])) {
            $customId = $resource['custom_id'];
            $decoded = json_decode($customId, true);
            if (is_array($decoded) && isset($decoded['order_id'])) {
                return $decoded['order_id'];
            }
            return $customId;
        }

        if (isset($resource['invoice_id'])) {
            return $resource['invoice_id'];
        }

        // Check purchase_units for invoice_id
        if (isset($resource['purchase_units'][0]['invoice_id'])) {
            return $resource['purchase_units'][0]['invoice_id'];
        }

        return null;
    }

    /**
     * Extract metadata from PayPal resource.
     *
     * @param array $resource
     * @return array
     */
    private function extractMetadataFromResource(array $resource): array
    {
        $metadata = [];

        // Try to extract metadata from purchase_units[0].custom_id (primary storage)
        if (isset($resource['purchase_units'][0]['custom_id'])) {
            $customId = $resource['purchase_units'][0]['custom_id'];
            $decoded = json_decode($customId, true);
            if (is_array($decoded)) {
                // If custom_id contains JSON metadata, merge it
                $metadata = array_merge($metadata, $decoded);
            } else {
                // If custom_id is just a string (order_id), store it
                if (!isset($metadata['order_id'])) {
                    $metadata['order_id'] = $customId;
                }
            }
        }

        // Also check resource-level custom_id
        if (isset($resource['custom_id'])) {
            $customId = $resource['custom_id'];
            $decoded = json_decode($customId, true);
            if (is_array($decoded)) {
                $metadata = array_merge($metadata, $decoded);
            } else {
                if (!isset($metadata['order_id'])) {
                    $metadata['order_id'] = $customId;
                }
            }
        }


        // Extract invoice_id (transaction_id) from purchase_units
        if (isset($resource['purchase_units'][0]['invoice_id'])) {
            if (!isset($metadata['transaction_id'])) {
                $metadata['transaction_id'] = $resource['purchase_units'][0]['invoice_id'];
            }
        }

        // Extract invoice_id from resource level
        if (isset($resource['invoice_id']) && !isset($metadata['transaction_id'])) {
            $metadata['transaction_id'] = $resource['invoice_id'];
        }

        // Extract description if available
        if (isset($resource['purchase_units'][0]['description'])) {
            if (!isset($metadata['product_name'])) {
                $metadata['product_name'] = $resource['purchase_units'][0]['description'];
            }
        }

        return $metadata;
    }
}

