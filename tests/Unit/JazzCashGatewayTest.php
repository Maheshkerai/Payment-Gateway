<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Unit;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface;
use MaheshKerai\PaymentGateways\Gateways\JazzCashGateway;
use MaheshKerai\PaymentGateways\Tests\Concerns\SignsJazzCashPayloads;
use MaheshKerai\PaymentGateways\Tests\TestCase;

final class JazzCashGatewayTest extends TestCase
{
    use SignsJazzCashPayloads;

    private const ORDER_ID = 'ORD-ABCDEFGHIJKL-1700000000';

    private function gateway(array $config = []): JazzCashGateway
    {
        $gateway = new JazzCashGateway();
        $gateway->setConfig(array_merge([
            'publishable_key' => 'MC0001',
            'password' => 'pw-123',
            'secret_key' => self::JAZZCASH_SALT,
            'mode' => 'sandbox',
        ], $config));

        return $gateway;
    }

    private function metadata(array $extra = []): array
    {
        return array_merge([
            'product_name' => 'Subscription Plan',
            'callback_url' => 'https://app.test/payment-success?metadata=abc',
        ], $extra);
    }

    private function checkoutPayload(string $orderId = self::ORDER_ID): array
    {
        return Cache::get(JazzCashGateway::checkoutCacheKey($orderId));
    }

