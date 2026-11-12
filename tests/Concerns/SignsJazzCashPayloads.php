<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Concerns;

/**
 * Builds JazzCash payloads signed the way JazzCash documents it, independently of the
 * gateway implementation: sort pp_/ppmpf_ fields by name, drop empty values, join the
 * values with "&" prefixed by the integrity salt, HMAC-SHA256 with the salt.
 */
trait SignsJazzCashPayloads
{
    private const JAZZCASH_SALT = 'test-salt';

    private function jazzcashHash(array $fields, string $salt = self::JAZZCASH_SALT): string
    {
        $names = array_keys($fields);
        sort($names, SORT_STRING);

        $message = $salt;
        foreach ($names as $name) {
            if ($name === 'pp_SecureHash' || (string) $fields[$name] === '') {
                continue;
            }
            $message .= '&' . $fields[$name];
        }

        return hash_hmac('sha256', $message, $salt);
    }

    /**
     * A response as JazzCash posts it to pp_ReturnURL, signed with the salt.
     */
    private function jazzcashResponse(string $orderId, string $txnRef, array $overrides = [], string $salt = self::JAZZCASH_SALT): array
    {
        $response = array_merge([
            'pp_Amount' => '10050',
            'pp_AuthCode' => '',
            'pp_BankID' => 'TBANK',
            'pp_BillReference' => $txnRef,
            'pp_Language' => 'EN',
            'pp_MerchantID' => 'MC0001',
            'pp_ResponseCode' => '000',
            'pp_ResponseMessage' => 'Thank you for Using JazzCash, your transaction was successful.',
            'pp_RetreivalReferenceNo' => '123456789012',
            'pp_SubMerchantId' => '',
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => '20260101120000',
            'pp_TxnRefNo' => $txnRef,
            'pp_TxnType' => 'MWALLET',
            'pp_Version' => '1.1',
            'ppmpf_1' => $orderId,
            'ppmpf_2' => '2',
            'ppmpf_3' => '3',
            'ppmpf_4' => '4',
            'ppmpf_5' => '5',
        ], $overrides);

        $response['pp_SecureHash'] = strtoupper($this->jazzcashHash($response, $salt));

        return $response;
    }
}
