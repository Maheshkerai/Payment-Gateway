<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Transaction;
use Midtrans\Notification;

final class MidtransGateway extends BaseGateway
{
    /**
     * Initialize Midtrans configuration.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        $serverKey = $this->getConfigValue('secret_key') ?? '';
        $clientKey = $this->getConfigValue('publishable_key') ?? '';
        $isProduction = $this->getConfigValue('mode', 'sandbox') === 'production';

        Config::$serverKey = $serverKey;
        Config::$clientKey = $clientKey;
        Config::$isProduction = $isProduction;
        Config::$isSanitized = true;
        Config::$is3ds = true;
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

            $serverKey = $this->getConfigValue('secret_key');
            $clientKey = $this->getConfigValue('publishable_key');
            $isProduction = $this->getConfigValue('mode', 'sandbox') === 'production';

            if (empty($serverKey) || empty($clientKey)) {
                throw new \RuntimeException('Midtrans credentials are not configured. Please check your Server Key and Client Key in the database.');
            }

            // Validate server key format
            $expectedPrefix = $isProduction ? 'Mid-server-' : 'SB-Mid-server-';
            if (!str_starts_with($serverKey, $expectedPrefix) && !str_starts_with($serverKey, 'Mid-server-')) {
                throw new \RuntimeException(
                    "Invalid Midtrans Server Key format. " .
                    "For sandbox, it should start with 'SB-Mid-server-'. " .
                    "For production, it should start with 'Mid-server-'. " .
                    "Current key: " . substr($serverKey, 0, 20) . "... " .
                    "Please check your Midtrans dashboard and update the Server Key in the payment_gateways table."
                );
            }

            // Validate client key format
            if (!str_starts_with($clientKey, 'Mid-client-') && !str_starts_with($clientKey, 'SB-Mid-client-')) {
                throw new \RuntimeException(
                    "Invalid Midtrans Client Key format. " .
                    "It should start with 'Mid-client-' or 'SB-Mid-client-'. " .
                    "Current key: " . substr($clientKey, 0, 20) . "... " .
                    "Please check your Midtrans dashboard and update the Client Key in the payment_gateways table."
                );
            }

            $callbackUrl = $metadata['callback_url'] ?? '';

            // Midtrans expects amount in smallest currency unit
            // For IDR (default), amount is in rupiah (no conversion needed)
            // For other currencies like USD, EUR, etc., convert to cents
            $currencyCode = strtoupper($currency);
            $grossAmount = $currencyCode === 'IDR' 
                ? (int) round($amount) 
                : (int) round($amount * 100);

            // Build transaction details
            $transactionDetails = [
                'order_id' => $orderId,
                'gross_amount' => $grossAmount,
            ];

            // Customer details
            $customerDetails = [
                'first_name' => $metadata['customer_name'] ?? $metadata['first_name'] ?? 'Customer',
                'last_name' => $metadata['last_name'] ?? '',
                'email' => $metadata['customer_email'] ?? $metadata['email'] ?? 'customer@example.com',
                'phone' => $metadata['customer_phone'] ?? $metadata['phone'] ?? '',
            ];

            // Item details
            $itemDetails = [
                [
                    'id' => $orderId,
                    'price' => $grossAmount,
                    'quantity' => 1,
                    'name' => $metadata['product_name'] ?? 'Payment',
                ],
            ];

            // Build transaction parameters
            $transactionParams = [
                'transaction_details' => $transactionDetails,
                'customer_details' => $customerDetails,
                'item_details' => $itemDetails,
            ];

            // Add callback URLs if provided
            // Midtrans will redirect to the finish URL after payment
            // We need to route through the unified callback to preserve metadata properly
            if (!empty($callbackUrl)) {
                // Parse the original callback URL to extract metadata
                $parsedUrl = parse_url($callbackUrl);
                $originalQueryParams = [];
                
                // Extract existing query parameters from callback URL (this includes the encoded 'metadata' parameter)
                if (isset($parsedUrl['query'])) {
                    parse_str($parsedUrl['query'], $originalQueryParams);
                }
                
                // Route through unified callback to preserve metadata
                // The unified callback will then redirect to the original callback URL with all metadata
                $unifiedCallbackUrl = route('payment-gateways.callback');
                $queryParams = [];
                
                // Add essential parameters
                $queryParams['order_id'] = $orderId;
                if (isset($metadata['transaction_id'])) {
                    $queryParams['transaction_id'] = $metadata['transaction_id'];
                }
                
                // Preserve the original callback URL and metadata
                // Store the original callback URL so unified callback can redirect there
                $queryParams['original_callback_url'] = $callbackUrl;
                
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
                
                // Build the finish URL that routes through unified callback
                $finishUrl = $unifiedCallbackUrl . '?' . http_build_query($queryParams);
                
                $transactionParams['callbacks'] = [
                    'finish' => $finishUrl,
                ];
            }

            // Add custom fields for additional metadata storage
            // custom_field1: transaction_id (for quick lookup)
            if (isset($metadata['transaction_id'])) {
                $transactionParams['custom_field1'] = (string) $metadata['transaction_id'];
            }

            // custom_field2: user_id (for quick lookup)
            if (isset($metadata['user_id'])) {
                $transactionParams['custom_field2'] = (string) $metadata['user_id'];
            }

            // custom_field3: full metadata (excluding internal fields) as compact JSON
            // This is the main place where we persist all user-defined metadata for webhook usage.
            $metadataForMidtrans = [];
            $excludedFields = ['callback_url']; // internal field, not needed in metadata
            foreach ($metadata as $key => $value) {
                if (!in_array($key, $excludedFields, true) && $value !== null) {
                    if (is_array($value) || is_object($value)) {
                        $stringValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    } else {
                        $stringValue = (string) $value;
                    }
                    $metadataForMidtrans[$key] = $stringValue;
                }
            }

            if (!empty($metadataForMidtrans)) {
                $metadataJson = json_encode($metadataForMidtrans, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // Midtrans docs state custom fields are strings; they don't give a very small limit,
                // but we'll be defensive and truncate very large payloads to avoid errors.
                // 2000 chars is a safe upper bound for most gateways; adjust if needed.
                if (strlen($metadataJson) > 2000) {
                    $metadataJson = substr($metadataJson, 0, 2000);
                }
                $transactionParams['custom_field3'] = $metadataJson;
            }

            // Create Snap transaction
            $snapToken = Snap::getSnapToken($transactionParams);

            if (!$snapToken) {
                throw new \RuntimeException('Failed to generate Midtrans snap token.');
            }

            // Build checkout URL
            $checkoutUrl = Config::$isProduction
                ? 'https://app.midtrans.com/snap/v2/vtweb/' . $snapToken
                : 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . $snapToken;

            return [
                'payment_id' => $orderId,
                'order_id' => $orderId,
                'snap_token' => $snapToken,
                'checkout_url' => $checkoutUrl,
                'snap_url' => $checkoutUrl,
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Midtrans payment initialization failed: ' . $e->getMessage());
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

            $orderId = $data['order_id'] ?? $data['transaction_id'] ?? null;

            if (!$orderId) {
                return [
                    'success' => false,
                    'message' => 'Order ID is required for verification.',
                ];
            }

            // Get transaction status from Midtrans
            $status = Transaction::status($orderId);

            if (!$status || !isset($status->status_code)) {
                return [
                    'success' => false,
                    'message' => 'Transaction not found or invalid.',
                ];
            }

            // Check transaction status
            $transactionStatus = $status->transaction_status ?? '';
            $statusCode = $status->status_code ?? '';

            // Transaction is successful if status_code is 200 and transaction_status is settlement or capture
            if ($statusCode === '200' && in_array($transactionStatus, ['settlement', 'capture'])) {
                // Convert amount back from smallest unit to actual amount
                $currency = strtoupper($status->currency ?? 'IDR');
                $grossAmount = isset($status->gross_amount) ? (float) $status->gross_amount : null;
                $amount = $grossAmount !== null 
                    ? ($currency === 'IDR' ? $grossAmount : $grossAmount / 100)
                    : null;

                return [
                    'success' => true,
                    'payment_id' => $status->transaction_id ?? $orderId,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $transactionStatus,
                    'payment_type' => $status->payment_type ?? null,
                    'transaction_time' => $status->transaction_time ?? null,
                ];
            }

            // Check if transaction is pending
            if (in_array($transactionStatus, ['pending', 'authorize', 'challenge'])) {
                return [
                    'success' => false,
                    'message' => 'Payment is pending. Status: ' . $transactionStatus,
                    'status' => $transactionStatus,
                ];
            }

            // Transaction failed or denied
            if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                return [
                    'success' => false,
                    'message' => 'Payment failed. Status: ' . $transactionStatus,
                    'status' => $transactionStatus,
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment verification failed. Status: ' . $transactionStatus,
                'status' => $transactionStatus,
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

            $webhookSecret = $this->getConfigValue('webhook_secret');

            // Midtrans uses notification class for webhook handling
            // The Notification class reads from $_POST, so we need to set it from payload
            $body = $payload['body'] ?? [];
            
            // Temporarily set $_POST for Midtrans Notification class
            $originalPost = $_POST;
            $_POST = $body;
            
            try {
                $notification = new Notification();
            } finally {
                // Restore original $_POST
                $_POST = $originalPost;
            }

            // Get notification data
            $transactionStatus = $notification->transaction_status ?? '';
            $orderId = $notification->order_id ?? null;
            $transactionId = $notification->transaction_id ?? null;
            $fraudStatus = $notification->fraud_status ?? '';

            // Extract metadata from custom fields (and any other available sources)
            $metadata = [];

            // transaction_id and user_id from custom_field1/custom_field2
            if (!empty($notification->custom_field1)) {
                $metadata['transaction_id'] = (string) $notification->custom_field1;
            }
            if (!empty($notification->custom_field2)) {
                $metadata['user_id'] = (string) $notification->custom_field2;
            }

            // Full metadata JSON from custom_field3 (primary)
            if (!empty($notification->custom_field3)) {
                $decoded = json_decode((string) $notification->custom_field3, true);
                if (is_array($decoded)) {
                    // Merge decoded metadata, but don't overwrite transaction_id/user_id if already set.
                    // Additionally, if any value itself looks like JSON, try to decode it back to array/object
                    // so callers receive metadata in the same structure they originally sent.
                    foreach ($decoded as $key => $value) {
                        if ($value === null || array_key_exists($key, $metadata)) {
                            continue;
                        }

                        // If the value is a JSON string (we encoded arrays/objects as JSON),
                        // attempt to decode back to its original structure.
                        if (is_string($value) && $value !== '') {
                            $firstChar = $value[0];
                            if ($firstChar === '{' || $firstChar === '[') {
                                $maybeDecoded = json_decode($value, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $value = $maybeDecoded;
                                }
                            }
                        }

                        $metadata[$key] = $value;
                    }
                } elseif (!isset($metadata['custom_field3'])) {
                    $metadata['custom_field3'] = (string) $notification->custom_field3;
                }
            }

            if (!$orderId) {
                return [
                    'success' => false,
                    'message' => 'Order ID is missing in webhook notification.',
                ];
            }

            // Verify fraud status (for credit card payments)
            if ($fraudStatus === 'challenge') {
                // Transaction is marked as challenge by FDS
                return [
                    'success' => false,
                    'message' => 'Transaction is challenged by fraud detection system.',
                    'order_id' => $orderId,
                    'transaction_id' => $transactionId,
                    'status' => $transactionStatus,
                    'fraud_status' => $fraudStatus,
                ];
            }

            // Handle different transaction statuses
            switch ($transactionStatus) {
                case 'capture':
                case 'settlement':
                    // Payment is successful
                    if ($fraudStatus === 'accept' || $fraudStatus === '') {
                        // Convert amount back from smallest unit to actual amount
                        $currency = strtoupper($notification->currency ?? 'IDR');
                        $grossAmount = isset($notification->gross_amount) ? (float) $notification->gross_amount : null;
                        $amount = $grossAmount !== null 
                            ? ($currency === 'IDR' ? $grossAmount : $grossAmount / 100)
                            : null;

                        return [
                            'success' => true,
                            'event_type' => $transactionStatus,
                            'payment_id' => $transactionId,
                            'order_id' => $orderId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'status' => $transactionStatus,
                            'payment_type' => $notification->payment_type ?? null,
                            'metadata' => $metadata,
                        ];
                    }
                    break;

                case 'pending':
                case 'authorize':
                    // Payment is pending
                    return [
                        'success' => false,
                        'message' => 'Payment is pending.',
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'status' => $transactionStatus,
                        'metadata' => $metadata,
                    ];

                case 'deny':
                case 'expire':
                case 'cancel':
                    // Payment failed
                    return [
                        'success' => false,
                        'message' => 'Payment failed. Status: ' . $transactionStatus,
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'status' => $transactionStatus,
                        'metadata' => $metadata,
                    ];

                default:
                    return [
                        'success' => false,
                        'message' => 'Unhandled transaction status: ' . $transactionStatus,
                        'order_id' => $orderId,
                        'transaction_id' => $transactionId,
                        'status' => $transactionStatus,
                        'metadata' => $metadata,
                    ];
            }

            return [
                'success' => false,
                'message' => 'Webhook processing failed. Status: ' . $transactionStatus,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }
}

