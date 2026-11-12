<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

final class RazorpayGateway extends BaseGateway
{
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
            $api = new Api(
                $this->getConfigValue('publishable_key'),
                $this->getConfigValue('secret_key')
            );

            $razorpayOrder = $api->order->create([
                'receipt' => $orderId,
                'amount' => (int)($amount * 100), // Convert to paise
                'currency' => strtoupper($currency),
                'notes' => $metadata,
            ]);

            return [
                'payment_id' => $razorpayOrder['id'], // Store Razorpay order ID initially (will be updated to pay_xxx on success)
                'order_id' => $razorpayOrder['id'],
                'razorpay_order_id' => $razorpayOrder['id'],
                'amount' => $razorpayOrder['amount'] / 100,
                'amount_in_paise' => $razorpayOrder['amount'],
                'currency' => $razorpayOrder['currency'],
                'key' => $this->getConfigValue('publishable_key'),
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('Razorpay payment initialization failed: ' . $e->getMessage());
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
            $api = new Api(
                $this->getConfigValue('publishable_key'),
                $this->getConfigValue('secret_key')
            );

            $razorpayOrderId = $data['razorpay_order_id'] ?? $data['order_id'] ?? null;
            $razorpayPaymentId = $data['razorpay_payment_id'] ?? $data['payment_id'] ?? null;
            $razorpaySignature = $data['razorpay_signature'] ?? $data['signature'] ?? null;

            if (!$razorpayOrderId || !$razorpayPaymentId || !$razorpaySignature) {
                return [
                    'success' => false,
                    'message' => 'Required payment verification data is missing.',
                ];
            }

            $attributes = [
                'razorpay_order_id' => $razorpayOrderId,
                'razorpay_payment_id' => $razorpayPaymentId,
                'razorpay_signature' => $razorpaySignature,
            ];

            $api->utility->verifyPaymentSignature($attributes);

            // Fetch payment details
            $payment = $api->payment->fetch($razorpayPaymentId);

            return [
                'success' => true,
                'payment_id' => $razorpayPaymentId,
                'order_id' => $razorpayOrderId,
                'amount' => $payment['amount'] / 100,
                'currency' => $payment['currency'],
                'status' => $payment['status'],
            ];
        } catch (SignatureVerificationError $e) {
            return [
                'success' => false,
                'message' => 'Payment signature verification failed.',
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
            $webhookSecret = $this->getConfigValue('webhook_secret');

            if (!$webhookSecret) {
                return [
                    'success' => false,
                    'message' => 'Webhook secret not configured.',
                ];
            }

            $api = new Api(
                $this->getConfigValue('publishable_key'),
                $this->getConfigValue('secret_key')
            );

            // Decode body for event/entity extraction.
            $body = $payload['body'] ?? [];
            if (is_string($body)) {
                $bodyArr = json_decode($body, true) ?: [];
            } else {
                $bodyArr = is_array($body) ? $body : [];
            }

            $event = $bodyArr['event'] ?? ($payload['event'] ?? null);
            $entity = $bodyArr['payload']['payment']['entity'] ?? null;

            if (!$event || !$entity) {
                return [
                    'success' => false,
                    'message' => 'Invalid webhook payload.',
                ];
            }

            // Verify webhook signature against the raw HTTP body (Razorpay signs raw body).
            // Symfony stores headers with lowercase keys and array values.
            $headers = $payload['headers'] ?? [];
            $sigRaw = $headers['x-razorpay-signature'] ?? null;
            $webhookSignature = is_array($sigRaw) ? ($sigRaw[0] ?? null) : $sigRaw;
            $rawBody = $payload['raw_body'] ?? (is_string($payload['body'] ?? null) ? $payload['body'] : json_encode($bodyArr));

            if ($webhookSignature) {
                $api->utility->verifyWebhookSignature(
                    $rawBody,
                    $webhookSignature,
                    $webhookSecret
                );
            }

            return [
                'success' => true,
                'event_type' => $event,
                'payment_id' => $entity['id'] ?? null,
                'order_id' => $entity['order_id'] ?? null,
                'amount' => isset($entity['amount']) ? $entity['amount'] / 100 : null,
                'status' => $entity['status'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }
}

