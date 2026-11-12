<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Database\Seeders;

use Illuminate\Database\Seeder;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;

final class PaymentGatewaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $gateways = [
            [
                'name' => 'stripe',
                'webhook_url' => 'payment-gateways/webhooks/stripe',
            ],
            [
                'name' => 'razorpay',
                'webhook_url' => 'payment-gateways/webhooks/razorpay',
            ],
            [
                'name' => 'flutterwave',
                'webhook_url' => 'payment-gateways/webhooks/flutterwave',
            ],
            [
                'name' => 'paystack',
                'webhook_url' => 'payment-gateways/webhooks/paystack',
            ],
            [
                'name' => 'paypal',
                'webhook_url' => 'payment-gateways/webhooks/paypal',
            ],
            [
                'name' => 'midtrans',
                'webhook_url' => 'payment-gateways/webhooks/midtrans',
            ],
            [
                'name' => 'phonepe',
                'webhook_url' => 'payment-gateways/webhooks/phonepe',
            ],
            [
                'name' => 'jazzcash',
                'webhook_url' => 'payment-gateways/webhooks/jazzcash',
            ],
        ];

        foreach ($gateways as $gateway) {
            PaymentGateway::updateOrCreate(
                ['name' => $gateway['name']],
                $gateway
            );
        }
    }
}

