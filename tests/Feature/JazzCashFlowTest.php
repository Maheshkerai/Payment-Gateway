<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Feature;

use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use MaheshKerai\PaymentGateways\Gateways\JazzCashGateway;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use MaheshKerai\PaymentGateways\Services\PaymentService;
use MaheshKerai\PaymentGateways\Tests\Concerns\SignsJazzCashPayloads;
use MaheshKerai\PaymentGateways\Tests\TestCase;

final class JazzCashFlowTest extends TestCase
{
    use SignsJazzCashPayloads;

    private const CALLBACK_URL = 'https://app.test/payment-success?metadata=abc';

    protected function setUp(): void
    {
        parent::setUp();

        PaymentGateway::create([
            'name' => 'jazzcash',
            'publishable_key' => 'MC0001',
            'password' => 'pw-123',
            'secret_key' => self::JAZZCASH_SALT,
            'status' => 1,
        ]);

        config(['payment-gateways.gateways.jazzcash.mode' => 'sandbox']);
    }

    /**
     * Start a payment through the same entry point host applications use.
     */
    private function initializePayment(float $amount = 100.5): array
    {
        $result = app(PaymentService::class)->onlinePaymentMethod('jazzcash', $amount, 'PKR', self::CALLBACK_URL, [
            'user_id' => 1,
            'product_name' => 'Subscription Plan',
        ]);

        $this->assertTrue($result['success'], $result['message'] ?? '');

        return $result;
    }

    /**
     * What JazzCash posts to the return URL for a payment started with initializePayment().
     */
    private function jazzcashReturn(array $result, array $overrides = [], string $salt = self::JAZZCASH_SALT): array
    {
        return $this->jazzcashResponse($result['order_id'], $result['payment_data']['txn_ref_no'], $overrides, $salt);
    }

    private function returnUrl(array $result): string
    {
        return Cache::get(JazzCashGateway::checkoutCacheKey($result['order_id']))['fields']['pp_ReturnURL'];
    }

