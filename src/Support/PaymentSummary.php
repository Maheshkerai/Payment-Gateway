<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Support;

use Illuminate\Support\Str;

/**
 * Turns the metadata a host application passes with a payment into display-ready data
 * for the Razorpay payment page and receipt (system name and logo, product, customer and
 * any further detail rows).
 *
 * Metadata comes from query-string parameters, so everything is treated as untrusted: values
 * are reduced to short plain strings, URLs are limited to http(s) or relative paths, colours
 * must be hex, and anything that looks like a credential is never displayed.
 */
final class PaymentSummary
{
    /**
     * Keys that are used by the package itself or shown elsewhere, so never listed as detail rows.
     *
     * @var array<int, string>
     */
    private const RESERVED_KEYS = [
        'amount', 'currency', 'callback_url', 'transaction_id', 'order_id', 'payment_id', 'status', 'message',
        'user_id', 'merchant_name', 'merchant_logo', 'product_name', 'product_image', 'description',
        'customer_name', 'customer_email', 'customer_phone', 'name', 'email', 'phone',
        'button_text', 'theme_color', 'metadata', 'page',
    ];

    /**
     * Words that mark a key as holding a secret, wherever they appear in it ("apikey", "client_secret").
     *
     * @var array<int, string>
     */
    private const SENSITIVE_FRAGMENTS = ['secret', 'password', 'passwd', 'token', 'signature', 'credential', 'cvv', 'apikey'];

    /**
     * Words that mark a key as sensitive only when they are a whole word of it ("api_key", but not "shipping").
     *
     * @var array<int, string>
     */
    private const SENSITIVE_WORDS = ['key', 'hash', 'salt', 'pass', 'otp', 'pin', 'card', 'iban', 'account'];

    private const DEFAULT_THEME_COLOR = '#ff7529';

    private const MAX_DETAIL_ROWS = 8;

    private const SYMBOLS = [
        'INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AUD' => 'A$', 'CAD' => 'C$',
        'SGD' => 'S$', 'AED' => 'AED ', 'MYR' => 'RM ', 'JPY' => '¥',
    ];

    /**
     * Cache key under which the summary of a payment is kept until its receipt is shown.
     *
     * @param int|string $transactionId
     * @return string
     */
    public static function cacheKey(int|string $transactionId): string
    {
        return 'payment-gateways:razorpay:summary:' . $transactionId;
    }

    /**
     * Build the display summary from payment metadata.
     *
     * @param array $metadata
     * @param string $fallbackName name shown when the metadata carries no merchant_name
     * @return array{
     *     merchant_name: string, merchant_logo: string, product_name: string, product_image: string,
     *     description: string, customer_name: string, customer_email: string, customer_phone: string,
     *     theme_color: string, accent_rgb: string, accent_text: string,
     *     details: array<int, array{label: string, value: string}>
     * }
     */
    public static function fromMetadata(array $metadata, string $fallbackName = ''): array
    {
        $themeColor = self::color($metadata['theme_color'] ?? null);

        return [
            'merchant_name' => self::text($metadata['merchant_name'] ?? null, 80) ?: self::text($fallbackName, 80),
            'merchant_logo' => self::url($metadata['merchant_logo'] ?? null),
            'product_name' => self::text($metadata['product_name'] ?? null, 120),
            'product_image' => self::url($metadata['product_image'] ?? null),
            'description' => self::text($metadata['description'] ?? null, 300),
            'customer_name' => self::text($metadata['customer_name'] ?? null, 80),
            'customer_email' => self::text($metadata['customer_email'] ?? null, 120),
            'customer_phone' => self::text($metadata['customer_phone'] ?? null, 30),
            'theme_color' => $themeColor,
            'accent_rgb' => self::rgb($themeColor),
            'accent_text' => self::readableTextColor($themeColor),
            'details' => self::details($metadata),
        ];
    }

    /**
     * Currency symbol for display, or the currency code followed by a space.
     *
     * @param string $currency
     * @return string
     */
    public static function currencySymbol(string $currency): string
    {
        $currency = strtoupper($currency);

        return self::SYMBOLS[$currency] ?? ($currency !== '' ? $currency . ' ' : '');
    }

    /**
     * Format an amount for display, e.g. "₹1,500.00".
     *
     * @param float|int|string $amount
     * @param string $currency
     * @return string
     */
    public static function formatAmount(float|int|string $amount, string $currency): string
    {
        return self::currencySymbol($currency) . number_format((float) $amount, 2);
    }

    /**
     * Extra metadata shown as label/value rows, e.g. "billing_cycle" => "Billing Cycle: Monthly".
     *
     * @param array $metadata
     * @return array<int, array{label: string, value: string}>
     */
    private static function details(array $metadata): array
    {
        $rows = [];

        foreach ($metadata as $key => $value) {
            if (count($rows) >= self::MAX_DETAIL_ROWS) {
                break;
            }

            if (!is_string($key) || !is_scalar($value) || is_bool($value)) {
                continue;
            }

            $normalised = strtolower($key);

            if (
                in_array($normalised, self::RESERVED_KEYS, true)
                || str_starts_with($normalised, 'razorpay_')
                || str_starts_with($normalised, '_')
                || self::isSensitiveKey($normalised)
            ) {
                continue;
            }

            $text = self::text($value, 160);

            if ($text === '') {
                continue;
            }

            $rows[] = ['label' => Str::headline($key), 'value' => $text];
        }

        return $rows;
    }

    /**
     * Whether a (lower-case) metadata key looks like it holds a secret or sensitive personal data.
     *
     * @param string $key
     * @return bool
     */
    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return array_intersect(preg_split('/[^a-z0-9]+/', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [], self::SENSITIVE_WORDS) !== [];
    }

    /**
     * A short single-line plain string (control characters and surrounding space removed).
     *
     * @param mixed $value
     * @param int $max
     * @return string
     */
    private static function text(mixed $value, int $max): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));

        // The ellipsis counts towards the limit, so the result is never longer than $max characters.
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * An http(s) URL or a site-relative path; anything else (javascript:, data:, //host) is dropped.
     *
     * @param mixed $value
     * @return string
     */
    private static function url(mixed $value): string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return '';
        }

        $url = trim((string) $value);

        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20"\'<>\\\\]/', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
    }

    /**
     * A #rgb or #rrggbb colour, or the default accent.
     *
     * @param mixed $value
     * @return string
     */
    private static function color(mixed $value): string
    {
        $color = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1
            ? strtolower($color)
            : self::DEFAULT_THEME_COLOR;
    }

    /**
     * "r, g, b" of a hex colour, for use in rgba().
     *
     * @param string $hex
     * @return string
     */
    private static function rgb(string $hex): string
    {
        [$r, $g, $b] = self::channels($hex);

        return "{$r}, {$g}, {$b}";
    }

    /**
     * White or near-black, whichever reads better on the given background colour.
     *
     * @param string $hex
     * @return string
     */
    private static function readableTextColor(string $hex): string
    {
        [$r, $g, $b] = self::channels($hex);

        // Perceived brightness (ITU-R BT.601).
        return (($r * 299) + ($g * 587) + ($b * 114)) / 1000 > 160 ? '#111827' : '#ffffff';
    }

    /**
     * @param string $hex
     * @return array{0: int, 1: int, 2: int}
     */
    private static function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
