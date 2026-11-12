<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class FlutterwaveGateway extends BaseGateway
{
    /**
     * Flutterwave API base URL.
     *
     * @var string
     */
    private string $baseUrl = 'https://api.flutterwave.com/v3';

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
            $secretKey = $this->getConfigValue('secret_key');
            
            if (!$secretKey) {
                throw new \RuntimeException('Flutterwave secret key is not configured.');
            }

            $client = new Client([
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $callbackUrl = $metadata['callback_url'] ?? '';
            $txRef = $orderId;

            // Use full URL to avoid Guzzle base_uri path resolution issues
            $url = $this->baseUrl . '/payments';
            
            $response = $client->post($url, [
                'json' => [
                    'tx_ref' => $txRef,
                    'amount' => $amount,
                    'currency' => strtoupper($currency),
                    'payment_options' => 'card,account,ussd,banktransfer,mobilemoney',
                    'redirect_url' => $callbackUrl,
                    'customer' => [
                        'email' => $metadata['customer_email'] ?? $metadata['email'] ?? 'customer@example.com',
                        'name' => $metadata['customer_name'] ?? $metadata['name'] ?? 'Customer',
                    ],
                    'customizations' => [
                        'title' => $metadata['product_name'] ?? 'Payment',
                        'description' => $metadata['description'] ?? 'Payment for order ' . $orderId,
                    ],
                    'meta' => $metadata,
                ],
            ]);

            $responseBody = $response->getBody()->getContents();
            $data = json_decode($responseBody, true);

            if (!isset($data['status'])) {
                throw new \RuntimeException('Flutterwave payment initialization failed: Invalid response format. Response: ' . $responseBody);
            }

            if ($data['status'] === 'success' && isset($data['data']['link'])) {
                return [
                    'payment_id' => $data['data']['tx_ref'] ?? $data['data']['id'] ?? null,
                    'checkout_url' => $data['data']['link'],
                    'tx_ref' => $data['data']['tx_ref'] ?? $orderId,
                ];
            }

            $errorMessage = $data['message'] ?? ($data['data']['message'] ?? 'Unknown error');
            throw new \RuntimeException('Flutterwave payment initialization failed: ' . $errorMessage);
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorData = json_decode($responseBody, true);
                if (isset($errorData['message'])) {
                    $errorMessage = $errorData['message'];
                }
            }
            throw new \RuntimeException('Flutterwave payment initialization failed: ' . $errorMessage);
        } catch (\Exception $e) {
            throw new \RuntimeException('Flutterwave payment initialization failed: ' . $e->getMessage());
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
            $txRef = $data['tx_ref'] ?? $data['order_id'] ?? null;
            $transactionId = $data['transaction_id'] ?? $data['payment_id'] ?? null;

            if (!$txRef && !$transactionId) {
                return [
                    'success' => false,
                    'message' => 'Transaction reference or transaction ID is required.',
                ];
            }

            $secretKey = $this->getConfigValue('secret_key');
            
            if (!$secretKey) {
                return [
                    'success' => false,
                    'message' => 'Flutterwave secret key is not configured.',
                ];
            }

            $client = new Client([
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Use full URL to avoid Guzzle base_uri path resolution issues
            if ($transactionId) {
                $url = $this->baseUrl . "/transactions/{$transactionId}/verify";
            } else {
                $url = $this->baseUrl . "/transactions/verify_by_reference?tx_ref={$txRef}";
            }
            $response = $client->get($url);

            $result = json_decode($response->getBody()->getContents(), true);

            if ($result['status'] === 'success' && $result['data']['status'] === 'successful') {
                return [
                    'success' => true,
                    'payment_id' => $result['data']['id'],
                    'tx_ref' => $result['data']['tx_ref'],
                    'amount' => $result['data']['amount'],
                    'currency' => $result['data']['currency'],
                    'status' => $result['data']['status'],
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

            // Verify webhook signature.
            // Symfony stores header keys lowercased and values as arrays.
            $headers = $payload['headers'] ?? [];
            $sigRaw = $headers['verif-hash'] ?? null;
            $signature = is_array($sigRaw) ? ($sigRaw[0] ?? null) : $sigRaw;

            if (!$signature || !hash_equals((string) $webhookSecret, (string) $signature)) {
                return [
                    'success' => false,
                    'message' => 'Invalid webhook signature.',
                ];
            }

            // Body comes either as decoded array or raw JSON; extract event/data from it.
            $body = $payload['body'] ?? [];
            $bodyArr = is_string($body) ? (json_decode($body, true) ?: []) : (is_array($body) ? $body : []);

            $event = $bodyArr['event'] ?? ($payload['event'] ?? null);
            $data = $bodyArr['data'] ?? ($payload['data'] ?? []);

            if ($event === 'charge.completed' && ($data['status'] ?? '') === 'successful') {
                return [
                    'success' => true,
                    'event_type' => $event,
                    'payment_id' => $data['id'] ?? null,
                    'tx_ref' => $data['tx_ref'] ?? null,
                    'amount' => $data['amount'] ?? null,
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

