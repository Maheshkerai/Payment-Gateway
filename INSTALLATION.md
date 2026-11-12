# Installation Guide

## For Local Development (Current Setup)

The package is already set up in your project. Follow these steps:

### 1. Run Composer Autoload

```bash
composer dump-autoload
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=payment-gateways-config
```

Or manually copy the config file:
```bash
cp packages/mahesh-kerai/payment-gateways/config/payment-gateways.php config/payment-gateways.php
```

### 3. Run Migrations

```bash
php artisan migrate
```

### 4. Seed Payment Gateways (Optional)

```bash
php artisan db:seed --class="MaheshKerai\PaymentGateways\Database\Seeders\PaymentGatewaysSeeder"
```

### 5. Configure Environment Variables

Add to your `.env` file:

```env
PAYMENT_DEFAULT_GATEWAY=stripe

STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

RAZORPAY_KEY_ID=rzp_test_...
RAZORPAY_SECRET_KEY=your_secret_key
RAZORPAY_WEBHOOK_SECRET=your_webhook_secret

FLUTTERWAVE_PUBLIC_KEY=FLWPUBK_TEST_...
FLUTTERWAVE_SECRET_KEY=FLWSECK_TEST_...
FLUTTERWAVE_WEBHOOK_SECRET=your_webhook_secret

PAYSTACK_PUBLIC_KEY=pk_test_...
PAYSTACK_SECRET_KEY=sk_test_...
PAYSTACK_WEBHOOK_SECRET=your_webhook_secret

JAZZCASH_MERCHANT_ID=your_merchant_id
JAZZCASH_PASSWORD=your_merchant_password
JAZZCASH_INTEGRITY_SALT=your_integrity_salt
JAZZCASH_MODE=sandbox # 'sandbox' or 'production'
JAZZCASH_TXN_TYPE=MWALLET # optional: MWALLET, MIGS or OTC
JAZZCASH_RETURN_URL= # optional: override pp_ReturnURL, supports {order_id}

PAYMENT_RECEIPT_ENABLED=true # show a receipt after a verified Razorpay payment before redirecting
PAYMENT_RECEIPT_REDIRECT_SECONDS=5 # 0 = wait for the customer to press Continue
```

### 6. Update Database Configuration

After seeding, update the payment gateway records in the database with your actual API keys and activate them:

```php
use MaheshKerai\PaymentGateways\Models\PaymentGateway;

$gateway = PaymentGateway::where('name', 'stripe')->first();
$gateway->update([
    'secret_key' => 'your_actual_secret_key',
    'publishable_key' => 'your_actual_publishable_key',
    'webhook_secret' => 'your_actual_webhook_secret',
    'status' => 1, // Activate
]);
```

## For Production Distribution

### 1. Prepare Package for Composer

If you want to distribute this package via Composer:

1. Create a Git repository for the package
2. Push to GitHub/GitLab
3. Register on Packagist (https://packagist.org)

### 2. Install in Other Projects

Once published, install in any Laravel project:

```bash
composer require mahesh-kerai/payment-gateways
```

### 3. Service Provider Auto-Discovery

The package uses Laravel's auto-discovery feature, so the service provider will be automatically registered.

If auto-discovery doesn't work, manually register in `config/app.php`:

```php
'providers' => [
    // ...
    MaheshKerai\PaymentGateways\PaymentGatewaysServiceProvider::class,
],
```

## Verification

Test that the package is working:

```php
// In tinker or a controller
use MaheshKerai\PaymentGateways\Services\PaymentService;

$service = app(PaymentService::class);
$result = $service->onlinePaymentMethod(
    'stripe',
    100.00,
    'USD',
    'https://yourdomain.com/callback',
    ['user_id' => 1]
);

dd($result);
```

## Troubleshooting

### Service Provider Not Found

If you get "Class not found" errors:

1. Run `composer dump-autoload`
2. Check that the namespace is correct in `composer.json`
3. Verify the service provider is registered in `config/app.php`

### Migrations Not Running

If migrations don't run:

1. Check that `loadMigrationsFrom` is called in the service provider
2. Verify migration files exist in `database/migrations/`
3. Run `php artisan migrate:status` to check migration status

### Webhook Routes Not Working

1. Verify routes are loaded: `php artisan route:list | grep payment-gateways`
2. Check CSRF middleware exceptions in `app/Http/Middleware/VerifyCsrfToken.php`
3. Ensure webhook URLs are correctly configured in payment gateway dashboards

