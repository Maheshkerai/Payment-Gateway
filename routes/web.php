<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use MaheshKerai\PaymentGateways\Http\Controllers\WebhookController;
use MaheshKerai\PaymentGateways\Http\Controllers\PaymentController;

/*
|--------------------------------------------------------------------------
| Payment Gateways Webhook Routes
|--------------------------------------------------------------------------
|
| These routes handle webhook callbacks from payment gateways.
| They are prefixed with 'payment-gateways' and CSRF protection
| is disabled for webhook endpoints.
|
*/

Route::post('/webhooks/{gateway}', [WebhookController::class, 'handle'])
    ->name('payment-gateways.webhook')
    ->withoutMiddleware(['\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken']);

/*
|--------------------------------------------------------------------------
| Payment Gateways Payment Routes
|--------------------------------------------------------------------------
|
| These routes handle payment initialization and callbacks.
|
*/

// Razorpay routes
Route::get('/razorpay/form', [PaymentController::class, 'razorpayForm'])
    ->name('payment-gateways.razorpay.form');

Route::post('/razorpay/callback', [PaymentController::class, 'razorpayCallback'])
    ->name('payment-gateways.razorpay.callback');

// PayPal routes
Route::get('/paypal/callback', [PaymentController::class, 'paypalCallback'])
    ->name('payment-gateways.paypal.callback');

// JazzCash routes
Route::get('/jazzcash/checkout/{orderId}', [PaymentController::class, 'jazzcashCheckout'])
    ->name('payment-gateways.jazzcash.checkout');

// JazzCash posts the result here from its own domain. A cross-site POST carries no session
// cookie, so starting a session here would replace the customer's login cookie; the payload is
// authenticated by its secure hash instead of CSRF/session.
Route::post('/jazzcash/callback/{orderId}', [PaymentController::class, 'jazzcashCallback'])
    ->name('payment-gateways.jazzcash.callback')
    ->withoutMiddleware([
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class, // Laravel 13+
    ]);

/*
|--------------------------------------------------------------------------
| Unified Payment Callback Route
|--------------------------------------------------------------------------
|
| This route handles payment success/cancel/failed callbacks for all gateways.
| It retrieves the transaction from database using order_id.
|
*/

Route::get('/callback', [PaymentController::class, 'callback'])
    ->name('payment-gateways.callback');

Route::post('/callback', [PaymentController::class, 'callback'])
    ->name('payment-gateways.callback.post');

/*
|--------------------------------------------------------------------------
| Payment Gateway Management Routes
|--------------------------------------------------------------------------
|
| These routes handle read and update operations for payment gateways.
| All gateways are created via seeder, so only update is allowed.
| These routes require authentication to protect sensitive payment gateway data.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/gateways', [\MaheshKerai\PaymentGateways\Http\Controllers\PaymentGatewayController::class, 'index'])
        ->name('payment-gateways.index');

    Route::put('/gateways/{name}', [\MaheshKerai\PaymentGateways\Http\Controllers\PaymentGatewayController::class, 'update'])
        ->name('payment-gateways.update');
});