    private function redirectQuery($response): array
    {
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://app.test/payment-success?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_payment_service_initializes_a_jazzcash_payment(): void
    {
        $result = $this->initializePayment();

        $transaction = PaymentTransaction::find($result['transaction_id']);

        $this->assertSame('pending', $transaction->status);
        $this->assertSame(100.5, (float) $transaction->amount);
        $this->assertSame($result['order_id'], $transaction->order_id);
        $this->assertSame($result['payment_data']['txn_ref_no'], $transaction->payment_id);
        $this->assertSame(
            $result['order_id'],
            Cache::get(JazzCashGateway::checkoutCacheKey($result['order_id']))['fields']['ppmpf_1']
        );
    }

    public function test_payment_service_reports_jazzcash_failures_like_other_gateways(): void
    {
        $result = app(PaymentService::class)->onlinePaymentMethod('jazzcash', 100.0, 'USD', self::CALLBACK_URL);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Payment initialization failed: JazzCash payment initialization failed', $result['message']);
        $this->assertSame(0, PaymentTransaction::count());
    }

    public function test_payment_service_requires_the_password_stored_with_the_gateway(): void
    {
        PaymentGateway::where('name', 'jazzcash')->update(['password' => null]);

        $result = app(PaymentService::class)->onlinePaymentMethod('jazzcash', 100.0, 'PKR', self::CALLBACK_URL);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('JazzCash Password is not configured', $result['message']);
    }

    public function test_checkout_page_auto_submits_the_signed_form_to_jazzcash(): void
    {
        $result = $this->initializePayment();

        $response = $this->get($result['payment_data']['checkout_url']);

        $response->assertOk();
        $response->assertSee('action="https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/"', false);
        $response->assertSee('name="pp_SecureHash"', false);
        $response->assertSee('name="pp_Amount" value="10050"', false);
        $response->assertSee('name="ppmpf_1" value="' . $result['order_id'] . '"', false);
        $response->assertDontSee(self::JAZZCASH_SALT);
    }

    public function test_checkout_page_rejects_unsigned_links(): void
    {
        $result = $this->initializePayment();

        $this->get('/payment-gateways/jazzcash/checkout/' . $result['order_id'])->assertForbidden();
    }

    public function test_checkout_page_reports_an_expired_session(): void
    {
        $result = $this->initializePayment();
        Cache::forget(JazzCashGateway::checkoutCacheKey($result['order_id']));

        $this->get($result['payment_data']['checkout_url'])->assertStatus(410);
    }

    public function test_verified_callback_marks_the_transaction_successful_and_redirects_to_the_callback_url(): void
    {
        $result = $this->initializePayment();

        $response = $this->post($this->returnUrl($result), $this->jazzcashReturn($result));

        $query = $this->redirectQuery($response);
        $this->assertSame('success', $query['status']);
        $this->assertSame($result['order_id'], $query['order_id']);
        $this->assertSame('123456789012', $query['payment_id']);
        $this->assertSame('abc', $query['metadata']);
        $this->assertEquals($result['transaction_id'], $query['transaction_id']);

        $transaction = PaymentTransaction::find($result['transaction_id']);
        $this->assertSame('success', $transaction->status);
        $this->assertSame('123456789012', $transaction->payment_id);
    }

    public function test_replaying_a_successful_callback_is_harmless(): void
    {
        $result = $this->initializePayment();
        $payload = $this->jazzcashReturn($result);

        $this->post($this->returnUrl($result), $payload);
        $query = $this->redirectQuery($this->post($this->returnUrl($result), $payload));

        $this->assertSame('success', $query['status']);
        $this->assertSame('success', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_failed_callback_marks_the_transaction_failed(): void
    {
        $result = $this->initializePayment();

        $response = $this->post($this->returnUrl($result), $this->jazzcashReturn($result, [
            'pp_ResponseCode' => '121',
            'pp_ResponseMessage' => 'Insufficient balance',
        ]));

        $query = $this->redirectQuery($response);
        $this->assertSame('failed', $query['status']);
        $this->assertStringContainsString('Insufficient balance', $query['message']);
        $this->assertSame('failed', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_pending_callback_leaves_the_transaction_pending(): void
    {
        $result = $this->initializePayment();

        $response = $this->post($this->returnUrl($result), $this->jazzcashReturn($result, ['pp_ResponseCode' => '124']));

        $this->assertSame('pending', $this->redirectQuery($response)['status']);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_callback_with_an_invalid_hash_is_rejected_and_leaves_the_transaction_untouched(): void
    {
        $result = $this->initializePayment();

        $payload = $this->jazzcashReturn($result);
        $payload['pp_SecureHash'] = str_repeat('0', 64);

        $query = $this->redirectQuery($this->post($this->returnUrl($result), $payload));

        $this->assertSame('failed', $query['status']);
        $this->assertSame('Invalid JazzCash secure hash.', $query['message']);
        $this->assertArrayNotHasKey('payment_id', $query);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_callback_changed_after_signing_is_rejected(): void
    {
        $result = $this->initializePayment();

        $payload = $this->jazzcashReturn($result);
        $payload['pp_Amount'] = '100';

        $query = $this->redirectQuery($this->post($this->returnUrl($result), $payload));

        $this->assertSame('failed', $query['status']);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_callback_signed_with_another_salt_is_rejected(): void
    {
        $result = $this->initializePayment();

        $query = $this->redirectQuery(
            $this->post($this->returnUrl($result), $this->jazzcashReturn($result, [], 'someone-elses-salt'))
        );

        $this->assertSame('failed', $query['status']);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_callback_without_a_hash_is_rejected(): void
    {
        $result = $this->initializePayment();

        $payload = $this->jazzcashReturn($result);
        unset($payload['pp_SecureHash']);

        $query = $this->redirectQuery($this->post($this->returnUrl($result), $payload));

        $this->assertSame('failed', $query['status']);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_a_valid_callback_cannot_be_replayed_against_another_order(): void
    {
        $cheap = $this->initializePayment(10.0);
        $expensive = $this->initializePayment(5000.0);

        // A genuine, successful response for the cheap order, posted to the expensive order's return URL.
        $query = $this->redirectQuery(
            $this->post($this->returnUrl($expensive), $this->jazzcashReturn($cheap))
        );

        $this->assertSame('failed', $query['status']);
        $this->assertSame('pending', PaymentTransaction::find($expensive['transaction_id'])->status);
        $this->assertSame('pending', PaymentTransaction::find($cheap['transaction_id'])->status);
    }

    public function test_callback_is_rejected_when_the_gateway_is_inactive(): void
    {
        $result = $this->initializePayment();
        PaymentGateway::where('name', 'jazzcash')->update(['status' => 0]);

        $query = $this->redirectQuery($this->post($this->returnUrl($result), $this->jazzcashReturn($result)));

        $this->assertSame('failed', $query['status']);
        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_callback_falls_back_to_the_unified_callback_route_when_the_callback_url_is_gone(): void
    {
        $result = $this->initializePayment();
        Cache::forget(JazzCashGateway::callbackCacheKey($result['order_id']));

        $response = $this->post($this->returnUrl($result), $this->jazzcashReturn($result));

        $response->assertRedirect();
        $this->assertStringStartsWith(
            route('payment-gateways.callback'),
            $response->headers->get('Location')
        );
        $this->assertSame('success', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_return_route_neither_starts_a_session_nor_checks_csrf(): void
    {
        // Instantiating the HTTP kernel syncs the middleware groups into the router.
        $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $router = app('router');

        $middleware = fn (string $name): array => array_filter(
            (array) $router->gatherRouteMiddleware($router->getRoutes()->getByName($name)),
            'is_string'
        );

        $isCsrfOrSession = static fn (string $class): bool => is_a($class, StartSession::class, true)
            || is_a($class, \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, true);

        // JazzCash posts from its own domain: no session cookie arrives, and a new one must not replace the customer's.
        $this->assertSame([], array_filter($middleware('payment-gateways.jazzcash.callback'), $isCsrfOrSession));
        // Sanity check: the other package routes still run in the web group.
        $this->assertNotEmpty(array_filter($middleware('payment-gateways.jazzcash.checkout'), $isCsrfOrSession));
    }

    public function test_return_route_does_not_set_a_session_cookie(): void
    {
        $result = $this->initializePayment();

        $sessionCookies = static fn ($response): array => array_filter(
            $response->headers->getCookies(),
            static fn ($cookie): bool => str_ends_with($cookie->getName(), '_session')
        );

        $callback = $this->post($this->returnUrl($result), $this->jazzcashReturn($result));
        $checkout = $this->get($result['payment_data']['checkout_url']);

        $this->assertSame([], $sessionCookies($callback));
        $this->assertNotEmpty($sessionCookies($checkout));
    }

    public function test_webhook_marks_the_transaction_successful(): void
    {
        $result = $this->initializePayment();

        $this->post('/payment-gateways/webhooks/jazzcash', $this->jazzcashReturn($result))->assertOk();

        $transaction = PaymentTransaction::find($result['transaction_id']);
        $this->assertSame('success', $transaction->status);
        $this->assertSame('123456789012', $transaction->payment_id);
    }

    public function test_webhook_with_an_invalid_hash_does_not_change_the_transaction(): void
    {
        $result = $this->initializePayment();

        $payload = $this->jazzcashReturn($result);
        $payload['pp_SecureHash'] = str_repeat('0', 64);

        $this->post('/payment-gateways/webhooks/jazzcash', $payload)->assertOk();

        $this->assertSame('pending', PaymentTransaction::find($result['transaction_id'])->status);
    }

    public function test_webhook_reports_failed_payments(): void
    {
        $result = $this->initializePayment();

        $this->post('/payment-gateways/webhooks/jazzcash', $this->jazzcashReturn($result, ['pp_ResponseCode' => '121']))
            ->assertOk();

        $this->assertSame('failed', PaymentTransaction::find($result['transaction_id'])->status);
    }
}
