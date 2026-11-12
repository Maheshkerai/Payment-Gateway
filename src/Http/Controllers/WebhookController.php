<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use MaheshKerai\PaymentGateways\Services\PaymentService;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use MaheshKerai\PaymentGateways\Gateways\StripeGateway;
use Illuminate\Support\Facades\Log;

final class WebhookController extends Controller
{
    /**
     * Handle webhook callback.
     *
     * @param Request $request
     * @param string $gateway
     * @return JsonResponse
     */
    public function handle(Request $request, string $gateway): JsonResponse
    {
        try {
            $paymentGateway = PaymentGateway::where('name', $gateway)
                ->where('status', 1)
                ->first();

            if (!$paymentGateway) {
                return response()->json([
                    'success' => false,
                    'message' => "Payment gateway '{$gateway}' not found or inactive.",
                ], 404);
            }

            $paymentService = app(PaymentService::class);
            $gatewayInstance = $this->resolveGateway($gateway);

            if (!$gatewayInstance) {
                return response()->json([
                    'success' => false,
                    'message' => "Payment gateway '{$gateway}' is not supported.",
                ], 400);
            }

            $config = [
                'secret_key' => $paymentGateway->secret_key,
                'publishable_key' => $paymentGateway->publishable_key,
                'webhook_secret' => $paymentGateway->webhook_secret,
            ];

            // Add PayPal-specific config (mode) from config file
            if ($gateway === 'paypal') {
                $paypalConfig = config('payment-gateways.gateways.paypal', []);
                if (isset($paypalConfig['mode'])) {
                    $config['mode'] = $paypalConfig['mode'];
                }
            }

            // Add Midtrans-specific config (mode) from config file
            if ($gateway === 'midtrans') {
                $midtransConfig = config('payment-gateways.gateways.midtrans', []);
                if (isset($midtransConfig['mode'])) {
                    $config['mode'] = $midtransConfig['mode'];
                }
            }

            // Add PhonePe-specific config (mode, client_version, username, password) from config file
            if ($gateway === 'phonepe') {
                $phonepeConfig = config('payment-gateways.gateways.phonepe', []);
                if (isset($phonepeConfig['mode'])) {
                    $config['mode'] = $phonepeConfig['mode'];
                }
                if (isset($phonepeConfig['client_version'])) {
                    $config['client_version'] = $phonepeConfig['client_version'];
                }
                if (isset($paymentGateway->username) && isset($paymentGateway->password)) {
                    $config['username'] = $paymentGateway->username;
                    $config['password'] = $paymentGateway->password;
                }
            }

            // Add JazzCash-specific config (mode, return_url, transaction_type from config file; password from database)
            if ($gateway === 'jazzcash') {
                $jazzcashConfig = config('payment-gateways.gateways.jazzcash', []);
                foreach (['mode', 'return_url', 'transaction_type'] as $jazzcashKey) {
                    if (isset($jazzcashConfig[$jazzcashKey])) {
                        $config[$jazzcashKey] = $jazzcashConfig[$jazzcashKey];
                    }
                }
                if (isset($paymentGateway->password)) {
                    $config['password'] = $paymentGateway->password;
                }
            }

            $gatewayInstance->setConfig($config);

            // Prepare payload
            // Always provide raw body so gateways that compute HMAC signatures
            // (Stripe/Razorpay/Paystack/PhonePe) can verify against the exact bytes received.
            $rawBody = $request->getContent();
            $body = $request->all();
            if ($gateway === 'phonepe') {
                // PhonePe webhook expects raw JSON body for verification
                $body = $rawBody !== '' ? $rawBody : json_encode($request->all());
            }

            $payload = [
                'body' => $body,
                'raw_body' => $rawBody,
                'headers' => $request->headers->all(),
                'event' => $request->input('event') ?? $request->input('type'),
            ];

            // Handle webhook
            $result = $gatewayInstance->handleWebhook($payload);
            Log::info("result --->" . json_encode($result));

            // Update transaction status based on webhook result (for all gateways)
            // Extract order_id from result (check both top level and metadata)
            $orderId = $result['order_id'] ?? $result['metadata']['order_id'] ?? null;
            
            if ($orderId) {
                $transaction = PaymentTransaction::where('order_id', $orderId)->first();
                Log::info("transaction found: " . ($transaction ? "yes (ID: {$transaction->id})" : "no"));
                if ($transaction) {
                    // Allow updates if transaction is pending, or if we're updating to refunded status (from success)
                    $isRefundUpdate = ($gateway === 'stripe' && isset($result['status']) && $result['status'] === 'refunded');
                    $canUpdate = ($transaction->status === PaymentTransaction::STATUS_PENDING) ||
                                 ($isRefundUpdate && $transaction->status === PaymentTransaction::STATUS_SUCCESS);

                    if ($canUpdate) {
                        $updateData = [];

                        // Map Stripe statuses to ENUM values
                        if ($gateway === 'stripe' && isset($result['status'])) {
                            $stripeStatus = (string) $result['status'];

                            // Payment succeeded → success
                            if ($stripeStatus === 'succeeded' || $stripeStatus === 'paid') {
                                $updateData['status'] = PaymentTransaction::STATUS_SUCCESS;
                            }
                            // Payment refunded → refunded
                            elseif ($stripeStatus === 'refunded') {
                                $updateData['status'] = PaymentTransaction::STATUS_REFUNDED;
                            }
                            // Payment failed / cancelled / declined → failed
                            elseif (in_array($stripeStatus, [
                                'canceled',
                                'payment_failed',
                                'requires_payment_method', // Declined
                                'failed',
                                'unpaid', // Checkout session unpaid
                            ], true)) {
                                $updateData['status'] = PaymentTransaction::STATUS_FAILED;
                            }
                            // Payment still in process → pending
                            elseif (in_array($stripeStatus, [
                                'processing',
                                'requires_action',
                                'requires_confirmation',
                                'requires_capture',
                            ], true)) {
                                $updateData['status'] = PaymentTransaction::STATUS_PENDING;
                            }
                            // Default fallback: if success flag is true, mark as success; otherwise failed
                            else {
                                $updateData['status'] = ($result['success'] === true)
                                    ? PaymentTransaction::STATUS_SUCCESS
                                    : PaymentTransaction::STATUS_FAILED;
                            }
                        } else {
                            // Non-Stripe gateways: map based on success flag and any
                            // explicit pending status reported by the gateway. Pending
                            // webhooks (Midtrans 'pending'/'authorize', PhonePe 'PENDING'/
                            // 'INITIATED', etc.) must not flip the transaction to failed —
                            // a follow-up webhook will resolve the final state.
                            if ($result['success'] === true) {
                                $updateData['status'] = PaymentTransaction::STATUS_SUCCESS;
                            } else {
                                $explicitStatus = strtolower((string) ($result['status'] ?? ''));
                                $pendingStates = ['pending', 'authorize', 'challenge', 'initiated', 'unpaid'];
                                if (!in_array($explicitStatus, $pendingStates, true)) {
                                    $updateData['status'] = PaymentTransaction::STATUS_FAILED;
                                }
                            }
                        }

                        if (isset($result['signature'])) {
                            $updateData['payment_signature'] = $result['signature'];
                        }

                        if (isset($result['payment_id'])) {
                            $updateData['payment_id'] = $result['payment_id'];
                        }
                        Log::info("updateData --->" . json_encode($updateData));
                        if (!empty($updateData)) {
                            $transaction->update($updateData);
                        }
                    }
                }
            }

            // Fire webhook event if needed
            // event(new PaymentWebhookReceived($gateway, $result));

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully.',
            ], 200);
        } catch (\Exception $e) {
            Log::error('Webhook handling error: ' . $e->getMessage(), [
                'gateway' => $gateway,
                'request' => $request->all(),
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve gateway instance.
     *
     * @param string $gateway
     * @return \MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface|null
     */
    private function resolveGateway(string $gateway): ?\MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface
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

        $gatewayClass = $gatewayMap[strtolower($gateway)] ?? null;

        if (!$gatewayClass || !class_exists($gatewayClass)) {
            return null;
        }

        return new $gatewayClass();
    }
}

