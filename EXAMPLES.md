# Payment Gateways Package - Usage Examples

## Example 1: Simple Payment Flow

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use MaheshKerai\PaymentGateways\Services\PaymentService;

class CheckoutController extends Controller
{
    public function checkout(Request $request, PaymentService $paymentService)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'gateway' => 'required|in:stripe,razorpay,flutterwave,paystack,jazzcash',
        ]);

        $result = $paymentService->onlinePaymentMethod(
            $request->gateway,
            $request->amount,
            'USD',
            route('payment.callback'),
            [
                'user_id' => auth()->id(),
                'product_name' => 'Product Purchase',
            ]
        );

        if ($result['success']) {
            $paymentUrl = $result['payment_data']['checkout_url'] 
                ?? $result['payment_data']['authorization_url'] 
                ?? $result['payment_data']['link'];

            return redirect($paymentUrl);
        }

        return back()->with('error', $result['message']);
    }

    public function callback(Request $request, PaymentService $paymentService)
    {
        $gateway = $request->input('gateway', 'stripe');
        
        $verificationData = $this->getVerificationData($request, $gateway);
        $verification = $paymentService->verifyPayment($gateway, $verificationData);

        if ($verification['success']) {
            // Update order status
            // Send confirmation email
            return redirect()->route('order.success');
        }

        return redirect()->route('order.failed');
    }

    private function getVerificationData(Request $request, string $gateway): array
    {
        return match($gateway) {
            'stripe' => ['session_id' => $request->input('session_id')],
            'razorpay' => [
                'razorpay_order_id' => $request->input('razorpay_order_id'),
                'razorpay_payment_id' => $request->input('razorpay_payment_id'),
                'razorpay_signature' => $request->input('razorpay_signature'),
            ],
            'flutterwave' => [
                'tx_ref' => $request->input('tx_ref'),
                'transaction_id' => $request->input('transaction_id'),
            ],
            'paystack' => ['reference' => $request->input('reference')],
            'jazzcash' => ['order_id' => $request->input('order_id')],
            default => [],
        };
    }
}
```

## Example 2: Using Helper Functions

```php
<?php

// Initialize payment
$result = onlinePaymentMethod(
    'stripe',
    100.00,
    'USD',
    'https://yourdomain.com/payment/callback',
    [
        'user_id' => auth()->id(),
        'order_id' => 123,
        'product_name' => 'Subscription',
    ]
);

// Verify payment
$verification = verifyPayment('stripe', [
    'session_id' => $request->input('session_id'),
]);
```

## Example 3: Subscription Payment

```php
<?php

namespace App\Http\Controllers;

use MaheshKerai\PaymentGateways\Services\PaymentService;

class SubscriptionController extends Controller
{
    public function subscribe(PaymentService $paymentService)
    {
        $result = $paymentService->onlinePaymentMethod(
            'stripe',
            29.99, // Monthly subscription
            'USD',
            route('subscription.callback'),
            [
                'user_id' => auth()->id(),
                'product_name' => 'Monthly Subscription',
                'subscription_type' => 'monthly',
                'customer_email' => auth()->user()->email,
            ]
        );

        if ($result['success']) {
            return redirect($result['payment_data']['checkout_url']);
        }

        return back()->with('error', 'Failed to initialize payment');
    }
}
```

## Example 4: E-commerce Payment

```php
<?php

namespace App\Http\Controllers;

use MaheshKerai\PaymentGateways\Services\PaymentService;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;

class OrderController extends Controller
{
    public function processPayment(Request $request, PaymentService $paymentService)
    {
        $order = Order::find($request->order_id);
        
        $result = $paymentService->onlinePaymentMethod(
            $request->payment_gateway,
            $order->total,
            $order->currency,
            route('order.payment.callback', $order->id),
            [
                'user_id' => auth()->id(),
                'order_id' => $order->id,
                'product_name' => 'Order #' . $order->id,
                'customer_email' => auth()->user()->email,
                'customer_name' => auth()->user()->name,
            ]
        );

        if ($result['success']) {
            $order->update([
                'transaction_id' => $result['transaction_id'],
                'payment_status' => 'pending',
            ]);

            return redirect($result['payment_data']['checkout_url']);
        }

        return back()->with('error', $result['message']);
    }

    public function paymentCallback(Request $request, $orderId, PaymentService $paymentService)
    {
        $order = Order::findOrFail($orderId);
        $transaction = PaymentTransaction::where('order_id', $order->transaction_id)->first();

        $verification = $paymentService->verifyPayment(
            $transaction->paymentGateway->name,
            $request->all()
        );

        if ($verification['success']) {
            $order->update(['payment_status' => 'paid']);
            // Send order confirmation email
            // Update inventory
            return redirect()->route('order.success', $order->id);
        }

        return redirect()->route('order.failed', $order->id);
    }
}
```

## Example 5: API Integration

```php
<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use MaheshKerai\PaymentGateways\Services\PaymentService;

class PaymentApiController extends Controller
{
    public function initialize(Request $request, PaymentService $paymentService)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'gateway' => 'required|string|in:stripe,razorpay,flutterwave,paystack,jazzcash',
            'callback_url' => 'required|url',
        ]);

        $result = $paymentService->onlinePaymentMethod(
            $request->gateway,
            $request->amount,
            $request->currency,
            $request->callback_url,
            [
                'user_id' => auth()->id(),
            ]
        );

        return response()->json($result);
    }

    public function verify(Request $request, PaymentService $paymentService)
    {
        $request->validate([
            'gateway' => 'required|string',
        ]);

        $verification = $paymentService->verifyPayment(
            $request->gateway,
            $request->except('gateway')
        );

        return response()->json($verification);
    }
}
```

## Example 6: Transaction History

```php
<?php

namespace App\Http\Controllers;

use MaheshKerai\PaymentGateways\Models\PaymentTransaction;

class TransactionController extends Controller
{
    public function index()
    {
        $transactions = PaymentTransaction::where('user_id', auth()->id())
            ->with('paymentGateway')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('transactions.index', compact('transactions'));
    }

    public function show($id)
    {
        $transaction = PaymentTransaction::where('id', $id)
            ->where('user_id', auth()->id())
            ->with('paymentGateway')
            ->firstOrFail();

        return view('transactions.show', compact('transaction'));
    }
}
```

## Example 7: Admin - Gateway Management

```php
<?php

namespace App\Http\Controllers\Admin;

use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    public function index()
    {
        $gateways = PaymentGateway::all();
        return view('admin.gateways.index', compact('gateways'));
    }

    public function update(Request $request, $id)
    {
        $gateway = PaymentGateway::findOrFail($id);

        $gateway->update([
            'secret_key' => $request->secret_key,
            'publishable_key' => $request->publishable_key,
            'webhook_secret' => $request->webhook_secret,
            'webhook_url' => $request->webhook_url,
            'status' => $request->status,
        ]);

        return back()->with('success', 'Gateway updated successfully');
    }
}
```

