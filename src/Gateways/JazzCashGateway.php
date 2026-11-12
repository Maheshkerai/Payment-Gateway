<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

final class JazzCashGateway extends BaseGateway
{
    /**
     * JazzCash endpoints per environment.
     *
     * "checkout" is the Page Redirection (HTTP POST) form endpoint,
     * "inquiry" is the Payment Inquiry API used for status checks.
     *
     * @var array<string, array<string, string>>
     */
    private const ENDPOINTS = [
        'sandbox' => [
            'checkout' => 'https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/',
            'inquiry' => 'https://sandbox.jazzcash.com.pk/ApplicationAPI/API/PaymentInquiry/Inquire',
        ],
        'production' => [
            'checkout' => 'https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform/',
            'inquiry' => 'https://payments.jazzcash.com.pk/ApplicationAPI/API/PaymentInquiry/Inquire',
        ],
    ];

    /**
     * JazzCash response code for a successful payment.
     *
     * @var string
     */
    private const SUCCESS_CODE = '000';

    /**
     * JazzCash response codes meaning the payment has not been settled yet.
     *
     * @var array<int, string>
     */
    private const PENDING_CODES = ['124', '157'];

    /**
     * JazzCash limits pp_TxnRefNo to 20 characters.
     *
     * @var int
     */
    private const MAX_REFERENCE_LENGTH = 20;

    /**
     * JazzCash only settles in Pakistani rupees.
     *
     * @var string
     */
    private const CURRENCY = 'PKR';

    /**
     * Timezone JazzCash expects pp_TxnDateTime and pp_TxnExpiryDateTime in.
     *
     * @var string
     */
    private const TIMEZONE = 'Asia/Karachi';

    /**
     * Minutes the signed checkout link and cached form payload stay valid.
     *
     * @var int
     */
    private const CHECKOUT_TTL_MINUTES = 30;

    /**
     * Minutes the calling application's callback URL is kept.
     *
     * @var int
     */
    private const CALLBACK_TTL_MINUTES = 60;

    /**
     * Minutes a payment can be completed on JazzCash before it expires.
     *
     * @var int
     */
    private const PAYMENT_EXPIRY_MINUTES = 60;

    /**
     * Cache key holding the JazzCash form endpoint and fields for an order.
     *
     * @param string $orderId
     * @return string
     */
    public static function checkoutCacheKey(string $orderId): string
    {
        return 'payment-gateways:jazzcash:checkout:' . $orderId;
    }

    /**
     * Cache key holding the calling application's callback URL for an order.
     *
     * @param string $orderId
     * @return string
     */
    public static function callbackCacheKey(string $orderId): string
    {
        return 'payment-gateways:jazzcash:callback:' . $orderId;
    }

    /**
     * Derive the JazzCash transaction reference (pp_TxnRefNo) for an order.
     *
     * Package order IDs are longer than JazzCash's 20 character limit, so a deterministic
     * short reference is derived from them. The original order ID travels to JazzCash in
     * ppmpf_1 and comes back, covered by the secure hash, in the callback and IPN.
     *
     * @param string $orderId
     * @return string
     */
    public static function transactionReferenceFor(string $orderId): string
    {
        return substr('T' . strtoupper(hash('sha256', $orderId)), 0, self::MAX_REFERENCE_LENGTH);
    }

    /**
     * Compute the JazzCash secure hash.
     *
     * Per JazzCash's HTTP POST integration guide: take every pp_/ppmpf_ field except
     * pp_SecureHash that has a value, sort by field name, join the values with "&"
     * prefixed by the integrity salt, then HMAC-SHA256 the result with the integrity salt.
     *
     * @param array $fields
     * @param string $salt
     * @return string
     */
    public static function secureHash(array $fields, string $salt): string
    {
        $fields = array_filter(
            $fields,
            static fn ($value, $name): bool => is_string($name)
                && $name !== 'pp_SecureHash'
                && (str_starts_with($name, 'pp_') || str_starts_with($name, 'ppmpf_'))
                && is_scalar($value)
                && (string) $value !== '',
            ARRAY_FILTER_USE_BOTH
        );

        ksort($fields, SORT_STRING);

        $message = $salt;
        foreach ($fields as $value) {
            $message .= '&' . $value;
        }

        return hash_hmac('sha256', $message, $salt);
    }

