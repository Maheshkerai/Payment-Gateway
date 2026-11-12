<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use MaheshKerai\PaymentGateways\Services\PaymentService;
use MaheshKerai\PaymentGateways\Contracts\PaymentGatewayInterface;

final class PaymentGatewaysServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/payment-gateways.php',
            'payment-gateways'
        );

        // Register PaymentService as singleton
        $this->app->singleton(PaymentService::class, function ($app) {
            return new PaymentService();
        });

        // Register payment gateways
        $this->registerGateways();
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/payment-gateways.php' => config_path('payment-gateways.php'),
        ], 'payment-gateways-config');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'payment-gateways');

        // Publish views
        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/payment-gateways'),
        ], 'payment-gateways-views');

        // Load routes
        $this->loadRoutes();
    }

    /**
     * Register payment gateways.
     *
     * @return void
     */
    private function registerGateways(): void
    {
        $gateways = [
            'stripe' => \MaheshKerai\PaymentGateways\Gateways\StripeGateway::class,
            'razorpay' => \MaheshKerai\PaymentGateways\Gateways\RazorpayGateway::class,
            'flutterwave' => \MaheshKerai\PaymentGateways\Gateways\FlutterwaveGateway::class,
            'paystack' => \MaheshKerai\PaymentGateways\Gateways\PaystackGateway::class,
            'paypal' => \MaheshKerai\PaymentGateways\Gateways\PayPalGateway::class,
            'midtrans' => \MaheshKerai\PaymentGateways\Gateways\MidtransGateway::class,
            'phonepe' => \MaheshKerai\PaymentGateways\Gateways\PhonePeGateway::class,
            'jazzcash' => \MaheshKerai\PaymentGateways\Gateways\JazzCashGateway::class,
        ];

        foreach ($gateways as $key => $gateway) {
            $this->app->bind("payment.gateway.{$key}", function ($app) use ($gateway) {
                return new $gateway();
            });
        }
    }

    /**
     * Load package routes.
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        Route::middleware('web')
            ->prefix('payment-gateways')
            ->group(function () {
                require __DIR__ . '/../routes/web.php';
            });
    }
}

