<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use PhonePe\payments\v2\standardCheckout\StandardCheckoutClient;
use PhonePe\payments\v2\models\request\builders\StandardCheckoutPayRequestBuilder;
use PhonePe\payments\v2\models\response\StandardCheckoutPayResponse;
use PhonePe\payments\v2\models\response\StatusCheckResponse;
use PhonePe\payments\v2\models\response\CallbackResponse;
use PhonePe\common\exceptions\PhonePeException;
use PhonePe\Env;

final class PhonePeGateway extends BaseGateway
{
    /**
     * PhonePe client instance.
     *
     * @var StandardCheckoutClient|null
     */
    private ?StandardCheckoutClient $client = null;

    /**
     * PhonePe client ID.
     *
     * @var string
     */
    private string $clientId;

    /**
     * PhonePe client version.
     *
     * @var int
     */
    private int $clientVersion;

    /**
     * PhonePe client secret.
     *
     * @var string
     */
    private string $clientSecret;

    /**
     * PhonePe environment (UAT or PRODUCTION).
     *
     * @var string
     */
    private string $env;

    /**
     * PhonePe username for webhook authentication.
     *
     * @var string
     */
    private string $username;

    /**
     * PhonePe password for webhook authentication.
     *
     * @var string
     */
    private string $password;