    /**
     * Whether a callback/IPN payload carries a valid secure hash and, when an
     * order ID is given, belongs to that order (ppmpf_1 holds our order ID).
     *
     * @param array $payload
     * @param string $salt
     * @param string|null $orderId
     * @return bool
     */
    public static function isAuthenticCallback(array $payload, string $salt, ?string $orderId = null): bool
    {
        $received = $payload['pp_SecureHash'] ?? null;

        if ($salt === '' || !is_string($received) || $received === '') {
            return false;
        }

        if (!hash_equals(self::secureHash($payload, $salt), strtolower($received))) {
            return false;
        }

        return $orderId === null || ($payload['ppmpf_1'] ?? null) === $orderId;
    }

    /**
     * Translate a JazzCash response payload into the package result format.
     * The payload must already be authenticated.
     *
     * @param array $fields
     * @return array
     */
    public static function interpretResponse(array $fields): array
    {
        $code = (string) ($fields['pp_ResponseCode'] ?? '');
        $message = trim((string) ($fields['pp_ResponseMessage'] ?? ''));
        $orderId = ($fields['ppmpf_1'] ?? '') !== '' ? (string) $fields['ppmpf_1'] : null;
        $reference = ($fields['pp_TxnRefNo'] ?? '') !== '' ? (string) $fields['pp_TxnRefNo'] : null;

        $base = [
            'order_id' => $orderId,
            'txn_ref_no' => $reference,
            'response_code' => $code,
        ];

        if ($code === self::SUCCESS_CODE) {
            $retrievalReference = (string) ($fields['pp_RetreivalReferenceNo'] ?? '');

            return array_merge($base, [
                'success' => true,
                'payment_id' => $retrievalReference !== '' ? $retrievalReference : $reference,
                'amount' => (float) ((int) ($fields['pp_Amount'] ?? 0) / 100),
                'currency' => (string) ($fields['pp_TxnCurrency'] ?? self::CURRENCY),
                'status' => 'success',
                'state' => $code,
            ]);
        }

        if (in_array($code, self::PENDING_CODES, true)) {
            return array_merge($base, [
                'success' => false,
                'message' => 'Payment is pending. ' . $message,
                'status' => 'pending',
                'state' => $code,
            ]);
        }

        return array_merge($base, [
            'success' => false,
            'message' => 'Payment failed. ' . ($message !== '' ? $message : 'Response code: ' . ($code !== '' ? $code : 'unknown')),
            'status' => 'failed',
            'state' => $code,
        ]);
    }

