<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment gateway that will be used
    | when no specific gateway is specified. Supported values:
    | 'stripe', 'razorpay', 'flutterwave', 'paystack', 'paypal', 'midtrans', 'phonepe', 'jazzcash'
    |
    */
    'default_gateway' => env('PAYMENT_DEFAULT_GATEWAY', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the settings for each payment gateway.
    | These settings can be overridden by database configurations.
    |
    */
    'gateways' => [
        'stripe' => [
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],
        'razorpay' => [
            'secret_key' => env('RAZORPAY_SECRET_KEY'),
            'publishable_key' => env('RAZORPAY_KEY_ID'),
            'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
        ],
        'flutterwave' => [
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'publishable_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'webhook_secret' => env('FLUTTERWAVE_WEBHOOK_SECRET'),
        ],
        'paystack' => [
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'publishable_key' => env('PAYSTACK_PUBLIC_KEY'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        ],
        'paypal' => [
            'secret_key' => env('PAYPAL_SECRET_KEY'),
            'publishable_key' => env('PAYPAL_CLIENT_ID'),
            'webhook_secret' => env('PAYPAL_WEBHOOK_SECRET'),
            'mode' => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' or 'production'
        ],
        'midtrans' => [
            'secret_key' => env('MIDTRANS_SERVER_KEY'),
            'publishable_key' => env('MIDTRANS_CLIENT_KEY'),
            'webhook_secret' => env('MIDTRANS_WEBHOOK_SECRET'),
            'mode' => env('MIDTRANS_MODE', 'sandbox'), // 'sandbox' or 'production'
        ],
        'phonepe' => [
            'secret_key' => env('PHONEPE_CLIENT_SECRET'),
            'publishable_key' => env('PHONEPE_CLIENT_ID'),
            'webhook_secret' => env('PHONEPE_WEBHOOK_SECRET'),
            'client_version' => env('PHONEPE_CLIENT_VERSION', '1'),
            'mode' => env('PHONEPE_MODE', 'uat'), // 'uat' or 'production' or 'prod'
            'username' => env('PHONEPE_USERNAME'),
            'password' => env('PHONEPE_PASSWORD'),
        ],
        'jazzcash' => [
            'secret_key' => env('JAZZCASH_INTEGRITY_SALT'),
            'publishable_key' => env('JAZZCASH_MERCHANT_ID'),
            'password' => env('JAZZCASH_PASSWORD'),
            'return_url' => env('JAZZCASH_RETURN_URL'), // optional pp_ReturnURL override, supports {order_id}
            'transaction_type' => env('JAZZCASH_TXN_TYPE', 'MWALLET'), // pp_TxnType, e.g. 'MWALLET', 'MIGS' or 'OTC'
            'mode' => env('JAZZCASH_MODE', 'sandbox'), // 'sandbox' or 'production'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Route Prefix
    |--------------------------------------------------------------------------
    |
    | This option controls the prefix for webhook routes.
    |
    */
    'webhook_prefix' => 'payment-gateways/webhooks',

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Management Routes
    |--------------------------------------------------------------------------
    |
    | These URLs are used for managing payment gateways through the admin panel.
    | Note: Only read and update operations are available. Gateways are created via seeder.
    | The index route loads the form page directly with gateway selection in sidebar.
    |
    */
    'management' => [
        'index_url' => '/payment-gateways/gateways',
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Receipt
    |--------------------------------------------------------------------------
    |
    | After a verified Razorpay payment a receipt (amount, date, IDs, product
    | details) is shown before the customer is sent on to your callback URL.
    | Set 'enabled' to false to redirect immediately, as before. 'redirect_after'
    | is the number of seconds before the receipt continues on its own
    | (0 waits for the customer to press Continue).
    |
    */
    'receipt' => [
        'enabled' => env('PAYMENT_RECEIPT_ENABLED', true),
        'redirect_after' => (int) env('PAYMENT_RECEIPT_REDIRECT_SECONDS', 5),
    ],
];

