<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class PaystackGateway extends BaseGateway
{
    /**
     * Paystack API base URL.
     *
     * @var string
     */
    private string $baseUrl = 'https://api.paystack.co';

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
            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getConfigValue('secret_key'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $callbackUrl = $metadata['callback_url'] ?? '';

            // Paystack default currency is NGN. If other currency is provided, validate it
            $currencyCode = strtoupper($currency);
            
            // For Paystack, if currency is not NGN, check if merchant supports it
            // Most Paystack merchants only support NGN by default
            if ($currencyCode !== 'NGN') {
                // Log a warning that non-NGN currency is being used
                \Illuminate\Support\Facades\Log::warning('Paystack: Non-NGN currency requested', [
                    'currency' => $currencyCode,
                    'note' => 'Paystack merchants typically only support NGN. USD and other currencies require multi-currency activation in Paystack dashboard.',
                ]);
            }

            $response = $client->post('/transaction/initialize', [
                'json' => [
                    'email' => $metadata['customer_email'] ?? $metadata['email'] ?? 'customer@example.com',
                    'amount' => (int)($amount * 100), // Convert to kobo (or smallest unit for the currency)
                    'currency' => $currencyCode,
                    'reference' => $orderId,
                    'callback_url' => $callbackUrl,
                    'metadata' => array_merge($metadata, ['order_id' => $orderId]),
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);

            if ($data['status'] === true) {
                return [
                    'payment_id' => $data['data']['reference'],
                    'authorization_url' => $data['data']['authorization_url'],
                    'access_code' => $data['data']['access_code'],
                    'reference' => $data['data']['reference'],
                ];
            }

            // Provide a more helpful error message
            $errorMessage = $data['message'] ?? 'Unknown error';
            
            // Check for currency-related errors
            if (stripos($errorMessage, 'currency') !== false || stripos($errorMessage, 'not supported') !== false) {
                $errorMessage = "Currency '{$currencyCode}' is not supported by your Paystack merchant account. " .
                    "Paystack merchants typically only support NGN (Nigerian Naira) by default. " .
                    "To use other currencies like USD, you need to enable multi-currency support in your Paystack dashboard. " .
                    "Original error: " . $errorMessage;
            }

            throw new \RuntimeException('Paystack payment initialization failed: ' . $errorMessage);
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            
            // Parse the response if available to get better error details
            if ($e->hasResponse()) {
                try {
                    $responseBody = $e->getResponse()->getBody()->getContents();
                    $errorData = json_decode($responseBody, true);
                    
                    if (isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                        
                        // Check for currency-related errors in the response
                        if (stripos($errorMessage, 'currency') !== false || stripos($errorMessage, 'not supported') !== false) {
                            $errorMessage = "Currency '{$currencyCode}' is not supported by your Paystack merchant account. " .
                                "Paystack merchants typically only support NGN (Nigerian Naira) by default. " .
                                "To use other currencies like USD, you need to enable multi-currency support in your Paystack dashboard. " .
                                "Original error: " . $errorMessage;
                        }
                    }
                } catch (\Exception $parseException) {
                    // If parsing fails, use the original error message
                }
            }
            
            throw new \RuntimeException('Paystack payment initialization failed: ' . $errorMessage);
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
            $reference = $data['reference'] ?? $data['order_id'] ?? null;

            if (!$reference) {
                return [
                    'success' => false,
                    'message' => 'Payment reference is required for verification.',
                ];
            }

            $client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getConfigValue('secret_key'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->get("/transaction/verify/{$reference}");

            $result = json_decode($response->getBody()->getContents(), true);

            if ($result['status'] === true && $result['data']['status'] === 'success') {
                return [
                    'success' => true,
                    'payment_id' => $result['data']['reference'],
                    'reference' => $result['data']['reference'],
                    'amount' => $result['data']['amount'] / 100,
                    'currency' => $result['data']['currency'],
                    'status' => $result['data']['status'],
                    'gateway_response' => $result['data']['gateway_response'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment verification failed or payment not successful.',
            ];
        } catch (GuzzleException $e) {
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
            $webhookSecret = $this->getConfigValue('webhook_secret');

            if (!$webhookSecret) {
                return [
                    'success' => false,
                    'message' => 'Webhook secret not configured.',
                ];
            }

            // Verify webhook signature against the raw HTTP body (Paystack signs raw body).
            // Symfony stores headers with lowercase keys and array values.
            $headers = $payload['headers'] ?? [];
            $sigRaw = $headers['x-paystack-signature'] ?? null;
            $signature = is_array($sigRaw) ? ($sigRaw[0] ?? null) : $sigRaw;

            $body = $payload['body'] ?? [];
            $rawBody = $payload['raw_body'] ?? (is_string($body) ? $body : json_encode($body));

            if ($signature) {
                $expectedSignature = hash_hmac('sha512', $rawBody, $webhookSecret);
                if (!hash_equals($expectedSignature, $signature)) {
                    return [
                        'success' => false,
                        'message' => 'Invalid webhook signature.',
                    ];
                }
            }

            $bodyArr = is_string($body) ? (json_decode($body, true) ?: []) : (is_array($body) ? $body : []);
            $event = $bodyArr['event'] ?? ($payload['event'] ?? null);
            $data = $bodyArr['data'] ?? ($payload['data'] ?? []);

            if ($event === 'charge.success' && ($data['status'] ?? '') === 'success') {
                return [
                    'success' => true,
                    'event_type' => $event,
                    'payment_id' => $data['reference'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'amount' => isset($data['amount']) ? $data['amount'] / 100 : null,
                    'currency' => $data['currency'] ?? null,
                    'status' => $data['status'] ?? null,
                ];
            }

            return [
                'success' => false,
                'message' => 'Unhandled webhook event or payment not successful.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }
}

