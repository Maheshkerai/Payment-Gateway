<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Tests\Concerns;

use Razorpay\Api\Api;

/**
 * Starts a local stand-in for Razorpay's payments API (tests/Support/razorpay-stub-router.php)
 * and points the Razorpay SDK at it, so payment verification can run end to end offline.
 */
trait RunsRazorpayStub
{
    /** @var resource|null */
    private static $razorpayStub = null;

    private static ?int $razorpayStubPort = null;

    public static function startRazorpayStub(): void
    {
        if (self::$razorpayStub !== null || !function_exists('proc_open')) {
            return;
        }

        $probe = @stream_socket_server('tcp://127.0.0.1:0');

        if ($probe === false) {
            return;
        }

        $port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        $process = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/../Support/razorpay-stub-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if ($connection !== false) {
                fclose($connection);
                self::$razorpayStub = $process;
                self::$razorpayStubPort = $port;

                return;
            }

            usleep(100000);
        }

        proc_terminate($process);
    }

    public static function stopRazorpayStub(): void
    {
        if (self::$razorpayStub !== null) {
            proc_terminate(self::$razorpayStub);
            proc_close(self::$razorpayStub);
        }

        self::$razorpayStub = null;
        self::$razorpayStubPort = null;
    }

    private function pointRazorpayAtTheStub(): void
    {
        if (self::$razorpayStubPort === null) {
            $this->markTestSkipped('Could not start the local Razorpay API stub (php -S).');
        }

        (new Api('stub', 'stub'))->setBaseUrl('http://127.0.0.1:' . self::$razorpayStubPort);
    }

    private function restoreRazorpayBaseUrl(): void
    {
        (new Api('stub', 'stub'))->setBaseUrl('https://api.razorpay.com');
    }
}
