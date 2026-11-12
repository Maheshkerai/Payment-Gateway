<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use MaheshKerai\PaymentGateways\Support\PaymentSummary;
use MaheshKerai\PaymentGateways\Tests\Concerns\RunsRazorpayStub;
use MaheshKerai\PaymentGateways\Tests\TestCase;

final class RazorpayReceiptTest extends TestCase
{
    use RunsRazorpayStub;

    private const SECRET = 'rzp_secret';

    private const CALLBACK_URL = 'https://app.test/payment-success?metadata=abc&transaction_id=%d';

    private PaymentTransaction $transaction;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::startRazorpayStub();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopRazorpayStub();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->pointRazorpayAtTheStub();
        Carbon::setTestNow('2026-11-05 10:30:00');

        $gateway = PaymentGateway::create([
            'name' => 'razorpay', 'publishable_key' => 'rzp_test_key', 'secret_key' => self::SECRET, 'status' => 1,
        ]);

        $this->transaction = PaymentTransaction::create([
            'user_id' => 1,
            'payment_gateway_id' => $gateway->id,
            'amount' => 100.50,
            'order_id' => 'ORD-ABCDEFGHIJKL-1700000000',
            'payment_id' => 'order_test123',
            'status' => 'pending',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->restoreRazorpayBaseUrl();

        parent::tearDown();
    }

    /**
     * What Razorpay Checkout posts back to the package once the customer has paid.
     */
    private function checkoutResult(array $overrides = []): array
    {
        $paymentId = $overrides['razorpay_payment_id'] ?? 'pay_Test456';

        return array_merge([
            'razorpay_order_id' => 'order_test123',
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', 'order_test123|' . $paymentId, self::SECRET),
            'callback_url' => sprintf(self::CALLBACK_URL, $this->transaction->id),
        ], $overrides);
    }

    private function cacheSummary(): void
    {
        Cache::put(PaymentSummary::cacheKey($this->transaction->id), PaymentSummary::fromMetadata([
            'merchant_name' => 'eSchool saas',
            'merchant_logo' => 'https://cdn.example.com/logo.png',
            'product_name' => 'Premium Plan - Annual',
            'description' => 'Unlimited students and online exams',
            'billing_cycle' => 'Yearly',
            'customer_name' => 'John Doe',
            'customer_email' => 'john@example.com',
        ], 'Fallback App'), now()->addHour());
    }

    public function test_a_verified_payment_shows_a_receipt(): void
    {
        $this->cacheSummary();

        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult());

        $response->assertOk();
        $response->assertSee('Payment successful');
        $response->assertSee('₹100.50');
        $response->assertSee('Amount paid');
        $response->assertSee('Successful');
        $response->assertSee('05 Nov 2026, 10:30 AM UTC');
        $response->assertSee('ORD-ABCDEFGHIJKL-1700000000');
        $response->assertSee('#' . $this->transaction->id);
        $response->assertSee('pay_Test456');
        $response->assertSee('Thank you, John Doe.');
        $response->assertSee('eSchool saas');
        $response->assertSee('<img src="https://cdn.example.com/logo.png"', false);
        $response->assertSee('Premium Plan - Annual');
        $response->assertSee('Unlimited students and online exams');
        $response->assertSee('Billing Cycle');
        $response->assertSee('Yearly');
        $response->assertSee('john@example.com');
        $response->assertSee('Payment processed securely by');
        $response->assertSee('Razor<b>pay</b>', false);
    }

    public function test_the_payment_is_recorded_as_successful(): void
    {
        $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult())->assertOk();

        $transaction = $this->transaction->fresh();
        $this->assertSame('success', $transaction->status);
        $this->assertSame('pay_Test456', $transaction->payment_id);
    }

    public function test_the_receipt_continues_to_the_callback_url_with_the_payment_result(): void
    {
        config(['payment-gateways.receipt.redirect_after' => 5]);

        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult());

        $html = $response->getContent();
        preg_match('#<a class="btn" id="continue-button" href="([^"]+)"#', $html, $match);
        $href = html_entity_decode($match[1] ?? '');
        parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://app.test/payment-success?', $href);
        $this->assertSame('success', $query['status']);
        $this->assertSame('pay_Test456', $query['payment_id']);
        $this->assertSame('order_test123', $query['order_id']);
        $this->assertSame('abc', $query['metadata']);
        $this->assertEquals($this->transaction->id, $query['transaction_id']);

        $response->assertSee('Taking you back in');
        $response->assertSee('var remaining = 5;', false);
    }

    public function test_the_receipt_can_wait_for_the_customer_instead_of_redirecting(): void
    {
        config(['payment-gateways.receipt.redirect_after' => 0]);

        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult());

        $response->assertOk();
        $response->assertSee('id="continue-button"', false);
        $response->assertDontSee('Taking you back in');
        $response->assertDontSee('window.location.assign', false);
        $response->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_a_receipt_without_cached_details_still_renders(): void
    {
        config(['app.name' => 'Fallback App']);

        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult());

        $response->assertOk();
        $response->assertSee('Payment successful');
        $response->assertSee('Fallback App');
        $response->assertSee('₹100.50');
        $response->assertDontSee('Order summary');
    }

    public function test_cached_details_are_escaped_on_the_receipt(): void
    {
        Cache::put(PaymentSummary::cacheKey($this->transaction->id), PaymentSummary::fromMetadata([
            'merchant_name' => '<script>alert(1)</script>', 'product_name' => '<img src=x onerror=alert(1)>',
        ], 'x'), now()->addHour());

        $html = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult())->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_the_receipt_can_be_turned_off_to_redirect_straight_away_as_before(): void
    {
        config(['payment-gateways.receipt.enabled' => false]);

        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult());

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://app.test/payment-success?', $location);
        $this->assertSame('success', $query['status']);
        $this->assertSame('pay_Test456', $query['payment_id']);
        $this->assertSame('success', $this->transaction->fresh()->status);
    }

    public function test_a_payment_that_fails_verification_redirects_with_a_failed_status_and_no_receipt(): void
    {
        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult([
            'razorpay_signature' => str_repeat('0', 64),
        ]));

        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('failed', $query['status']);
        $this->assertSame('failed', $this->transaction->fresh()->status);
    }

    public function test_a_payment_the_gateway_does_not_know_does_not_show_a_receipt(): void
    {
        // Valid signature, but the stub (like Razorpay) has no such payment.
        $paymentId = 'unknown_payment';
        $response = $this->post('/payment-gateways/razorpay/callback', $this->checkoutResult([
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => hash_hmac('sha256', 'order_test123|' . $paymentId, self::SECRET),
        ]));

        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('failed', $query['status']);
        $this->assertSame('failed', $this->transaction->fresh()->status);
    }

    public function test_the_callback_still_validates_its_input(): void
    {
        $this->post('/payment-gateways/razorpay/callback', [])->assertSessionHasErrors([
            'razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature',
        ]);
    }
}