    /**
     * Initialize PhonePe gateway configuration.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        $this->clientId = $this->getConfigValue('publishable_key') ?? ''; // Client ID stored in publishable_key
        $this->clientSecret = $this->getConfigValue('secret_key') ?? ''; // Client Secret stored in secret_key
        $this->clientVersion = (int) ($this->getConfigValue('client_version', 1)); // Client Version, default to 1
        $this->username = $this->getConfigValue('username') ?? ''; // Username for webhook authentication
        $this->password = $this->getConfigValue('password') ?? ''; // Password for webhook authentication
        
        // Validate required credentials
        if (empty($this->clientId)) {
            throw new \RuntimeException('PhonePe Client ID is not configured. Please check your publishable_key in the database.');
        }
        
        if (empty($this->clientSecret)) {
            throw new \RuntimeException('PhonePe Client Secret is not configured. Please check your secret_key in the database.');
        }
        
        // Determine environment (UAT or PRODUCTION)
        $mode = $this->getConfigValue('mode', 'uat');
        $this->env = ($mode === 'production' || $mode === 'prod') ? Env::PRODUCTION : Env::UAT;
    }

    /**
     * Get PhonePe client instance.
     *
     * @return StandardCheckoutClient
     */
    private function getClient(): StandardCheckoutClient
    {
        if ($this->client === null) {
            $this->initializeConfig();
            $this->client = StandardCheckoutClient::getInstance(
                $this->clientId,
                $this->clientVersion,
                $this->clientSecret,
                $this->env,
                false // shouldPublishEvents
            );
        }
        
        return $this->client;
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
            $client = $this->getClient();

            $callbackUrl = $metadata['callback_url'] ?? '';
            
            // PhonePe expects amount in paise (smallest currency unit for INR)
            // Convert amount to paise (multiply by 100)
            $amountInPaise = (int) round($amount * 100);

            // Build redirect URL - PhonePe will redirect here after payment
            // Route through unified callback to preserve metadata
            $unifiedCallbackUrl = route('payment-gateways.callback');
            $queryParams = [];
            
            // Add essential parameters
            $queryParams['order_id'] = $orderId;
            if (isset($metadata['transaction_id'])) {
                $queryParams['transaction_id'] = $metadata['transaction_id'];
            }
            
            // Preserve the original callback URL and metadata
            $queryParams['original_callback_url'] = $callbackUrl;
            
            // Parse original callback URL to extract metadata
            $parsedUrl = parse_url($callbackUrl);
            $originalQueryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $originalQueryParams);
            }
            
            // If metadata parameter exists in original callback, preserve it
            if (isset($originalQueryParams['metadata'])) {
                $queryParams['metadata'] = $originalQueryParams['metadata'];
            } else {
                // Encode all metadata as a single parameter
                $metadataToEncode = [];
                foreach ($metadata as $key => $value) {
                    if (!in_array($key, ['callback_url', 'transaction_id', 'order_id']) && $value !== null) {
                        $metadataToEncode[$key] = $value;
                    }
                }
                if (!empty($metadataToEncode)) {
                    $queryParams['metadata'] = base64_encode(json_encode($metadataToEncode));
                }
            }
            
            $redirectUrl = $unifiedCallbackUrl . '?' . http_build_query($queryParams);

            // Build payment request using PhonePe SDK builder
            $paymentRequest = StandardCheckoutPayRequestBuilder::builder()
                ->merchantOrderId($orderId)
                ->amount($amountInPaise)
                ->message($metadata['product_name'] ?? 'Payment for order ' . $orderId)
                ->redirectUrl($redirectUrl);

            // Prepare full metadata for PhonePe metaInfo (udf1..udf5)
            // We store the entire user-defined metadata (except internal fields) as JSON in udf1.
            // This JSON will later be available in webhook payload under payload.metaInfo.udf1.
            $metadataForPhonePe = [];
            $excludedFields = ['callback_url']; // internal field
            foreach ($metadata as $key => $value) {
                if (!in_array($key, $excludedFields, true) && $value !== null) {
                    $metadataForPhonePe[$key] = $value;
                }
            }

            if (!empty($metadataForPhonePe)) {
                $metadataJson = json_encode($metadataForPhonePe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // Be defensive with size; PhonePe docs don't specify a tiny limit for metaInfo,
                // but we truncate very large payloads to avoid request errors.
                if (strlen($metadataJson) > 4000) {
                    $metadataJson = substr($metadataJson, 0, 4000);
                }
                $paymentRequest->udf1($metadataJson);
            }

            // Optionally preserve explicit udf2..udf5 if merchant passes them
            if (isset($metadata['udf2'])) {
                $paymentRequest->udf2($metadata['udf2']);
            }
            if (isset($metadata['udf3'])) {
                $paymentRequest->udf3($metadata['udf3']);
            }
            if (isset($metadata['udf4'])) {
                $paymentRequest->udf4($metadata['udf4']);
            }
            if (isset($metadata['udf5'])) {
                $paymentRequest->udf5($metadata['udf5']);
            }

            // Build the request
            $payRequest = $paymentRequest->build();

            // Initiate payment using PhonePe SDK
            $paymentResponse = $client->pay($payRequest);

            if (!$paymentResponse || !$paymentResponse->getRedirectUrl()) {
                throw new \RuntimeException('PhonePe payment initialization failed: Invalid response from PhonePe API.');
            }

            return [
                'payment_id' => $paymentResponse->getOrderId() ?? $orderId,
                'order_id' => $orderId,
                'merchant_order_id' => $orderId,
                'checkout_url' => $paymentResponse->getRedirectUrl(),
                'redirect_url' => $paymentResponse->getRedirectUrl(),
                'phonepe_order_id' => $paymentResponse->getOrderId(),
                'state' => $paymentResponse->getState() ?? 'PENDING',
            ];
        } catch (PhonePeException $e) {
            throw new \RuntimeException('PhonePe payment initialization failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            throw new \RuntimeException('PhonePe payment initialization failed: ' . $e->getMessage());
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
            $client = $this->getClient();

            $merchantOrderId = $data['merchant_order_id'] ?? $data['order_id'] ?? null;

            if (!$merchantOrderId) {
                return [
                    'success' => false,
                    'message' => 'Merchant Order ID is required for verification.',
                ];
            }

            // Get order status using PhonePe SDK
            $statusResponse = $client->getOrderStatus($merchantOrderId, true);

            if (!$statusResponse) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found or invalid.',
                ];
            }

            // Convert amount from paise to rupees
            $amountInPaise = $statusResponse->getAmount() ?? 0;
            $amount = $amountInPaise / 100;

            // Check payment state
            $state = $statusResponse->getState() ?? '';
            $orderId = $statusResponse->getOrderId() ?? $merchantOrderId;

            // Payment is successful if state is PAYMENT_SUCCESS or COMPLETED
            if ($state === 'PAYMENT_SUCCESS' || $state === 'COMPLETED') {
                return [
                    'success' => true,
                    'payment_id' => $orderId,
                    'order_id' => $merchantOrderId,
                    'merchant_order_id' => $merchantOrderId,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'success',
                    'state' => $state,
                ];
            }

            // Check if payment is pending
            if ($state === 'PENDING' || $state === 'INITIATED') {
                return [
                    'success' => false,
                    'message' => 'Payment is pending. State: ' . $state,
                    'status' => 'pending',
                    'state' => $state,
                ];
            }

            // Payment failed
            return [
                'success' => false,
                'message' => 'Payment failed. State: ' . $state,
                'status' => 'failed',
                'state' => $state,
            ];
        } catch (PhonePeException $e) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
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
            $this->initializeConfig();
            $client = $this->getClient();

            $body = $payload['body'] ?? [];
            $headers = $payload['headers'] ?? [];

            // PhonePe webhook requires username and password for authentication
            if (empty($this->username) || empty($this->password)) {
                return [
                    'success' => false,
                    'message' => 'PhonePe webhook username and password are not configured.',
                ];
            }

            // Convert body to JSON string if it's an array
            $bodyJson = is_array($body) ? json_encode($body) : $body;
            
            // Verify callback response using PhonePe SDK
            $callbackResponse = $client->verifyCallbackResponse($headers, $bodyJson, $this->username, $this->password);

            if (!$callbackResponse) {
                return [
                    'success' => false,
                    'message' => 'Invalid webhook payload from PhonePe.',
                ];
            }

            // Extract data from callback response payload
            $payload = $callbackResponse->getPayload();
            $merchantOrderId = $payload->getMerchantOrderId() ?? null;
            $orderId = $payload->getOrderId() ?? $merchantOrderId;
            $state = $payload->getState() ?? '';
            $amount = $payload->getAmount() ?? 0;
            $amountInRupees = $amount / 100; // Convert from paise to rupees

            // Extract full metadata from webhook body (payload.metaInfo.udf1..udf5)
            $metadata = [];
            if (!empty($bodyJson)) {
                $decodedBody = json_decode($bodyJson, true);
                if (is_array($decodedBody) && isset($decodedBody['payload']['metaInfo']) && is_array($decodedBody['payload']['metaInfo'])) {
                    $metaInfo = $decodedBody['payload']['metaInfo'];

                    // Primary: udf1 holds JSON of full metadata we sent at initialization
                    if (isset($metaInfo['udf1']) && is_string($metaInfo['udf1']) && $metaInfo['udf1'] !== '') {
                        $decodedMeta = json_decode($metaInfo['udf1'], true);
                        if (is_array($decodedMeta)) {
                            $metadata = $decodedMeta;
                        } else {
                            $metadata['udf1'] = $metaInfo['udf1'];
                        }
                    }

                    // Also expose udf2..udf5 (do not overwrite existing keys)
                    foreach (['udf2', 'udf3', 'udf4', 'udf5'] as $udfKey) {
                        if (isset($metaInfo[$udfKey]) && !array_key_exists($udfKey, $metadata)) {
                            $metadata[$udfKey] = $metaInfo[$udfKey];
                        }
                    }
                }
            }

            if (!$merchantOrderId) {
                return [
                    'success' => false,
                    'message' => 'Merchant Order ID is missing in webhook.',
                ];
            }

            // Handle different payment states
            if ($state === 'PAYMENT_SUCCESS' || $state === 'COMPLETED') {
                return [
                    'success' => true,
                    'event_type' => 'PAYMENT_SUCCESS',
                    'payment_id' => $orderId,
                    'order_id' => $merchantOrderId,
                    'merchant_order_id' => $merchantOrderId,
                    'amount' => $amountInRupees,
                    'currency' => 'INR',
                    'status' => 'success',
                    'state' => $state,
                    'metadata' => $metadata,
                ];
            }

            if ($state === 'PENDING' || $state === 'INITIATED') {
                return [
                    'success' => false,
                    'message' => 'Payment is pending.',
                    'order_id' => $merchantOrderId,
                    'transaction_id' => $orderId,
                    'status' => 'pending',
                    'state' => $state,
                    'metadata' => $metadata,
                ];
            }

            // Payment failed
            return [
                'success' => false,
                'message' => 'Payment failed. State: ' . $state,
                'order_id' => $merchantOrderId,
                'transaction_id' => $orderId,
                'status' => 'failed',
                'state' => $state,
                'metadata' => $metadata,
            ];
        } catch (PhonePeException $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }
}

