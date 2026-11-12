<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;

if (!function_exists('onlinePaymentMethod')) {
    /**
     * Initialize payment through a gateway.
     * For Razorpay, automatically redirects to the payment form.
     * For other gateways, returns the payment data array.
     *
     * @param string $paymentMethod
     * @param float $amount
     * @param string $currencyCode
     * @param string $callbackUrl
     * @param array $metadata
     * @return array|RedirectResponse
     */
    function onlinePaymentMethod(
        string $paymentMethod,
        float $amount,
        string $currencyCode,
        string $callbackUrl,
        array $metadata = []
    ) {
        $paymentService = app(\MaheshKerai\PaymentGateways\Services\PaymentService::class);

        $result = $paymentService->onlinePaymentMethod(
            $paymentMethod,
            $amount,
            $currencyCode,
            $callbackUrl,
            $metadata
        );

        // For Razorpay, automatically redirect to the payment form
        if (strtolower($paymentMethod) === 'razorpay' && $result['success']) {
            $params = [
                'amount' => $amount,
                'currency' => $currencyCode,
                'callback_url' => $callbackUrl,
            ];

            // Add transaction_id to params to prevent duplicate transaction creation
            if (isset($result['transaction_id'])) {
                $params['transaction_id'] = $result['transaction_id'];
            }
            if (isset($result['order_id'])) {
                $params['order_id'] = $result['order_id'];
            }

            // Add all metadata to params to preserve it through the flow
            foreach ($metadata as $key => $value) {
                if (!in_array($key, ['amount', 'currency', 'callback_url', 'transaction_id', 'order_id']) && $value !== null) {
                    $params[$key] = $value;
                }
            }

            // Add optional metadata to params (for backward compatibility and fallbacks)
            if (isset($metadata['merchant_name'])) {
                $params['merchant_name'] = $metadata['merchant_name'];
            }
            if (isset($metadata['description'])) {
                $params['description'] = $metadata['description'];
            }
            if (isset($metadata['product_name'])) {
                $params['product_name'] = $metadata['product_name'];
            }
            if (isset($metadata['customer_name'])) {
                $params['customer_name'] = $metadata['customer_name'];
            } elseif (auth()->check() && auth()->user()->name) {
                $params['customer_name'] = auth()->user()->name;
            }
            if (isset($metadata['customer_email'])) {
                $params['customer_email'] = $metadata['customer_email'];
            } elseif (auth()->check() && auth()->user()->email) {
                $params['customer_email'] = auth()->user()->email;
            }
            if (isset($metadata['customer_phone'])) {
                $params['customer_phone'] = $metadata['customer_phone'];
            } elseif (auth()->check() && isset(auth()->user()->phone)) {
                $params['customer_phone'] = auth()->user()->phone;
            }
            if (isset($metadata['merchant_logo'])) {
                $params['merchant_logo'] = $metadata['merchant_logo'];
            }
            if (isset($metadata['button_text'])) {
                $params['button_text'] = $metadata['button_text'];
            }
            if (isset($metadata['theme_color'])) {
                $params['theme_color'] = $metadata['theme_color'];
            }

            return redirect()->route('payment-gateways.razorpay.form', $params);
        }

        // For other gateways, return the result array
        return $result;
    }
}

if (!function_exists('verifyPayment')) {
    /**
     * Verify payment transaction.
     *
     * @param string $paymentMethod
     * @param array $paymentData
     * @return array
     */
    function verifyPayment(string $paymentMethod, array $paymentData): array
    {
        $paymentService = app(\MaheshKerai\PaymentGateways\Services\PaymentService::class);

        return $paymentService->verifyPayment($paymentMethod, $paymentData);
    }
}

if (!function_exists('getRazorpayFormUrl')) {
    /**
     * Get Razorpay payment form URL.
     *
     * @param float $amount
     * @param string $currency
     * @param string $callbackUrl
     * @param array $options
     * @return string
     */
    function getRazorpayFormUrl(
        float $amount,
        string $currency = 'INR',
        string $callbackUrl = '',
        array $options = []
    ): string {
        $params = array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'callback_url' => $callbackUrl ?: route('payment-gateways.callback'),
        ], $options);

        return route('payment-gateways.razorpay.form') . '?' . http_build_query($params);
    }
}

if (!function_exists('getPaymentCallbackUrl')) {
    /**
     * Get unified payment callback URL.
     *
     * @return string
     */
    function getPaymentCallbackUrl(): string
    {
        return route('payment-gateways.callback');
    }
}

if (!function_exists('getTransactionByOrderId')) {
    /**
     * Get payment transaction by order ID.
     *
     * @param string $orderId
     * @return \MaheshKerai\PaymentGateways\Models\PaymentTransaction|null
     */
    function getTransactionByOrderId(string $orderId)
    {
        return \MaheshKerai\PaymentGateways\Models\PaymentTransaction::where('order_id', $orderId)
            ->with(['paymentGateway', 'user'])
            ->first();
    }
}

