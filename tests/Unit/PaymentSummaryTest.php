<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Unit;

use MaheshKerai\PaymentGateways\Support\PaymentSummary;
use MaheshKerai\PaymentGateways\Tests\TestCase;

final class PaymentSummaryTest extends TestCase
{
    public function test_it_falls_back_to_the_given_name_and_defaults_when_there_is_no_metadata(): void
    {
        $summary = PaymentSummary::fromMetadata([], 'My System');

        $this->assertSame('My System', $summary['merchant_name']);
        $this->assertSame('', $summary['merchant_logo']);
        $this->assertSame('', $summary['product_name']);
        $this->assertSame('', $summary['description']);
        $this->assertSame([], $summary['details']);
        $this->assertSame('#ff7529', $summary['theme_color']);
        $this->assertSame('255, 117, 41', $summary['accent_rgb']);
    }

    public function test_it_reads_the_display_fields_from_the_metadata(): void
    {
        $summary = PaymentSummary::fromMetadata([
            'merchant_name' => 'eSchool saas',
            'merchant_logo' => 'https://cdn.example.com/logo.png',
            'product_name' => 'Premium Plan',
            'product_image' => '/images/plan.png',
            'description' => 'Annual subscription',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '9999999999',
        ], 'Fallback');

        $this->assertSame('eSchool saas', $summary['merchant_name']);
        $this->assertSame('https://cdn.example.com/logo.png', $summary['merchant_logo']);
        $this->assertSame('Premium Plan', $summary['product_name']);
        $this->assertSame('/images/plan.png', $summary['product_image']);
        $this->assertSame('Annual subscription', $summary['description']);
        $this->assertSame('John Doe', $summary['customer_name']);
        $this->assertSame('john@example.com', $summary['customer_email']);
        $this->assertSame('9999999999', $summary['customer_phone']);
    }

    public function test_text_is_reduced_to_a_short_single_line(): void
    {
        $summary = PaymentSummary::fromMetadata([
            'product_name' => "Plan\nwith\tbreaks\x00",
            'description' => str_repeat('a', 500),
            'merchant_name' => ['not', 'a', 'string'],
        ], 'Fallback');

        $this->assertSame('Plan with breaks', $summary['product_name']);
        $this->assertSame(300, mb_strlen($summary['description']));
        $this->assertStringEndsWith('…', $summary['description']);
        $this->assertSame('Fallback', $summary['merchant_name']);
    }

    public function test_image_urls_are_limited_to_http_and_relative_paths(): void
    {
        $cases = [
            'https' => ['https://cdn.example.com/a.png', 'https://cdn.example.com/a.png'],
            'http' => ['http://localhost:8000/storage/logo.svg', 'http://localhost:8000/storage/logo.svg'],
            'relative path' => ['/assets/logo.svg', '/assets/logo.svg'],
            'javascript scheme' => ['javascript:alert(1)', ''],
            'data uri' => ['data:image/svg+xml;base64,PHN2Zz4=', ''],
            'protocol relative' => ['//evil.example.com/x.png', ''],
            'no scheme' => ['logo.png', ''],
            'contains a quote' => ['https://example.com/a.png" onerror="alert(1)', ''],
            'contains a space' => ['https://example.com/my logo.png', ''],
            'contains an angle bracket' => ['https://example.com/<b>.png', ''],
            'empty' => ['', ''],
        ];

        foreach ($cases as $name => [$url, $expected]) {
            $this->assertSame($expected, PaymentSummary::fromMetadata(['merchant_logo' => $url])['merchant_logo'], $name);
        }
    }

    public function test_theme_colour_must_be_hex(): void
    {
        $cases = [
            'six digits' => ['#0EA5E9', '#0ea5e9'],
            'three digits' => ['#fff', '#fff'],
            'named colour' => ['red', '#ff7529'],
            'too short' => ['#12', '#ff7529'],
            'css injection' => ['#fff;} body{display:none', '#ff7529'],
            'url()' => ['url(https://evil.example.com)', '#ff7529'],
        ];

        foreach ($cases as $name => [$input, $expected]) {
            $this->assertSame($expected, PaymentSummary::fromMetadata(['theme_color' => $input])['theme_color'], $name);
        }
    }

