<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Services;

use MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PaymentService
{
    /**
     * Initialize payment through a gateway.
     *
     * @param string $paymentMethod
     * @param float $amount
     * @param string $currencyCode
     * @param string $callbackUrl
     * @param array $metadata
     * @return array
     */
    public function onlinePaymentMethod(
        string $paymentMethod,
        float $amount,
        string $currencyCode,
        string $callbackUrl,
        array $metadata = []
    ): array {
        try {
            // Get payment gateway from database
            $gateway = PaymentGateway::where('name', $paymentMethod)
                ->where('status', 1)
                ->first();

            if (!$gateway) {
                return [
                    'success' => false,
                    'message' => "Payment gateway '{$paymentMethod}' not found or inactive.",
                ];
            }

            // Resolve gateway instance
            $gatewayInstance = $this->resolveGateway($paymentMethod);

            if (!$gatewayInstance) {
                return [
                    'success' => false,
                    'message' => "Payment gateway '{$paymentMethod}' is not supported.",
                ];
            }

            // Set gateway configuration
            $config = [
                'secret_key' => $gateway->secret_key,
                'publishable_key' => $gateway->publishable_key,
                'webhook_secret' => $gateway->webhook_secret,
            ];

            // Add PayPal-specific config (mode) from config file
            if ($paymentMethod === 'paypal') {
                $paypalConfig = config('payment-gateways.gateways.paypal', []);
                if (isset($paypalConfig['mode'])) {
                    $config['mode'] = $paypalConfig['mode'];
                }
            }

            // Add Midtrans-specific config (mode) from config file
            if ($paymentMethod === 'midtrans') {
                $midtransConfig = config('payment-gateways.gateways.midtrans', []);
                if (isset($midtransConfig['mode'])) {
                    $config['mode'] = $midtransConfig['mode'];
                }
            }

            // Add PhonePe-specific config (mode, client_version, username, password) from config file
            if ($paymentMethod === 'phonepe') {
                $phonepeConfig = config('payment-gateways.gateways.phonepe', []);
                if (isset($phonepeConfig['mode'])) {
                    $config['mode'] = $phonepeConfig['mode'];
                }
                if (isset($phonepeConfig['client_version'])) {
                    $config['client_version'] = $phonepeConfig['client_version'];
                }
                if (isset($gateway->username) && isset($gateway->password)) {
                    $config['username'] = $gateway->username;
                    $config['password'] = $gateway->password;
                }
            }

            // Add JazzCash-specific config (mode, return_url, transaction_type from config file; password from database)
            if ($paymentMethod === 'jazzcash') {
                $jazzcashConfig = config('payment-gateways.gateways.jazzcash', []);
                foreach (['mode', 'return_url', 'transaction_type'] as $jazzcashKey) {
                    if (isset($jazzcashConfig[$jazzcashKey])) {
                        $config[$jazzcashKey] = $jazzcashConfig[$jazzcashKey];
                    }
                }
                if (isset($gateway->password)) {
                    $config['password'] = $gateway->password;
                }
            }

            $gatewayInstance->setConfig($config);

            // Add callback URL to metadata
            $metadata['callback_url'] = $callbackUrl;
            $userId = $metadata['user_id'] ?? auth()->id();

            // Wrap everything in a database transaction to prevent duplicates
            return DB::transaction(function () use (
                $paymentMethod,
                $amount,
                $currencyCode,
                $metadata,
                $userId,
                $gateway,
                $gatewayInstance
            ) {
                // Dedupe is only safe for gateways where we can hand the caller back a
                // usable checkout/order on retry. Stripe (Checkout Session retrieve) and
                // Razorpay (cached order id) qualify. For others (Paystack/Flutterwave/
                // PayPal/Midtrans/PhonePe), the provider may reject duplicate order ids
                // and we have no cached checkout_url to return — so let a new transaction
                // be created instead of returning a half-formed payload.
                $dedupeMethods = ['stripe', 'razorpay'];
                $existingTransaction = null;
                if (in_array($paymentMethod, $dedupeMethods, true)) {
                    $existingTransaction = PaymentTransaction::where('user_id', $userId)
                        ->where('payment_gateway_id', $gateway->id)
                        ->where('amount', $amount)
                        ->where('status', 'pending')
                        ->where('created_at', '>=', now()->subSeconds(30))
                        ->lockForUpdate() // Lock the rows to prevent concurrent access
                        ->orderBy('created_at', 'desc')
                        ->first();
                }

                if ($existingTransaction) {
                    // Return existing transaction instead of creating a new one
                    Log::info('Duplicate payment attempt prevented', [
                        'existing_transaction_id' => $existingTransaction->id,
                        'order_id' => $existingTransaction->order_id,
                        'payment_id' => $existingTransaction->payment_id,
                    ]);

                    // If transaction already has a payment_id (e.g., Stripe session_id), reuse it
                    if ($existingTransaction->payment_id) {
                        // For Stripe, we need to retrieve the session to get the checkout URL
                        if ($paymentMethod === 'stripe') {
                            try {
                                \Stripe\Stripe::setApiKey($gateway->secret_key);
                                $session = \Stripe\Checkout\Session::retrieve($existingTransaction->payment_id);
                                
                                return [
                                    'success' => true,
                                    'transaction_id' => $existingTransaction->id,
                                    'order_id' => $existingTransaction->order_id,
                                    'payment_data' => [
                                        'payment_id' => $session->id,
                                        'checkout_url' => $session->url,
                                        'session_id' => $session->id,
                                    ],
                                ];
                            } catch (\Exception $e) {
                                // If session retrieval fails, create a new one
                                Log::warning('Failed to retrieve existing Stripe session, creating new one', [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        } elseif ($paymentMethod === 'razorpay') {
                            // For Razorpay, return existing order data
                            return [
                                'success' => true,
                                'transaction_id' => $existingTransaction->id,
                                'order_id' => $existingTransaction->order_id,
                                'payment_data' => [
                                    'payment_id' => $existingTransaction->payment_id, // This is the Razorpay order ID
                                    'order_id' => $existingTransaction->payment_id, // Razorpay order ID
                                    'razorpay_order_id' => $existingTransaction->payment_id,
                                    'key' => $gateway->publishable_key,
                                    'amount' => $existingTransaction->amount,
                                    'currency' => 'INR', // Default, can be enhanced
                                ],
                            ];
                        } else {
                            // For other gateways, return existing payment data
                            return [
                                'success' => true,
                                'transaction_id' => $existingTransaction->id,
                                'order_id' => $existingTransaction->order_id,
                                'payment_data' => [
                                    'payment_id' => $existingTransaction->payment_id,
                                ],
                            ];
                        }
                    }

                    // Update callback URL to include transaction_id
                    $callbackUrl = $metadata['callback_url'] ?? '';
                    if ($callbackUrl) {
                        // Append transaction_id to callback URL if not already present
                        $separator = (parse_url($callbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?';
                        $metadata['callback_url'] = $callbackUrl . $separator . 'transaction_id=' . $existingTransaction->id;
                    }

                    // If no payment_id exists, initialize payment with existing order ID
                    $paymentData = $gatewayInstance->initializePayment(
                        $amount,
                        $currencyCode,
                        $existingTransaction->order_id,
                        array_merge($metadata, ['transaction_id' => $existingTransaction->id])
                    );

                    // Update transaction with payment ID if returned
                    if (isset($paymentData['payment_id'])) {
                        $existingTransaction->update([
                            'payment_id' => $paymentData['payment_id'],
                        ]);
                    }

                    return [
                        'success' => true,
                        'transaction_id' => $existingTransaction->id,
                        'order_id' => $existingTransaction->order_id,
                        'payment_data' => $paymentData,
                    ];
                }

                // Generate order ID
                $orderId = $this->generateOrderId();

                // Create transaction record
                $transaction = PaymentTransaction::create([
                    'user_id' => $userId,
                    'payment_gateway_id' => $gateway->id,
                    'amount' => $amount,
                    'order_id' => $orderId,
                    'payment_id' => null,
                    'payment_signature' => null,
                    'status' => 'pending',
                ]);

                // Update callback URL to include transaction_id
                $callbackUrl = $metadata['callback_url'] ?? '';
                if ($callbackUrl) {
                    // Append transaction_id to callback URL if not already present
                    $separator = (parse_url($callbackUrl, PHP_URL_QUERY) !== null) ? '&' : '?';
                    $metadata['callback_url'] = $callbackUrl . $separator . 'transaction_id=' . $transaction->id;
                }

                // Initialize payment
                $paymentData = $gatewayInstance->initializePayment(
                    $amount,
                    $currencyCode,
                    $orderId,
                    array_merge($metadata, ['transaction_id' => $transaction->id])
                );

                // Update transaction with payment ID if returned
                if (isset($paymentData['payment_id'])) {
                    $transaction->update([
                        'payment_id' => $paymentData['payment_id'],
                    ]);
                }

                return [
                    'success' => true,
                    'transaction_id' => $transaction->id,
                    'order_id' => $orderId,
                    'payment_data' => $paymentData,
                ];
            });
        } catch (\Exception $e) {
            Log::error('Payment initialization error: ' . $e->getMessage(), [
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'currency' => $currencyCode,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'Payment initialization failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Verify payment transaction.
     *
     * @param string $paymentMethod
     * @param array $paymentData
     * @return array
     */
    public function verifyPayment(string $paymentMethod, array $paymentData): array
    {
        try {
            $gateway = PaymentGateway::where('name', $paymentMethod)
                ->where('status', 1)
                ->first();

            if (!$gateway) {
                return [
                    'success' => false,
                    'message' => "Payment gateway '{$paymentMethod}' not found or inactive.",
                ];
            }

            $gatewayInstance = $this->resolveGateway($paymentMethod);

            if (!$gatewayInstance) {
                return [
                    'success' => false,
                    'message' => "Payment gateway '{$paymentMethod}' is not supported.",
                ];
            }

            $config = [
                'secret_key' => $gateway->secret_key,
                'publishable_key' => $gateway->publishable_key,
                'webhook_secret' => $gateway->webhook_secret,
            ];

            // Add PayPal-specific config (mode) from config file
            if ($paymentMethod === 'paypal') {
                $paypalConfig = config('payment-gateways.gateways.paypal', []);
                if (isset($paypalConfig['mode'])) {
                    $config['mode'] = $paypalConfig['mode'];
                }
            }

            // Add Midtrans-specific config (mode) from config file
            if ($paymentMethod === 'midtrans') {
                $midtransConfig = config('payment-gateways.gateways.midtrans', []);
                if (isset($midtransConfig['mode'])) {
                    $config['mode'] = $midtransConfig['mode'];
                }
            }

            // Add PhonePe-specific config (mode, client_version, username, password) from config file
            if ($paymentMethod === 'phonepe') {
                $phonepeConfig = config('payment-gateways.gateways.phonepe', []);
                if (isset($phonepeConfig['mode'])) {
                    $config['mode'] = $phonepeConfig['mode'];
                }
                if (isset($phonepeConfig['client_version'])) {
                    $config['client_version'] = $phonepeConfig['client_version'];
                }
                if (isset($gateway->username) && isset($gateway->password)) {
                    $config['username'] = $gateway->username;
                    $config['password'] = $gateway->password;
                }
            }

            // Add JazzCash-specific config (mode, return_url, transaction_type from config file; password from database)
            if ($paymentMethod === 'jazzcash') {
                $jazzcashConfig = config('payment-gateways.gateways.jazzcash', []);
                foreach (['mode', 'return_url', 'transaction_type'] as $jazzcashKey) {
                    if (isset($jazzcashConfig[$jazzcashKey])) {
                        $config[$jazzcashKey] = $jazzcashConfig[$jazzcashKey];
                    }
                }
                if (isset($gateway->password)) {
                    $config['password'] = $gateway->password;
                }
            }

            $gatewayInstance->setConfig($config);

            $verification = $gatewayInstance->verifyPayment($paymentData);

            // Update transaction status and payment_id
            if (isset($paymentData['order_id']) || isset($paymentData['razorpay_order_id']) || isset($paymentData['token']) || isset($paymentData['transaction_id'])) {
                $orderId = $paymentData['order_id'] ?? $paymentData['razorpay_order_id'] ?? $paymentData['token'] ?? $paymentData['transaction_id'] ?? null;
                
                if ($orderId) {
                    // Use a more specific query to prevent matching multiple transactions
                    // First try to find by order_id, then by payment_id (for Razorpay/PayPal/Midtrans where payment_id might be set to order_id initially)
                    $transaction = PaymentTransaction::where('order_id', $orderId)->first();
                    
                    // If not found by order_id, check payment_id for Razorpay, PayPal, Midtrans, or PhonePe
                    if (!$transaction && in_array($paymentMethod, ['razorpay', 'paypal', 'midtrans', 'phonepe'])) {
                        $transaction = PaymentTransaction::where('payment_id', $orderId)
                            ->where('status', 'pending') // Only match pending transactions to prevent duplicates
                            ->first();
                    }
                        
                    if ($transaction) {
                        // Only mutate when transaction is still pending. Once a final
                        // status (success/failed/refunded) is set, do not let a later
                        // verify call downgrade or overwrite it.
                        if ($transaction->status === 'pending') {
                            $updateData = [
                                'status' => $verification['success'] ? 'success' : 'failed',
                            ];

                            // For Razorpay, update with actual payment_id (pay_xxx)
                            if ($paymentMethod === 'razorpay' && $verification['success'] && isset($paymentData['razorpay_payment_id'])) {
                                $updateData['payment_id'] = $paymentData['razorpay_payment_id'];
                                $updateData['payment_signature'] = $paymentData['razorpay_signature'] ?? $paymentData['signature'] ?? null;
                            } else {
                                // For other gateways, update signature if provided
                                if (isset($paymentData['signature']) || isset($paymentData['payment_signature'])) {
                                    $updateData['payment_signature'] = $paymentData['signature'] ?? $paymentData['payment_signature'] ?? null;
                                }
                                
                                // Update payment_id if returned in verification
                                if (isset($verification['payment_id'])) {
                                    $updateData['payment_id'] = $verification['payment_id'];
                                }
                            }

                            $transaction->update($updateData);
                        }
                    }
                }
            }

            return $verification;
        } catch (\Exception $e) {
            Log::error('Payment verification error: ' . $e->getMessage(), [
                'payment_method' => $paymentMethod,
                'payment_data' => $paymentData,
                'exception' => $e,
            ]);

            return [
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve gateway instance.
     *
     * @param string $paymentMethod
     * @return PaymentGatewayInterface|null
     */
    private function resolveGateway(string $paymentMethod): ?PaymentGatewayInterface
    {
        $gatewayMap = [
            'stripe' => \MaheshKerai\PaymentGateways\Gateways\StripeGateway::class,
            'razorpay' => \MaheshKerai\PaymentGateways\Gateways\RazorpayGateway::class,
            'flutterwave' => \MaheshKerai\PaymentGateways\Gateways\FlutterwaveGateway::class,
            'paystack' => \MaheshKerai\PaymentGateways\Gateways\PaystackGateway::class,
            'paypal' => \MaheshKerai\PaymentGateways\Gateways\PayPalGateway::class,
            'midtrans' => \MaheshKerai\PaymentGateways\Gateways\MidtransGateway::class,
            'phonepe' => \MaheshKerai\PaymentGateways\Gateways\PhonePeGateway::class,
            'jazzcash' => \MaheshKerai\PaymentGateways\Gateways\JazzCashGateway::class,
        ];

        $gatewayClass = $gatewayMap[strtolower($paymentMethod)] ?? null;

        if (!$gatewayClass || !class_exists($gatewayClass)) {
            return null;
        }

        return new $gatewayClass();
    }

    /**
     * Generate unique order ID.
     *
     * @return string
     */
    private function generateOrderId(): string
    {
        do {
            $orderId = 'ORD-' . strtoupper(Str::random(12)) . '-' . time();
        } while (PaymentTransaction::where('order_id', $orderId)->exists());

        return $orderId;
    }
}

