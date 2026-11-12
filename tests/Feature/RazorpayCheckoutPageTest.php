<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use MaheshKerai\PaymentGateways\Support\PaymentSummary;
use MaheshKerai\PaymentGateways\Tests\TestCase;

final class RazorpayCheckoutPageTest extends TestCase
{
    private PaymentTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 10:30:00');
        config(['app.name' => 'Fallback App']);

        $gateway = PaymentGateway::create([
            'name' => 'razorpay', 'publishable_key' => 'rzp_test_key', 'secret_key' => 'rzp_secret', 'status' => 1,
        ]);

        // An already initialised payment (Razorpay order created), so the page needs no call to Razorpay.
        $this->transaction = PaymentTransaction::create([
            'user_id' => 1,
            'payment_gateway_id' => $gateway->id,
            'amount' => 1500,
            'order_id' => 'ORD-ABCDEFGHIJKL-1700000000',
            'payment_id' => 'order_test123',
            'status' => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function page(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->get('/payment-gateways/razorpay/form?' . http_build_query(array_merge([
            'amount' => 1500,
            'currency' => 'INR',
            'callback_url' => 'https://app.test/payment-success?metadata=abc',
            'transaction_id' => $this->transaction->id,
        ], $query)));
    }

    public function test_it_shows_the_system_name_and_logo(): void
    {
        $response = $this->page([
            'merchant_name' => 'eSchool saas',
            'merchant_logo' => 'https://cdn.example.com/logo.png',
        ]);

        $response->assertOk();
        $response->assertSee('eSchool saas');
        $response->assertSee('<img src="https://cdn.example.com/logo.png" alt="eSchool saas logo"', false);
    }

    public function test_it_uses_the_app_name_and_an_initial_when_no_name_or_logo_is_given(): void
    {
        $response = $this->page();

        $response->assertOk();
        $response->assertSee('Fallback App');
        $response->assertDontSee('<img src=', false);
        $response->assertSee('<span aria-hidden="true">F</span>', false);
    }

    public function test_it_shows_product_details_from_the_metadata(): void
    {
        $response = $this->page([
            'product_name' => 'Premium Plan - Annual',
            'description' => 'Unlimited students and online exams',
            'billing_cycle' => 'Yearly',
            'valid_until' => '31 Dec 2027',
        ]);

        $response->assertSee('Order summary');
        $response->assertSee('Premium Plan - Annual');
        $response->assertSee('Unlimited students and online exams');
        $response->assertSee('Billing Cycle');
        $response->assertSee('Yearly');
        $response->assertSee('Valid Until');
        $response->assertSee('31 Dec 2027');
    }

    public function test_it_shows_a_description_on_its_own_and_hides_the_summary_when_there_is_nothing_to_show(): void
    {
        $this->page(['description' => 'Payment for subscription plan'])
            ->assertSee('Order summary')
            ->assertSee('Payment for subscription plan');

        $this->page()->assertDontSee('Order summary');
    }

    public function test_it_shows_the_payment_information(): void
    {
        $response = $this->page(['customer_name' => 'John Doe', 'customer_email' => 'john@example.com']);

        $response->assertSee('₹1,500.00');
        $response->assertSee('ORD-ABCDEFGHIJKL-1700000000');
        $response->assertSee('Transaction ID');
        $response->assertSee('#' . $this->transaction->id);
        $response->assertSee('05 Nov 2026, 10:30 AM UTC');
        $response->assertSee('Awaiting payment');
        $response->assertSee('John Doe');
        $response->assertSee('john@example.com');
        $response->assertSee('Pay ₹1,500.00');
    }

    public function test_it_is_branded_as_a_razorpay_payment(): void
    {
        $response = $this->page();

        $response->assertSee('Secured by');
        $response->assertSee('Razor<b>pay</b>', false);
        $response->assertSee('UPI');
        $response->assertSee('https://checkout.razorpay.com/v1/checkout.js', false);
    }

    public function test_it_hands_the_razorpay_checkout_its_options_and_posts_the_result_to_the_callback(): void
    {
        $response = $this->page([
            'merchant_name' => 'eSchool saas',
            'product_name' => 'Premium Plan',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
            'customer_phone' => '9999999999',
            'theme_color' => '#0ea5e9',
        ]);

        $html = $response->getContent();
        preg_match('#<script type="application/json" id="rzp-options">(.*?)</script>#s', $html, $match);
        $options = json_decode($match[1] ?? '', true);

        $this->assertSame('rzp_test_key', $options['key']);
        $this->assertSame(150000, $options['amount']);
        $this->assertSame('INR', $options['currency']);
        $this->assertSame('order_test123', $options['order_id']);
        $this->assertSame('eSchool saas', $options['name']);
        $this->assertSame('Premium Plan', $options['description']);
        $this->assertSame('John Doe', $options['prefill']['name']);
        $this->assertSame('john@example.com', $options['prefill']['email']);
        $this->assertSame('9999999999', $options['prefill']['contact']);
        $this->assertSame('#0ea5e9', $options['theme']['color']);

        $response->assertSee('action="' . route('payment-gateways.razorpay.callback') . '"', false);
        $response->assertSee('name="callback_url" value="https://app.test/payment-success?metadata=abc"', false);
        foreach (['razorpay_payment_id', 'razorpay_order_id', 'razorpay_signature', '_token'] as $field) {
            $response->assertSee('name="' . $field . '"', false);
        }
    }

    public function test_it_uses_a_custom_button_text_and_theme_colour(): void
    {
        $response = $this->page(['button_text' => 'Complete purchase', 'theme_color' => '#0ea5e9']);

        $response->assertSee('<span id="pay-button-label">Complete purchase</span>', false);
        $response->assertDontSee('<span id="pay-button-label">Pay', false);
        $response->assertSee('--accent: #0ea5e9;', false);
    }

    public function test_an_invalid_theme_colour_falls_back_to_the_default(): void
    {
        $this->page(['theme_color' => 'red;} body{display:none'])
            ->assertSee('--accent: #ff7529;', false)
            ->assertDontSee('display:none', false);
    }

    public function test_metadata_is_escaped(): void
    {
        $response = $this->page([
            'merchant_name' => '<script>alert("brand")</script>',
            'product_name' => '"><img src=x onerror=alert(1)>',
            'description' => '<b>bold</b>',
            'plan' => '<i>x</i>',
        ]);

        $html = $response->getContent();

        $this->assertStringNotContainsString('<script>alert("brand")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html);
        $response->assertSee('&lt;b&gt;bold&lt;/b&gt;', false);
    }

    public function test_an_unsafe_logo_url_is_not_rendered(): void
    {
        $response = $this->page(['merchant_logo' => 'javascript:alert(1)', 'product_image' => 'data:text/html;base64,AAAA']);

        $response->assertOk();
        $response->assertDontSee('javascript:alert', false);
        $response->assertDontSee('data:text/html', false);
        $response->assertDontSee('<img', false);
    }

    public function test_internal_and_secret_looking_metadata_is_not_displayed(): void
    {
        $response = $this->page(['user_id' => 77, 'api_key' => 'SECRET-KEY-VALUE', 'client_secret' => 'SECRET-VALUE', 'plan' => 'Gold']);

        $response->assertSee('Gold');
        $response->assertDontSee('SECRET-KEY-VALUE');
        $response->assertDontSee('SECRET-VALUE');
        $response->assertDontSee('User Id');
    }

    public function test_the_summary_is_kept_for_the_receipt(): void
    {
        $this->page(['merchant_name' => 'eSchool saas', 'product_name' => 'Premium Plan', 'billing_cycle' => 'Yearly'])->assertOk();

        $summary = Cache::get(PaymentSummary::cacheKey($this->transaction->id));

        $this->assertSame('eSchool saas', $summary['merchant_name']);
        $this->assertSame('Premium Plan', $summary['product_name']);
        $this->assertSame([['label' => 'Billing Cycle', 'value' => 'Yearly']], $summary['details']);
    }

    public function test_it_still_validates_its_input(): void
    {
        $this->get('/payment-gateways/razorpay/form?currency=INR')->assertSessionHasErrors(['amount', 'callback_url']);
    }
}