    public function test_button_text_colour_stays_readable_on_light_and_dark_accents(): void
    {
        $this->assertSame('#ffffff', PaymentSummary::fromMetadata(['theme_color' => '#0b3d91'])['accent_text']);
        $this->assertSame('#111827', PaymentSummary::fromMetadata(['theme_color' => '#ffeb3b'])['accent_text']);
        $this->assertSame('0, 0, 255', PaymentSummary::fromMetadata(['theme_color' => '#00f'])['accent_rgb']);
    }

    public function test_other_metadata_becomes_labelled_detail_rows(): void
    {
        $summary = PaymentSummary::fromMetadata([
            'billing_cycle' => 'Yearly',
            'valid_until' => '31 Dec 2027',
            'shipping_method' => 'Courier',
            'seats' => 25,
        ]);

        $this->assertSame([
            ['label' => 'Billing Cycle', 'value' => 'Yearly'],
            ['label' => 'Valid Until', 'value' => '31 Dec 2027'],
            ['label' => 'Shipping Method', 'value' => 'Courier'],
            ['label' => 'Seats', 'value' => '25'],
        ], $summary['details']);
    }

    public function test_internal_and_already_displayed_keys_are_not_listed_as_details(): void
    {
        $summary = PaymentSummary::fromMetadata([
            'amount' => 100, 'currency' => 'INR', 'callback_url' => 'https://app.test', 'transaction_id' => 1,
            'order_id' => 'ORD-1', 'user_id' => 9, 'merchant_name' => 'x', 'product_name' => 'x', 'description' => 'x',
            'customer_name' => 'x', 'customer_email' => 'x', 'button_text' => 'Pay', 'theme_color' => '#fff',
            'razorpay_signature' => 'abc', '_token' => 'abc',
        ]);

        $this->assertSame([], $summary['details']);
    }

    public function test_anything_that_looks_like_a_secret_is_never_listed(): void
    {
        $summary = PaymentSummary::fromMetadata([
            'api_key' => 'k', 'apikey' => 'k', 'client_secret' => 's', 'password' => 'p', 'auth_token' => 't',
            'card_number' => '4111', 'otp' => '123456', 'signature' => 'x', 'payment_hash' => 'x', 'account' => '123',
            'plan' => 'Gold',
        ]);

        $this->assertSame([['label' => 'Plan', 'value' => 'Gold']], $summary['details']);
    }

    public function test_non_scalar_empty_and_boolean_values_are_skipped_and_the_list_is_capped(): void
    {
        $metadata = ['nested' => ['a' => 1], 'empty' => '', 'flag' => true, 'nothing' => null];
        for ($i = 1; $i <= 12; $i++) {
            $metadata["field_{$i}"] = "value {$i}";
        }

        $details = PaymentSummary::fromMetadata($metadata)['details'];

        $this->assertCount(8, $details);
        $this->assertSame('Field 1', $details[0]['label']);
        $this->assertSame('Field 8', $details[7]['label']);
    }

    public function test_amounts_are_formatted_with_a_currency_symbol(): void
    {
        $this->assertSame('₹1,500.00', PaymentSummary::formatAmount(1500, 'INR'));
        $this->assertSame('$99.50', PaymentSummary::formatAmount('99.5', 'usd'));
        $this->assertSame('XYZ 10.00', PaymentSummary::formatAmount(10, 'XYZ'));
    }

    public function test_the_cache_key_is_per_transaction(): void
    {
        $this->assertSame('payment-gateways:razorpay:summary:42', PaymentSummary::cacheKey(42));
        $this->assertNotSame(PaymentSummary::cacheKey(1), PaymentSummary::cacheKey(2));
    }
}