    public function test_it_implements_the_gateway_contract_and_is_registered(): void
    {
        $resolved = $this->app->make('payment.gateway.jazzcash');

        $this->assertInstanceOf(JazzCashGateway::class, $resolved);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $resolved);
    }

    public function test_transaction_reference_is_deterministic_and_within_jazzcash_limit(): void
    {
        $reference = JazzCashGateway::transactionReferenceFor(self::ORDER_ID);

        $this->assertSame($reference, JazzCashGateway::transactionReferenceFor(self::ORDER_ID));
        $this->assertLessThanOrEqual(20, strlen($reference));
        $this->assertNotSame($reference, JazzCashGateway::transactionReferenceFor('ORD-ZZZZZZZZZZZZ-1700000001'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $reference);
    }

    public function test_initialize_payment_returns_checkout_payload_with_a_valid_secure_hash(): void
    {
        $result = $this->gateway()->initializePayment(100.5, 'pkr', self::ORDER_ID, $this->metadata());
        $reference = JazzCashGateway::transactionReferenceFor(self::ORDER_ID);

        $this->assertSame($reference, $result['payment_id']);
        $this->assertSame(self::ORDER_ID, $result['order_id']);
        $this->assertSame('POST', $result['method']);
        $this->assertSame(
            'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/',
            $result['jazzcash_url']
        );
        $this->assertStringContainsString('/payment-gateways/jazzcash/checkout/' . self::ORDER_ID, $result['checkout_url']);
        $this->assertStringContainsString('signature=', $result['checkout_url']);
        // pp_Password is part of the posted form, so it is only served by the checkout route.
        $this->assertArrayNotHasKey('form_fields', $result);

        $checkout = $this->checkoutPayload();
        $fields = $checkout['fields'];

        $this->assertSame($result['jazzcash_url'], $checkout['endpoint']);
        $this->assertSame('MC0001', $fields['pp_MerchantID']);
        $this->assertSame('pw-123', $fields['pp_Password']);
        $this->assertSame('10050', $fields['pp_Amount']); // paisa, whole number
        $this->assertSame('PKR', $fields['pp_TxnCurrency']);
        $this->assertSame('1.1', $fields['pp_Version']);
        $this->assertSame('MWALLET', $fields['pp_TxnType']);
        $this->assertSame($reference, $fields['pp_TxnRefNo']);
        $this->assertSame('Subscription Plan', $fields['pp_Description']);
        $this->assertSame(self::ORDER_ID, $fields['ppmpf_1']);
        $this->assertSame(
            url('/payment-gateways/jazzcash/callback/' . self::ORDER_ID),
            $fields['pp_ReturnURL']
        );
        $this->assertMatchesRegularExpression('/^\d{14}$/', $fields['pp_TxnDateTime']);
        $this->assertGreaterThan($fields['pp_TxnDateTime'], $fields['pp_TxnExpiryDateTime']);
        $this->assertArrayNotHasKey('pp_HashKey', $fields);
        $this->assertNotContains(self::JAZZCASH_SALT, $fields);

        // Salt & the non-empty values sorted by field name (pp_SubMerchantID is empty, so skipped).
        $expectedMessage = implode('&', [
            self::JAZZCASH_SALT,
            $fields['pp_Amount'], $fields['pp_BankID'], $fields['pp_BillReference'], $fields['pp_Description'],
            $fields['pp_Language'], $fields['pp_MerchantID'], $fields['pp_Password'], $fields['pp_ProductID'],
            $fields['pp_ReturnURL'], $fields['pp_TxnCurrency'], $fields['pp_TxnDateTime'],
            $fields['pp_TxnExpiryDateTime'], $fields['pp_TxnRefNo'], $fields['pp_TxnType'], $fields['pp_Version'],
            $fields['ppmpf_1'], $fields['ppmpf_2'], $fields['ppmpf_3'], $fields['ppmpf_4'], $fields['ppmpf_5'],
        ]);
        $this->assertSame(hash_hmac('sha256', $expectedMessage, self::JAZZCASH_SALT), $fields['pp_SecureHash']);
    }

    public function test_initialize_payment_stores_the_callback_url_for_the_return_route(): void
    {
        $this->gateway()->initializePayment(100.0, 'PKR', self::ORDER_ID, $this->metadata());

        $this->assertSame(
            'https://app.test/payment-success?metadata=abc',
            Cache::get(JazzCashGateway::callbackCacheKey(self::ORDER_ID))
        );
    }

    public function test_initialize_payment_uses_the_endpoint_of_the_configured_environment(): void
    {
        $sandbox = $this->gateway(['mode' => 'sandbox'])
            ->initializePayment(100.0, 'PKR', self::ORDER_ID, $this->metadata());
        $production = $this->gateway(['mode' => 'production'])
            ->initializePayment(100.0, 'PKR', 'ORD-PRODUCTIONORDER-1', $this->metadata());

        $this->assertSame(
            'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/',
            $sandbox['jazzcash_url']
        );
        $this->assertSame(
            'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/',
            $production['jazzcash_url']
        );
    }

    public function test_initialize_payment_supports_a_return_url_override_and_transaction_type(): void
    {
        $this->gateway(['return_url' => 'https://shop.test/jazzcash/{order_id}/return', 'transaction_type' => 'MIGS'])
            ->initializePayment(100.0, 'PKR', self::ORDER_ID, $this->metadata());

        $fields = $this->checkoutPayload()['fields'];

        $this->assertSame('https://shop.test/jazzcash/' . self::ORDER_ID . '/return', $fields['pp_ReturnURL']);
        $this->assertSame('MIGS', $fields['pp_TxnType']);
    }

    public function test_initialize_payment_strips_special_characters_from_the_description(): void
    {
        $this->gateway()->initializePayment(100.0, 'PKR', self::ORDER_ID, $this->metadata([
            'product_name' => 'Plan <b>A</b> & "Pro"',
        ]));

        $this->assertSame('Plan bAb  Pro', $this->checkoutPayload()['fields']['pp_Description']);
    }

    public function test_initialize_payment_rounds_amounts_to_whole_paisa(): void
    {
        $this->gateway()->initializePayment(19.99, 'PKR', self::ORDER_ID, $this->metadata());

        $this->assertSame('1999', $this->checkoutPayload()['fields']['pp_Amount']);
    }

    public function test_initialize_payment_rejects_non_pkr_currency(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JazzCash only supports PKR');

        $this->gateway()->initializePayment(100.0, 'USD', self::ORDER_ID, $this->metadata());
    }

    public function test_initialize_payment_rejects_non_positive_amount(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Amount must be greater than zero');

        $this->gateway()->initializePayment(0.004, 'PKR', self::ORDER_ID, $this->metadata());
    }

    public function test_initialize_payment_requires_every_credential(): void
    {
        foreach ([
            'publishable_key' => 'JazzCash Merchant ID is not configured',
            'password' => 'JazzCash Password is not configured',
            'secret_key' => 'JazzCash Integrity Salt is not configured',
        ] as $key => $message) {
            try {
                $this->gateway([$key => ''])->initializePayment(100.0, 'PKR', self::ORDER_ID, $this->metadata());
                $this->fail("Expected a RuntimeException when {$key} is missing.");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('JazzCash payment initialization failed:', $e->getMessage());
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_secure_hash_ignores_non_jazzcash_fields_empty_values_and_the_hash_itself(): void
    {
        $fields = ['pp_Amount' => '100', 'pp_Language' => '', 'ppmpf_1' => 'x'];

        $hash = JazzCashGateway::secureHash($fields, 'salt');

        $this->assertSame(
            $hash,
            JazzCashGateway::secureHash($fields + ['pp_SecureHash' => 'abc', 'order_id' => 'o', '_token' => 't'], 'salt')
        );
        $this->assertSame(hash_hmac('sha256', 'salt&100&x', 'salt'), $hash);
    }

    public function test_verify_payment_accepts_a_correctly_signed_successful_callback(): void
    {
        $reference = JazzCashGateway::transactionReferenceFor(self::ORDER_ID);
        $payload = $this->jazzcashResponse(self::ORDER_ID, $reference);

        $result = $this->gateway()->verifyPayment(array_merge($payload, ['order_id' => self::ORDER_ID]));

        $this->assertTrue($result['success']);
        $this->assertSame('123456789012', $result['payment_id']);
        $this->assertSame(self::ORDER_ID, $result['order_id']);
        $this->assertSame($reference, $result['txn_ref_no']);
        $this->assertSame(100.5, $result['amount']);
        $this->assertSame('PKR', $result['currency']);
        $this->assertSame('success', $result['status']);
    }

    public function test_verify_payment_accepts_lowercase_and_uppercase_hashes(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');
        $payload['pp_SecureHash'] = strtolower($payload['pp_SecureHash']);

        $this->assertTrue($this->gateway()->verifyPayment($payload)['success']);
    }

    public function test_verify_payment_rejects_a_callback_with_an_invalid_hash(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');
        $payload['pp_SecureHash'] = str_repeat('0', 64);

        $result = $this->gateway()->verifyPayment($payload);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid JazzCash secure hash.', $result['message']);
        $this->assertSame('failed', $result['status']);
    }

    public function test_verify_payment_rejects_a_callback_changed_after_signing(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');
        $payload['pp_Amount'] = '100'; // paid 1.00 instead of 100.50

        $result = $this->gateway()->verifyPayment($payload);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid JazzCash secure hash.', $result['message']);
    }

    public function test_verify_payment_rejects_a_callback_signed_with_another_salt(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1', [], 'someone-elses-salt');

        $this->assertFalse($this->gateway()->verifyPayment($payload)['success']);
    }

    public function test_verify_payment_rejects_a_valid_callback_belonging_to_another_order(): void
    {
        $payload = $this->jazzcashResponse('ORD-SOMEOTHERORDER-1', 'T1');

        $result = $this->gateway()->verifyPayment(array_merge($payload, ['order_id' => self::ORDER_ID]));

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid JazzCash secure hash.', $result['message']);
    }

    public function test_verify_payment_requires_a_configured_salt_for_callbacks(): void
    {
        $result = $this->gateway(['secret_key' => ''])->verifyPayment($this->jazzcashResponse(self::ORDER_ID, 'T1'));

        $this->assertFalse($result['success']);
        $this->assertSame('JazzCash integrity salt not configured.', $result['message']);
    }

    public function test_verify_payment_reports_failed_payments(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1', [
            'pp_ResponseCode' => '121',
            'pp_ResponseMessage' => 'Insufficient balance',
        ]);

        $result = $this->gateway()->verifyPayment($payload);

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('Insufficient balance', $result['message']);
    }

    public function test_verify_payment_reports_pending_payments(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1', ['pp_ResponseCode' => '124']);

        $result = $this->gateway()->verifyPayment($payload);

        $this->assertFalse($result['success']);
        $this->assertSame('pending', $result['status']);
    }

    public function test_verify_payment_requires_an_order_id_without_a_callback_payload(): void
    {
        $result = $this->gateway()->verifyPayment([]);

        $this->assertFalse($result['success']);
        $this->assertSame('Order ID is required for verification.', $result['message']);
    }

    public function test_verify_payment_checks_status_with_the_inquiry_api(): void
    {
        $reference = JazzCashGateway::transactionReferenceFor(self::ORDER_ID);
        Http::fake([
            'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/PaymentInquiry/Inquire' => Http::response([
                'pp_ResponseCode' => '000',
                'pp_PaymentResponseCode' => '000',
                'pp_Status' => 'Completed',
                'pp_Amount' => '10050',
                'pp_RetrievalReferenceNo' => '555000111222',
            ]),
        ]);

        $result = $this->gateway()->verifyPayment(['order_id' => self::ORDER_ID]);

        $this->assertTrue($result['success']);
        $this->assertSame('555000111222', $result['payment_id']);
        $this->assertSame(100.5, $result['amount']);

        Http::assertSent(function ($request) use ($reference) {
            $data = $request->data();

            return $request->url() === 'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/PaymentInquiry/Inquire'
                && $data['pp_TxnRefNo'] === $reference
                && $data['pp_MerchantID'] === 'MC0001'
                && $data['pp_SecureHash'] === $this->jazzcashHash([
                    'pp_TxnRefNo' => $reference,
                    'pp_MerchantID' => 'MC0001',
                    'pp_Password' => 'pw-123',
                ]);
        });
    }

    public function test_verify_payment_uses_the_production_inquiry_endpoint_in_production(): void
    {
        Http::fake([
            'https://payments.jazzcash.com.pk/ApplicationAPI/API/PaymentInquiry/Inquire' => Http::response([
                'pp_PaymentResponseCode' => '000',
            ]),
        ]);

        $this->assertTrue($this->gateway(['mode' => 'production'])->verifyPayment(['order_id' => self::ORDER_ID])['success']);
    }

    public function test_verify_payment_reports_unsuccessful_and_pending_inquiries(): void
    {
        Http::fakeSequence()
            ->push(['pp_PaymentResponseCode' => '121', 'pp_PaymentResponseMessage' => 'Declined'])
            ->push(['pp_Status' => 'Pending']);

        $failed = $this->gateway()->verifyPayment(['order_id' => self::ORDER_ID]);
        $pending = $this->gateway()->verifyPayment(['order_id' => self::ORDER_ID]);

        $this->assertFalse($failed['success']);
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('Declined', $failed['message']);
        $this->assertFalse($pending['success']);
        $this->assertSame('pending', $pending['status']);
    }

    public function test_verify_payment_handles_inquiry_http_errors(): void
    {
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $result = $this->gateway()->verifyPayment(['order_id' => self::ORDER_ID]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('HTTP 500', $result['message']);
    }

    public function test_verify_payment_handles_jazzcash_being_unreachable(): void
    {
        Http::fake(static function () {
            throw new ConnectionException('Connection timed out');
        });

        $result = $this->gateway()->verifyPayment(['order_id' => self::ORDER_ID]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('could not reach JazzCash', $result['message']);
    }

    public function test_webhook_accepts_a_correctly_signed_success_event(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');

        $result = $this->gateway()->handleWebhook(['body' => $payload]);

        $this->assertTrue($result['success']);
        $this->assertSame('payment.success', $result['event_type']);
        $this->assertSame(self::ORDER_ID, $result['order_id']);
        $this->assertSame('123456789012', $result['payment_id']);
        $this->assertSame(100.5, $result['amount']);
    }

    public function test_webhook_accepts_json_and_form_encoded_raw_bodies(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');

        $this->assertTrue($this->gateway()->handleWebhook(['body' => json_encode($payload)])['success']);
        $this->assertTrue($this->gateway()->handleWebhook(['body' => http_build_query($payload)])['success']);
    }

    public function test_webhook_rejects_an_invalid_hash(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');
        $payload['pp_SecureHash'] = str_repeat('f', 64);

        $result = $this->gateway()->handleWebhook(['body' => $payload]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid webhook signature.', $result['message']);
    }

    public function test_webhook_rejects_a_payload_without_a_hash(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1');
        unset($payload['pp_SecureHash']);

        $result = $this->gateway()->handleWebhook(['body' => $payload]);

        $this->assertFalse($result['success']);
        $this->assertSame('Invalid webhook signature.', $result['message']);
    }

    public function test_webhook_requires_a_configured_salt(): void
    {
        $result = $this->gateway(['secret_key' => ''])->handleWebhook(['body' => $this->jazzcashResponse(self::ORDER_ID, 'T1')]);

        $this->assertFalse($result['success']);
        $this->assertSame('JazzCash integrity salt not configured.', $result['message']);
    }

    public function test_webhook_requires_an_order_id(): void
    {
        $payload = $this->jazzcashResponse(self::ORDER_ID, 'T1', ['ppmpf_1' => '']);

        $result = $this->gateway()->handleWebhook(['body' => $payload]);

        $this->assertFalse($result['success']);
        $this->assertSame('Order ID is missing in webhook.', $result['message']);
    }

    public function test_webhook_reports_failed_and_pending_payments(): void
    {
        $failed = $this->gateway()->handleWebhook([
            'body' => $this->jazzcashResponse(self::ORDER_ID, 'T1', ['pp_ResponseCode' => '121']),
        ]);
        $pending = $this->gateway()->handleWebhook([
            'body' => $this->jazzcashResponse(self::ORDER_ID, 'T1', ['pp_ResponseCode' => '157']),
        ]);

        $this->assertFalse($failed['success']);
        $this->assertSame('failed', $failed['status']);
        $this->assertSame(self::ORDER_ID, $failed['order_id']);
        $this->assertFalse($pending['success']);
        $this->assertSame('pending', $pending['status']);
    }
}