    /**
     * Initialize a payment transaction.
     *
     * @param float $amount
     * @param string $currency
     * @param string $orderId
     * @param array $metadata
     * @return array
     */
    public function initializePayment(float $amount, string $currency, string $orderId, array $metadata = []): array
    {
        try {
            $currencyCode = strtoupper($currency);

            if ($currencyCode !== self::CURRENCY) {
                throw new \RuntimeException("Currency '{$currencyCode}' is not supported. JazzCash only supports " . self::CURRENCY . '.');
            }

            // JazzCash expects the amount in paisa as a whole number.
            $amountInPaisa = (int) round($amount * 100);

            if ($amountInPaisa <= 0) {
                throw new \RuntimeException('Amount must be greater than zero.');
            }

            [$merchantId, $password, $salt] = $this->credentials();
            $endpoints = $this->endpoints();

            $reference = self::transactionReferenceFor($orderId);
            $now = now(self::TIMEZONE);

            $fields = [
                'pp_Version' => '1.1',
                'pp_TxnType' => trim((string) $this->getConfigValue('transaction_type', 'MWALLET')),
                'pp_Language' => 'EN',
                'pp_MerchantID' => $merchantId,
                'pp_SubMerchantID' => '',
                'pp_Password' => $password,
                'pp_BankID' => 'TBANK',
                'pp_ProductID' => 'RETL',
                'pp_TxnRefNo' => $reference,
                'pp_Amount' => (string) $amountInPaisa,
                'pp_TxnCurrency' => self::CURRENCY,
                'pp_TxnDateTime' => $now->format('YmdHis'),
                'pp_BillReference' => $reference,
                'pp_Description' => $this->description($metadata, $orderId),
                'pp_TxnExpiryDateTime' => $now->copy()->addMinutes(self::PAYMENT_EXPIRY_MINUTES)->format('YmdHis'),
                'pp_ReturnURL' => $this->returnUrl($orderId),
                // ppmpf_1 carries our order ID and comes back (hash-protected) in the callback.
                'ppmpf_1' => $orderId,
                'ppmpf_2' => $this->customField($metadata, 'ppmpf_2', '2'),
                'ppmpf_3' => $this->customField($metadata, 'ppmpf_3', '3'),
                'ppmpf_4' => $this->customField($metadata, 'ppmpf_4', '4'),
                'ppmpf_5' => $this->customField($metadata, 'ppmpf_5', '5'),
            ];

            $fields['pp_SecureHash'] = self::secureHash($fields, $salt);

            Cache::put(
                self::checkoutCacheKey($orderId),
                ['endpoint' => $endpoints['checkout'], 'fields' => $fields],
                now()->addMinutes(self::CHECKOUT_TTL_MINUTES)
            );

            $callbackUrl = (string) ($metadata['callback_url'] ?? '');
            if ($callbackUrl !== '') {
                Cache::put(self::callbackCacheKey($orderId), $callbackUrl, now()->addMinutes(self::CALLBACK_TTL_MINUTES));
            }

            // JazzCash needs a POST form submission, so checkout_url points to a package
            // route that auto-submits it. Callers redirect to it like any other gateway.
            // The form carries pp_Password (JazzCash protocol), so it is only served from there.
            $checkoutUrl = URL::temporarySignedRoute(
                'payment-gateways.jazzcash.checkout',
                now()->addMinutes(self::CHECKOUT_TTL_MINUTES),
                ['orderId' => $orderId]
            );

            return [
                'payment_id' => $reference,
                'order_id' => $orderId,
                'txn_ref_no' => $reference,
                'checkout_url' => $checkoutUrl,
                'jazzcash_url' => $endpoints['checkout'],
                'method' => 'POST',
            ];
        } catch (\Exception $e) {
            throw new \RuntimeException('JazzCash payment initialization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify a payment transaction.
     *
     * With a JazzCash response payload (pp_SecureHash present) the secure hash is verified
     * and the payload evaluated. With only an order ID, JazzCash's Payment Inquiry API is queried.
     *
     * @param array $data
     * @return array
     */
    public function verifyPayment(array $data): array
    {
        try {
            $orderId = $data['order_id'] ?? $data['merchant_order_id'] ?? null;
            $orderId = $orderId !== null ? (string) $orderId : null;

            if (isset($data['pp_SecureHash'])) {
                $salt = trim((string) $this->getConfigValue('secret_key', ''));

                if ($salt === '') {
                    return [
                        'success' => false,
                        'message' => 'JazzCash integrity salt not configured.',
                    ];
                }

                if (!self::isAuthenticCallback($data, $salt, $orderId)) {
                    return [
                        'success' => false,
                        'message' => 'Invalid JazzCash secure hash.',
                        'status' => 'failed',
                    ];
                }

                return self::interpretResponse($data);
            }

            if (!$orderId) {
                return [
                    'success' => false,
                    'message' => 'Order ID is required for verification.',
                ];
            }

            return $this->inquire($orderId);
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: could not reach JazzCash. ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle webhook callback (JazzCash IPN).
     *
     * @param array $payload
     * @return array
     */
    public function handleWebhook(array $payload): array
    {
        try {
            $salt = trim((string) $this->getConfigValue('secret_key', ''));

            if ($salt === '') {
                return [
                    'success' => false,
                    'message' => 'JazzCash integrity salt not configured.',
                ];
            }

            $body = $payload['body'] ?? [];
            if (is_string($body)) {
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    parse_str($body, $decoded);
                }
                $body = $decoded;
            }
            $body = is_array($body) ? $body : [];

            if (!self::isAuthenticCallback($body, $salt)) {
                return [
                    'success' => false,
                    'message' => 'Invalid webhook signature.',
                ];
            }

            $result = self::interpretResponse($body);

            if (empty($result['order_id'])) {
                return [
                    'success' => false,
                    'message' => 'Order ID is missing in webhook.',
                ];
            }

            if ($result['success']) {
                $result['event_type'] = 'payment.success';
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Webhook handling failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ask JazzCash for the state of a payment.
     *
     * @param string $orderId
     * @return array
     */
    private function inquire(string $orderId): array
    {
        [$merchantId, $password, $salt] = $this->credentials();

        $reference = self::transactionReferenceFor($orderId);

        $request = [
            'pp_TxnRefNo' => $reference,
            'pp_MerchantID' => $merchantId,
            'pp_Password' => $password,
        ];
        $request['pp_SecureHash'] = self::secureHash($request, $salt);

        $response = Http::asJson()
            ->acceptJson()
            ->timeout(30)
            ->post($this->endpoints()['inquiry'], $request);

        if ($response->failed()) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: JazzCash returned HTTP ' . $response->status() . '.',
            ];
        }

        $result = $response->json();

        if (!is_array($result)) {
            return [
                'success' => false,
                'message' => 'Payment verification failed: invalid response from JazzCash.',
            ];
        }

        $paymentCode = (string) ($result['pp_PaymentResponseCode'] ?? '');
        $status = strtolower((string) ($result['pp_Status'] ?? ''));
        $message = trim((string) ($result['pp_PaymentResponseMessage'] ?? $result['pp_ResponseMessage'] ?? ''));

        if ($paymentCode === self::SUCCESS_CODE || $status === 'completed') {
            $retrievalReference = (string) ($result['pp_RetrievalReferenceNo'] ?? $result['pp_RetreivalReferenceNo'] ?? '');

            return [
                'success' => true,
                'payment_id' => $retrievalReference !== '' ? $retrievalReference : $reference,
                'order_id' => $orderId,
                'txn_ref_no' => $reference,
                'amount' => (float) ((int) ($result['pp_Amount'] ?? 0) / 100),
                'currency' => self::CURRENCY,
                'status' => 'success',
                'state' => $paymentCode !== '' ? $paymentCode : $status,
            ];
        }

        if ($status === 'pending' || in_array($paymentCode, self::PENDING_CODES, true)) {
            return [
                'success' => false,
                'message' => 'Payment is pending. ' . $message,
                'status' => 'pending',
            ];
        }

        return [
            'success' => false,
            'message' => 'Payment failed. ' . ($message !== '' ? $message : 'Status: ' . ($status !== '' ? $status : 'unknown')),
            'status' => 'failed',
        ];
    }

    /**
     * Read and validate the merchant credentials.
     *
     * @return array{0: string, 1: string, 2: string} merchant ID, password, integrity salt
     */
    private function credentials(): array
    {
        $merchantId = trim((string) $this->getConfigValue('publishable_key', '')); // Merchant ID stored in publishable_key
        $password = trim((string) $this->getConfigValue('password', '')); // Merchant password stored in password
        $salt = trim((string) $this->getConfigValue('secret_key', '')); // Integrity salt stored in secret_key

        if ($merchantId === '') {
            throw new \RuntimeException('JazzCash Merchant ID is not configured. Please check your publishable_key in the database.');
        }

        if ($password === '') {
            throw new \RuntimeException('JazzCash Password is not configured. Please check your password in the database.');
        }

        if ($salt === '') {
            throw new \RuntimeException('JazzCash Integrity Salt is not configured. Please check your secret_key in the database.');
        }

        return [$merchantId, $password, $salt];
    }

    /**
     * Endpoints for the configured environment.
     *
     * @return array<string, string>
     */
    private function endpoints(): array
    {
        $mode = strtolower((string) $this->getConfigValue('mode', 'sandbox'));

        return in_array($mode, ['production', 'prod', 'live'], true)
            ? self::ENDPOINTS['production']
            : self::ENDPOINTS['sandbox'];
    }

    /**
     * URL JazzCash posts the payment result to (pp_ReturnURL).
     *
     * @param string $orderId
     * @return string
     */
    private function returnUrl(string $orderId): string
    {
        $override = trim((string) $this->getConfigValue('return_url', ''));

        if ($override !== '') {
            return str_replace('{order_id}', rawurlencode($orderId), $override);
        }

        return route('payment-gateways.jazzcash.callback', ['orderId' => $orderId]);
    }

    /**
     * Payment description. JazzCash rejects special characters, so keep letters, digits and spaces.
     *
     * @param array $metadata
     * @param string $orderId
     * @return string
     */
    private function description(array $metadata, string $orderId): string
    {
        $description = (string) ($metadata['product_name'] ?? $metadata['description'] ?? 'Payment for order ' . $orderId);
        $description = trim((string) preg_replace('/[^A-Za-z0-9 ]/', '', $description));

        return Str::limit($description !== '' ? $description : 'Payment', 100, '');
    }

    /**
     * A merchant defined field (ppmpf_2 .. ppmpf_5) from the metadata, or its default.
     *
     * @param array $metadata
     * @param string $name
     * @param string $default
     * @return string
     */
    private function customField(array $metadata, string $name, string $default): string
    {
        return isset($metadata[$name]) && is_scalar($metadata[$name]) && (string) $metadata[$name] !== ''
            ? (string) $metadata[$name]
            : $default;
    }
}
