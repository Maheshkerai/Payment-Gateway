<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;

final class StripeGateway extends BaseGateway
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
            Stripe::setApiKey($this->getConfigValue('secret_key'));

            $callbackUrl = $metadata['callback_url'] ?? '';
            
            // Properly append gateway parameters to callback URL
            // Use & if URL already has parameters, ? otherwise
            $separator = (parse_url($callbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?';
            $successUrl = $callbackUrl . $separator . 'session_id={CHECKOUT_SESSION_ID}&status=success';
            $cancelUrl = $callbackUrl . ((parse_url($callbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?') . 'status=cancelled';

            // Prepare metadata for Stripe
            // Stripe metadata requires all values to be strings
            // Exclude internal fields like callback_url
            $stripeMetadata = [
                'order_id' => $orderId,
            ];

            // Add transaction_id if available
            if (isset($metadata['transaction_id'])) {
                $stripeMetadata['transaction_id'] = (string) $metadata['transaction_id'];
            }

            // Add all other metadata fields (excluding internal fields)
            // Stripe metadata limitations:
            // - Keys: up to 40 characters
            // - Values: up to 500 characters
            $excludedFields = ['callback_url'];
            foreach ($metadata as $key => $value) {
                if (!in_array($key, $excludedFields) && $value !== null) {
                    // Truncate key if it exceeds Stripe's 40 character limit
                    $metadataKey = mb_substr((string) $key, 0, 40);
                    
                    // Convert value to string (Stripe requirement)
                    // Handle arrays and objects by JSON encoding
                    $metadataValue = '';
                    if (is_array($value) || is_object($value)) {
                        $metadataValue = json_encode($value);
                    } else {
                        $metadataValue = (string) $value;
                    }
                    
                    // Truncate value if it exceeds Stripe's 500 character limit
                    if (mb_strlen($metadataValue) > 500) {
                        $metadataValue = mb_substr($metadataValue, 0, 500);
                    }
                    
                    $stripeMetadata[$metadataKey] = $metadataValue;
                }
            }

            // Create Checkout Session with metadata on both Session and PaymentIntent
            // This ensures metadata is available in both checkout.session.completed 
            // and payment_intent.succeeded webhook events
            $sessionParams = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($currency),
                        'product_data' => [
                            'name' => $metadata['product_name'] ?? 'Payment',
                        ],
                        'unit_amount' => (int)($amount * 100), // Convert to cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $stripeMetadata,
                // Set metadata on PaymentIntent as well
                // This ensures metadata is available on the PaymentIntent object
                'payment_intent_data' => [
                    'metadata' => $stripeMetadata,
                ],
            ];

            $session = Session::create($sessionParams);

            return [
                'payment_id' => $session->id,
                'checkout_url' => $session->url,
                'session_id' => $session->id,
            ];
        } catch (ApiErrorException $e) {
            throw new \RuntimeException('Stripe payment initialization failed: ' . $e->getMessage());
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
            Stripe::setApiKey($this->getConfigValue('secret_key'));

            $sessionId = $data['session_id'] ?? $data['payment_id'] ?? null;

            if (!$sessionId) {
                return [
                    'success' => false,
                    'message' => 'Session ID is required for verification.',
                ];
            }

            $session = Session::retrieve($sessionId);

            if ($session->payment_status === 'paid') {
                return [
                    'success' => true,
                    'payment_id' => $session->payment_intent,
                    'amount' => $session->amount_total / 100,
                    'currency' => strtoupper($session->currency),
                    'metadata' => $session->metadata->toArray(),
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment not completed.',
            ];
        } catch (ApiErrorException $e) {
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

            // WebhookController passes a wrapper payload with 'body', 'raw_body', and 'headers'.
            $rawBody = $payload['raw_body'] ?? null;
            $headers = $payload['headers'] ?? [];

            // Symfony lowercases header names; values are arrays.
            $sigHeaderRaw = $headers['stripe-signature'] ?? null;
            $sigHeader = is_array($sigHeaderRaw) ? ($sigHeaderRaw[0] ?? null) : $sigHeaderRaw;

            // Prefer signed verification when raw body and signature header are available.
            if (is_string($rawBody) && $rawBody !== '' && $sigHeader) {
                try {
                    \Stripe\Stripe::setApiKey($this->getConfigValue('secret_key'));
                    $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, $webhookSecret);
                } catch (\Stripe\Exception\SignatureVerificationException $e) {
                    return [
                        'success' => false,
                        'message' => 'Invalid Stripe webhook signature.',
                    ];
                }
            } else {
                // Fallback: parse without signature verification (e.g. when invoked outside HTTP).
                $body = $payload['body'] ?? $payload;
                if (is_string($body)) {
                    $eventData = json_decode($body, true) ?: [];
                } elseif (is_array($body)) {
                    $eventData = $body;
                } else {
                    $eventData = [];
                }

                if (empty($eventData)) {
                    return [
                        'success' => false,
                        'message' => 'Invalid Stripe webhook payload.',
                    ];
                }

                $event = \Stripe\Event::constructFrom($eventData);
            }
            $eventType = $event->type ?? '';

            // Handle checkout.session.completed (for redirect flow)
            if ($eventType === 'checkout.session.completed') {
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;
                $metadata = $session->metadata ? $session->metadata->toArray() : [];

                // Map payment_status to status field for consistency
                $paymentStatus = $session->payment_status ?? null;
                $status = null;
                
                if ($paymentStatus === 'paid') {
                    $status = 'succeeded';
                } elseif ($paymentStatus === 'unpaid') {
                    $status = 'pending';
                }

                return [
                    'success' => ($paymentStatus === 'paid'),
                    'event_type' => $eventType,
                    'payment_id' => $session->payment_intent,
                    'order_id' => $metadata['order_id'] ?? null,
                    'amount' => $session->amount_total / 100,
                    'currency' => strtoupper($session->currency),
                    'status' => $status ?? $paymentStatus,
                    'metadata' => $metadata,
                ];
            }

            // Handle charge.refunded event
            if ($eventType === 'charge.refunded') {
                /** @var \Stripe\Charge $charge */
                $charge = $event->data->object;
                $metadata = $charge->metadata ? $charge->metadata->toArray() : [];

                return [
                    'success' => true,
                    'event_type' => $eventType,
                    'payment_id' => $charge->payment_intent ?? $charge->id,
                    'order_id' => $metadata['order_id'] ?? null,
                    'amount' => $charge->amount_refunded / 100,
                    'currency' => strtoupper($charge->currency),
                    'status' => 'refunded',
                    'metadata' => $metadata,
                ];
            }

            // Handle payment_intent.refunded event
            if ($eventType === 'payment_intent.refunded') {
                /** @var \Stripe\PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;
                $metadata = $paymentIntent->metadata ? $paymentIntent->metadata->toArray() : [];

                return [
                    'success' => true,
                    'event_type' => $eventType,
                    'payment_id' => $paymentIntent->id,
                    'order_id' => $metadata['order_id'] ?? null,
                    'amount' => isset($paymentIntent->amount) ? $paymentIntent->amount / 100 : null,
                    'currency' => isset($paymentIntent->currency) ? strtoupper($paymentIntent->currency) : null,
                    'status' => 'refunded',
                    'metadata' => $metadata,
                ];
            }

            // Handle all payment_intent.* events in a generic way
            if (str_starts_with((string) $eventType, 'payment_intent.')) {
                /** @var \Stripe\PaymentIntent $paymentIntent */
                $paymentIntent = $event->data->object;
                $metadata = $paymentIntent->metadata ? $paymentIntent->metadata->toArray() : [];

                $status = $paymentIntent->status ?? null;
                $amount = isset($paymentIntent->amount) ? $paymentIntent->amount / 100 : null;
                $currency = isset($paymentIntent->currency) ? strtoupper($paymentIntent->currency) : null;

                // Treat succeeded as successful, other payment_intent.* as non-fatal but handled
                $isSuccess = ($status === 'succeeded');

                // Extract order_id from metadata for easier access
                $orderId = $metadata['order_id'] ?? null;

                return [
                    'success' => $isSuccess,
                    'event_type' => $eventType,
                    'payment_id' => $paymentIntent->id,
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => $status,
                    'metadata' => $metadata,
                ];
            }

            // For other Stripe events we don't explicitly use, mark as handled but not successful
            return [
                'success' => false,
                'message' => 'Unhandled event type: ' . $eventType,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }
}

