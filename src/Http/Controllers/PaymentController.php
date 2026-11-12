<?php

declare(strict_types=1);

namespace MaheshKerai\PaymentGateways\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use MaheshKerai\PaymentGateways\Gateways\JazzCashGateway;
use MaheshKerai\PaymentGateways\Services\PaymentService;
use MaheshKerai\PaymentGateways\Models\PaymentTransaction;
use MaheshKerai\PaymentGateways\Models\PaymentGateway;
use MaheshKerai\PaymentGateways\Support\PaymentSummary;

final class PaymentController extends Controller
{
    /**
     * Display Razorpay payment form.
     *
     * @param Request $request
     * @param PaymentService $paymentService
     * @return View|RedirectResponse
     */
    public function razorpayForm(Request $request, PaymentService $paymentService)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'callback_url' => 'required|url',
        ]);

        // Check if transaction_id is provided (from previous initialization)
        $existingTransactionId = $request->input('transaction_id');
        $existingOrderId = $request->input('order_id');
        
        $result = null;
        $transaction = null;
        
        // If transaction_id is provided, retrieve existing transaction instead of creating new one
        if ($existingTransactionId) {
            $transaction = PaymentTransaction::find($existingTransactionId);
            if ($transaction && $transaction->status === 'pending') {
                // Get gateway for publishable key
                $gateway = PaymentGateway::find($transaction->payment_gateway_id);
                
                // Use existing transaction - payment_id should already be set (Razorpay order ID)
                if ($transaction->payment_id) {
                    $result = [
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'order_id' => $transaction->order_id,
                        'payment_data' => [
                            'payment_id' => $transaction->payment_id,
                            'order_id' => $transaction->payment_id,
                            'razorpay_order_id' => $transaction->payment_id,
                            'key' => $gateway->publishable_key ?? '',
                            'amount' => $transaction->amount,
                            'currency' => $request->currency ?? 'INR',
                        ],
                    ];
                } else {
                    // Transaction exists but no payment_id yet - shouldn't happen but handle it
                    // Fall through to create new one
                    $transaction = null;
                }
            }
        } elseif ($existingOrderId) {
            // Try to find by order_id
            $transaction = PaymentTransaction::where('order_id', $existingOrderId)
                ->where('status', 'pending')
                ->first();
            if ($transaction) {
                $gateway = PaymentGateway::find($transaction->payment_gateway_id);
                
                if ($transaction->payment_id) {
                    $result = [
                        'success' => true,
                        'transaction_id' => $transaction->id,
                        'order_id' => $transaction->order_id,
                        'payment_data' => [
                            'payment_id' => $transaction->payment_id,
                            'order_id' => $transaction->payment_id,
                            'razorpay_order_id' => $transaction->payment_id,
                            'key' => $gateway->publishable_key ?? '',
                            'amount' => $transaction->amount,
                            'currency' => $request->currency ?? 'INR',
                        ],
                    ];
                } else {
                    // Transaction exists but no payment_id yet - shouldn't happen but handle it
                    $transaction = null;
                }
            }
        }
        
        // If no existing transaction found, create a new one
        if (!$result) {
            $result = $paymentService->onlinePaymentMethod(
                'razorpay',
                (float) $request->amount, // Cast to float since query params are strings
                $request->currency,
                $request->callback_url,
                array_merge($request->except(['amount', 'currency', 'callback_url', 'transaction_id', 'order_id']), [
                    'user_id' => auth()->id(),
                ])
            );
        }

        if (!$result['success']) {
            return back()->with('error', $result['message'] ?? 'Failed to initialize payment');
        }

        $paymentData = $result['payment_data'];
        
        // Prepare data for Razorpay checkout
        $amount = (float) ($paymentData['amount'] ?? $request->amount);
        $checkoutData = [
            'key' => $paymentData['key'] ?? '',
            'amount' => $amount,
            'amount_in_paise' => (int)($amount * 100),
            'currency' => $paymentData['currency'] ?? $request->currency,
            'order_id' => $result['order_id'],
            'razorpay_order_id' => $paymentData['order_id'] ?? $paymentData['payment_id'] ?? '',
            'merchant_name' => $request->input('merchant_name', config('app.name')),
            'description' => $request->input('description', 'Payment'),
            'merchant_logo' => $request->input('merchant_logo', ''),
            'customer_name' => $request->input('customer_name', auth()->user()->name ?? ''),
            'customer_email' => $request->input('customer_email', auth()->user()->email ?? ''),
            'customer_phone' => $request->input('customer_phone', auth()->user()->phone ?? ''),
            'button_text' => $request->input('button_text', 'Pay Now'),
            'theme_color' => $request->input('theme_color', '#ff7529'),
        ];

        // Display data (system name/logo, product, extra detail rows) taken from the payment metadata.
        // Kept per transaction so the receipt can show the same details after the payment succeeds.
        $summary = PaymentSummary::fromMetadata($request->all(), (string) config('app.name'));
        $summary['customer_name'] = $summary['customer_name'] !== '' ? $summary['customer_name'] : (string) $checkoutData['customer_name'];
        $summary['customer_email'] = $summary['customer_email'] !== '' ? $summary['customer_email'] : (string) $checkoutData['customer_email'];
        Cache::put(PaymentSummary::cacheKey($result['transaction_id']), $summary, now()->addHours(3));

        return view('payment-gateways::razorpay-checkout', [
            'paymentData' => $checkoutData,
            'transactionId' => $result['transaction_id'],
            'callbackUrl' => $request->callback_url,
            'summary' => $summary,
            'orderDate' => now(),
        ]);
    }

    /**
     * Handle Razorpay payment callback.
     *
     * @param Request $request
     * @param PaymentService $paymentService
     * @return View|RedirectResponse
     */
    public function razorpayCallback(Request $request, PaymentService $paymentService): View|RedirectResponse
    {
        $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $verification = $paymentService->verifyPayment('razorpay', [
            'razorpay_order_id' => $request->razorpay_order_id,
            'razorpay_payment_id' => $request->razorpay_payment_id,
            'razorpay_signature' => $request->razorpay_signature,
        ]);

        $callbackUrl = $request->input('callback_url', route('payment-gateways.callback'));

        if ($verification['success']) {
            // Update transaction with actual payment_id (pay_xxx) and signature
            // Use a more specific query to prevent matching multiple transactions
            $transaction = PaymentTransaction::where('order_id', $request->razorpay_order_id)->first();
            
            // If not found by order_id, check payment_id (for cases where payment_id was set to order_id initially)
            if (!$transaction) {
                $transaction = PaymentTransaction::where('payment_id', $request->razorpay_order_id)
                    ->where('status', 'pending') // Only match pending transactions to prevent duplicates
                    ->first();
            }

            if ($transaction) {
                // Only update if status is still pending to prevent duplicate updates
                if ($transaction->status === 'pending') {
                    $transaction->update([
                        'payment_id' => $request->razorpay_payment_id, // Update with actual payment ID (pay_xxx)
                        'payment_signature' => $request->razorpay_signature,
                        'status' => 'success',
                    ]);
                }
            }

            // Parse existing callback URL to preserve metadata and transaction_id
            $parsedUrl = parse_url($callbackUrl);
            $queryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
            }

            // Add/update gateway-specific parameters
            $queryParams['order_id'] = $request->razorpay_order_id;
            $queryParams['status'] = 'success';
            $queryParams['payment_id'] = $request->razorpay_payment_id;

            // Ensure transaction_id is included (from existing params or from transaction)
            if (!isset($queryParams['transaction_id']) && $transaction) {
                $queryParams['transaction_id'] = $transaction->id;
            }

            // Rebuild callback URL with all parameters
            $baseUrl = '';
            if (isset($parsedUrl['scheme'])) {
                $baseUrl = $parsedUrl['scheme'] . '://';
            }
            if (isset($parsedUrl['host'])) {
                $baseUrl .= $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $baseUrl .= ':' . $parsedUrl['port'];
                }
            }
            $baseUrl .= ($parsedUrl['path'] ?? '');
            $redirectUrl = $baseUrl . '?' . http_build_query($queryParams);

            // Show a payment receipt first (it continues to the callback URL by itself) unless disabled.
            // PaymentService has already replaced the transaction's payment_id (order_xxx) with the
            // payment ID (pay_xxx) during verification, so look it up by that when not found above.
            $receiptTransaction = $transaction
                ?: PaymentTransaction::where('payment_id', $request->razorpay_payment_id)->first();

            if ($receiptTransaction && config('payment-gateways.receipt.enabled', true)) {
                return view('payment-gateways::razorpay-receipt', [
                    'transaction' => $receiptTransaction->fresh() ?? $receiptTransaction,
                    'verification' => $verification,
                    'summary' => Cache::get(PaymentSummary::cacheKey($receiptTransaction->id))
                        ?? PaymentSummary::fromMetadata([], (string) config('app.name')),
                    'redirectUrl' => $redirectUrl,
                    'redirectAfter' => (int) config('payment-gateways.receipt.redirect_after', 5),
                ]);
            }

            return redirect($redirectUrl);
        }

        // Update transaction status to failed
        $transaction = PaymentTransaction::where('order_id', $request->razorpay_order_id)->first();
        
        // If not found by order_id, check payment_id
        if (!$transaction) {
            $transaction = PaymentTransaction::where('payment_id', $request->razorpay_order_id)
                ->where('status', 'pending')
                ->first();
        }

        if ($transaction && $transaction->status === 'pending') {
            $transaction->update([
                'status' => 'failed',
            ]);
        }

        // Parse existing callback URL to preserve metadata and transaction_id
        $parsedUrl = parse_url($callbackUrl);
        $queryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        // Add/update gateway-specific parameters
        $queryParams['order_id'] = $request->razorpay_order_id;
        $queryParams['status'] = 'failed';
        $queryParams['payment_id'] = $request->razorpay_payment_id;
        if (isset($verification['message'])) {
            $queryParams['message'] = $verification['message'];
        }

        // Ensure transaction_id is included
        if (!isset($queryParams['transaction_id']) && $transaction) {
            $queryParams['transaction_id'] = $transaction->id;
        }

        // Rebuild callback URL with all parameters
        $baseUrl = '';
        if (isset($parsedUrl['scheme'])) {
            $baseUrl = $parsedUrl['scheme'] . '://';
        }
        if (isset($parsedUrl['host'])) {
            $baseUrl .= $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
        }
        $baseUrl .= ($parsedUrl['path'] ?? '');
        $redirectUrl = $baseUrl . '?' . http_build_query($queryParams);

        return redirect($redirectUrl);
    }

    /**
     * Handle PayPal payment callback.
     *
     * @param Request $request
     * @param PaymentService $paymentService
     * @return RedirectResponse
     */
    public function paypalCallback(Request $request, PaymentService $paymentService): RedirectResponse
    {
        $request->validate([
            'token' => 'nullable|string', // PayPal sends order ID as 'token'
            'status' => 'nullable|string|in:success,cancelled',
        ]);

        // PayPal sends the order ID as 'token' parameter
        $token = $request->input('token');
        
        // If token is not provided, try to get it from PayerID or other PayPal parameters
        if (!$token) {
            // PayPal might send order ID in different ways, check all possibilities
            $token = $request->input('orderId') ?? $request->input('order_id');
        }

        if (!$token) {
            return redirect()->back()->with('error', 'PayPal order ID is missing.');
        }

        $status = $request->input('status', 'success');
        $callbackUrl = $request->input('callback_url', route('payment-gateways.callback'));

        if ($status === 'cancelled') {
            // Find transaction by payment_id (which should be the PayPal order ID)
            $transaction = PaymentTransaction::where('payment_id', $token)->first();
            
            // If not found by payment_id, check order_id
            if (!$transaction) {
                $transaction = PaymentTransaction::where('order_id', $token)
                    ->where('status', 'pending')
                    ->first();
            }

            if ($transaction && $transaction->status === 'pending') {
                // payment_transactions.status enum is pending|success|failed|refunded.
                // Map cancelled → failed for persistence; the redirect URL still
                // reports 'cancelled' to the calling app for UI purposes.
                $transaction->update([
                    'status' => 'failed',
                ]);
            }

            // Parse existing callback URL to preserve metadata and transaction_id
            $parsedUrl = parse_url($callbackUrl);
            $queryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
            }

            $queryParams['order_id'] = $token;
            $queryParams['status'] = 'cancelled';

            if (!isset($queryParams['transaction_id']) && $transaction) {
                $queryParams['transaction_id'] = $transaction->id;
            }

            // Rebuild callback URL
            $baseUrl = '';
            if (isset($parsedUrl['scheme'])) {
                $baseUrl = $parsedUrl['scheme'] . '://';
            }
            if (isset($parsedUrl['host'])) {
                $baseUrl .= $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $baseUrl .= ':' . $parsedUrl['port'];
                }
            }
            $baseUrl .= ($parsedUrl['path'] ?? '');
            $redirectUrl = $baseUrl . '?' . http_build_query($queryParams);

            return redirect($redirectUrl);
        }

        // Verify payment using token (which is the PayPal order ID)
        $verification = $paymentService->verifyPayment('paypal', [
            'token' => $token,
            'order_id' => $token,
            'payment_id' => $token,
        ]);

        if ($verification['success']) {
            // Update transaction - try payment_id first (PayPal order ID)
            $transaction = PaymentTransaction::where('payment_id', $token)->first();
            
            // If not found by payment_id, check order_id
            if (!$transaction) {
                $transaction = PaymentTransaction::where('order_id', $token)
                    ->where('status', 'pending')
                    ->first();
            }

            if ($transaction && $transaction->status === 'pending') {
                $transaction->update([
                    'payment_id' => $verification['payment_id'] ?? $token,
                    'status' => 'success',
                ]);
            }

            // Parse existing callback URL to preserve metadata and transaction_id
            $parsedUrl = parse_url($callbackUrl);
            $queryParams = [];
            if (isset($parsedUrl['query'])) {
                parse_str($parsedUrl['query'], $queryParams);
            }

            // Add/update gateway-specific parameters
            $queryParams['order_id'] = $token;
            $queryParams['status'] = 'success';
            if (isset($verification['payment_id'])) {
                $queryParams['payment_id'] = $verification['payment_id'];
            }

            // Ensure transaction_id is included
            if (!isset($queryParams['transaction_id']) && $transaction) {
                $queryParams['transaction_id'] = $transaction->id;
            }

            // Rebuild callback URL
            $baseUrl = '';
            if (isset($parsedUrl['scheme'])) {
                $baseUrl = $parsedUrl['scheme'] . '://';
            }
            if (isset($parsedUrl['host'])) {
                $baseUrl .= $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $baseUrl .= ':' . $parsedUrl['port'];
                }
            }
            $baseUrl .= ($parsedUrl['path'] ?? '');
            $redirectUrl = $baseUrl . '?' . http_build_query($queryParams);

            return redirect($redirectUrl);
        }

        // Update transaction status to failed
        $transaction = PaymentTransaction::where('payment_id', $token)->first();
        
        // If not found by payment_id, check order_id
        if (!$transaction) {
            $transaction = PaymentTransaction::where('order_id', $token)
                ->where('status', 'pending')
                ->first();
        }

        if ($transaction && $transaction->status === 'pending') {
            $transaction->update([
                'status' => 'failed',
            ]);
        }

        // Parse existing callback URL to preserve metadata and transaction_id
        $parsedUrl = parse_url($callbackUrl);
        $queryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        // Add/update gateway-specific parameters
        $queryParams['order_id'] = $token;
        $queryParams['status'] = 'failed';
        if (isset($verification['message'])) {
            $queryParams['message'] = $verification['message'];
        }

        // Ensure transaction_id is included
        if (!isset($queryParams['transaction_id']) && $transaction) {
            $queryParams['transaction_id'] = $transaction->id;
        }

        // Rebuild callback URL
        $baseUrl = '';
        if (isset($parsedUrl['scheme'])) {
            $baseUrl = $parsedUrl['scheme'] . '://';
        }
        if (isset($parsedUrl['host'])) {
            $baseUrl .= $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
        }
        $baseUrl .= ($parsedUrl['path'] ?? '');
        $redirectUrl = $baseUrl . '?' . http_build_query($queryParams);

        return redirect($redirectUrl);
    }

    /**
     * Auto-submit the signed JazzCash payment form.
     * JazzCash only accepts a POST, so the checkout_url returned by the gateway lands here.
     *
     * @param Request $request
     * @param string $orderId
     * @return View
     */
    public function jazzcashCheckout(Request $request, string $orderId): View
    {
        abort_unless($request->hasValidSignature(), 403, 'Invalid or expired payment link.');

        $checkout = Cache::get(JazzCashGateway::checkoutCacheKey($orderId));

        abort_unless(
            is_array($checkout) && !empty($checkout['endpoint']) && !empty($checkout['fields']),
            410,
            'Payment session expired. Please start the payment again.'
        );

        return view('payment-gateways::jazzcash-checkout', [
            'endpoint' => $checkout['endpoint'],
            'fields' => $checkout['fields'],
        ]);
    }

    /**
     * Handle the JazzCash payment result (pp_ReturnURL).
     * The payload is only trusted when its secure hash verifies and it belongs to this order;
     * anything else is rejected without touching the transaction.
     *
     * @param Request $request
     * @param PaymentService $paymentService
     * @param string $orderId
     * @return RedirectResponse
     */
    public function jazzcashCallback(Request $request, PaymentService $paymentService, string $orderId): RedirectResponse
    {
        $callbackUrl = Cache::get(JazzCashGateway::callbackCacheKey($orderId)) ?: route('payment-gateways.callback');

        $payload = $request->all();
        $gateway = PaymentGateway::where('name', 'jazzcash')->where('status', 1)->first();

        if (!$gateway || !JazzCashGateway::isAuthenticCallback($payload, (string) $gateway->secret_key, $orderId)) {
            return $this->redirectToCallbackUrl($callbackUrl, $orderId, 'failed', [
                'message' => 'Invalid JazzCash secure hash.',
            ]);
        }

        // Pending payments are settled later (IPN or status check); do not mark them failed.
        $result = JazzCashGateway::interpretResponse($payload);
        if (($result['status'] ?? null) === 'pending') {
            return $this->redirectToCallbackUrl($callbackUrl, $orderId, 'pending', $result);
        }

        $verification = $paymentService->verifyPayment('jazzcash', array_merge($payload, [
            'order_id' => $orderId,
        ]));

        return $this->redirectToCallbackUrl($callbackUrl, $orderId, $verification['success'] ? 'success' : 'failed', $verification);
    }

    /**
     * Redirect to the callback URL given when the payment was initialized,
     * preserving its query string (metadata, transaction_id) and adding the payment outcome.
     *
     * @param string $callbackUrl
     * @param string $orderId
     * @param string $status
     * @param array $verification
     * @return RedirectResponse
     */
    private function redirectToCallbackUrl(string $callbackUrl, string $orderId, string $status, array $verification): RedirectResponse
    {
        $transaction = PaymentTransaction::where('order_id', $orderId)->first();

        // Parse existing callback URL to preserve metadata and transaction_id
        $parsedUrl = parse_url($callbackUrl);
        $queryParams = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        $queryParams['order_id'] = $orderId;
        $queryParams['status'] = $status;
        if (isset($verification['payment_id'])) {
            $queryParams['payment_id'] = $verification['payment_id'];
        }
        if ($status !== 'success' && isset($verification['message'])) {
            $queryParams['message'] = $verification['message'];
        }

        // Ensure transaction_id is included
        if (!isset($queryParams['transaction_id']) && $transaction) {
            $queryParams['transaction_id'] = $transaction->id;
        }

        // Rebuild callback URL
        $baseUrl = '';
        if (isset($parsedUrl['scheme'])) {
            $baseUrl = $parsedUrl['scheme'] . '://';
        }
        if (isset($parsedUrl['host'])) {
            $baseUrl .= $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $baseUrl .= ':' . $parsedUrl['port'];
            }
        }
        $baseUrl .= ($parsedUrl['path'] ?? '');

        return redirect($baseUrl . '?' . http_build_query($queryParams));
    }

    /**
     * Handle unified payment callback (success/cancel/failed).
     * Retrieves transaction from database using order_id.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|View|RedirectResponse
     */
    public function callback(Request $request)
    {
        $request->validate([
            'order_id' => 'required|string',
            'status' => 'nullable|string|in:success,failed,cancelled',
        ]);

        $orderId = $request->input('order_id');
        
        $status = $request->input('status', 'pending');
        
        // Check if this is a redirect from Midtrans with original_callback_url
        // If so, we need to redirect to the original callback URL with all metadata preserved
        // Laravel automatically URL-decodes query parameters, so this should be the decoded URL
        $originalCallbackUrl = $request->input('original_callback_url');
        
        // Ensure the URL is properly decoded (Laravel should handle this automatically, but decode again if needed)
        if ($originalCallbackUrl) {
            // Laravel automatically URL-decodes query parameters, but double-decode if needed
            // Sometimes URLs can be double-encoded, so we decode until no more % encoding is found
            $decodedUrl = $originalCallbackUrl;
            $previousUrl = '';
            while ($decodedUrl !== $previousUrl && strpos($decodedUrl, '%') !== false) {
                $previousUrl = $decodedUrl;
                $decodedUrl = urldecode($decodedUrl);
            }
            $originalCallbackUrl = $decodedUrl;
            
            // Validate that it's a proper URL or relative path
            $isValidUrl = filter_var($originalCallbackUrl, FILTER_VALIDATE_URL) !== false;
            $isRelativePath = str_starts_with($originalCallbackUrl, '/');
            
            if (!$isValidUrl && !$isRelativePath) {
                // If it's not a valid URL and not a relative path, log error and fall back
                \Log::error('Invalid original_callback_url received', [
                    'url' => $originalCallbackUrl,
                    'raw_input' => $request->input('original_callback_url'),
                    'all_inputs' => $request->all()
                ]);
                $originalCallbackUrl = null;
            }
        }

        // Retrieve transaction from database
        $transaction = PaymentTransaction::where('order_id', $orderId)
            ->with(['paymentGateway', 'user'])
            ->first();

        if (!$transaction) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaction not found.',
                ], 404);
            }

            return redirect()->back()->with('error', 'Transaction not found.');
        }

        // Update transaction status if provided and different.
        // payment_transactions.status enum is pending|success|failed|refunded — map
        // 'cancelled' → 'failed' for DB persistence while keeping the original value
        // for the user-facing redirect.
        $dbStatus = $status === 'cancelled' ? 'failed' : $status;
        if ($dbStatus !== 'pending' && $transaction->status !== $dbStatus && $transaction->status === 'pending') {
            $transaction->update(['status' => $dbStatus]);
        }

        // Prepare response data
        $responseData = [
            'success' => $transaction->isSuccessful(),
            'transaction' => [
                'id' => $transaction->id,
                'order_id' => $transaction->order_id,
                'payment_id' => $transaction->payment_id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'payment_gateway' => $transaction->paymentGateway->name ?? null,
                'user_id' => $transaction->user_id,
                'created_at' => $transaction->created_at,
                'updated_at' => $transaction->updated_at,
            ],
        ];

        // Extract all metadata from query parameters (preserve for redirect)
        // First, check if there's an encoded 'metadata' parameter (from the original callback URL)
        $metadataParams = [];
        $encodedMetadata = $request->input('metadata');
        
        if ($encodedMetadata) {
            // Decode the base64 encoded metadata JSON
            try {
                $decoded = base64_decode($encodedMetadata, true);
                if ($decoded !== false) {
                    $decodedMetadata = json_decode($decoded, true);
                    if (is_array($decodedMetadata)) {
                        $metadataParams = $decodedMetadata;
                    }
                }
            } catch (\Exception $e) {
                // If decoding fails, continue with individual parameters
            }
        }
        
        // Also extract individual metadata fields from query parameters
        // This handles cases where metadata is passed as individual fields
        $individualMetadata = $request->except(['order_id', 'status', 'payment_id', 'metadata']);
        
        // Merge individual fields into metadata (individual fields take precedence)
        foreach ($individualMetadata as $key => $value) {
            if ($value !== null) {
                // Try to decode if it's base64 encoded
                if (is_string($value) && base64_decode($value, true) !== false) {
                    $decoded = base64_decode($value, true);
                    $jsonDecoded = json_decode($decoded, true);
                    if ($jsonDecoded !== null) {
                        $metadataParams[$key] = $jsonDecoded;
                    } else {
                        $metadataParams[$key] = $value;
                    }
                } else {
                    $metadataParams[$key] = $value;
                }
            }
        }

        // If JSON request, return JSON response with metadata
        if ($request->expectsJson()) {
            $responseData['metadata'] = $metadataParams;
            return response()->json($responseData);
        }

        // For web requests, redirect based on payment status
        // Store transaction in session for access in the success/failed pages
        session()->flash('payment_transaction', $transaction);
        session()->flash('payment_status', $transaction->status);

        if ($transaction->isSuccessful()) {
            // If original_callback_url is provided (from Midtrans), redirect there with all metadata
            if ($originalCallbackUrl) {
                // Parse the original callback URL
                $parsedOriginalUrl = parse_url($originalCallbackUrl);
                $originalQueryParams = [];
                
                if (isset($parsedOriginalUrl['query'])) {
                    parse_str($parsedOriginalUrl['query'], $originalQueryParams);
                }
                
                // Build redirect parameters
                $redirectParams = [
                    'order_id' => $orderId,
                    'transaction_id' => $transaction->id,
                    'status' => 'success',
                ];
                
                // Preserve original metadata if it exists
                if (isset($originalQueryParams['metadata'])) {
                    $redirectParams['metadata'] = $originalQueryParams['metadata'];
                } elseif ($encodedMetadata) {
                    $redirectParams['metadata'] = $encodedMetadata;
                } elseif (!empty($metadataParams)) {
                    $redirectParams['metadata'] = base64_encode(json_encode($metadataParams));
                }
                
                // Also add individual fields for backward compatibility
                foreach ($metadataParams as $key => $value) {
                    if ($value !== null && !in_array($key, ['order_id', 'status', 'transaction_id', 'payment_id'])) {
                        $redirectParams[$key] = $value;
                    }
                }
                
                // Rebuild original callback URL with all parameters
                // Check if the original callback URL is a full URL or relative
                if (isset($parsedOriginalUrl['scheme']) && isset($parsedOriginalUrl['host'])) {
                    // It's a full URL - rebuild it properly
                    $baseUrl = $parsedOriginalUrl['scheme'] . '://';
                    $baseUrl .= $parsedOriginalUrl['host'];
                    if (isset($parsedOriginalUrl['port'])) {
                        $baseUrl .= ':' . $parsedOriginalUrl['port'];
                    }
                    $baseUrl .= ($parsedOriginalUrl['path'] ?? '');
                } else {
                    // It's a relative URL - use it as-is for the path
                    $baseUrl = $parsedOriginalUrl['path'] ?? '';
                }
                
                // Merge redirect params with original params (redirect params take precedence)
                $finalParams = array_merge($originalQueryParams, $redirectParams);
                $finalParams = array_filter($finalParams, function($value) {
                    return $value !== null;
                });
                
                // Build the final URL
                $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
                $finalUrl = $baseUrl . $separator . http_build_query($finalParams);
                
                return redirect($finalUrl)
                    ->with('success', 'Payment completed successfully.')
                    ->with('transaction', $transaction);
            }
            
            // Build redirect URL with all metadata preserved
            // If original request had encoded metadata, preserve it; otherwise encode the extracted metadata
            $redirectParams = [
                'order_id' => $orderId,
                'transaction_id' => $transaction->id,
                'status' => 'success',
            ];
            
            // If original request had encoded metadata parameter, preserve it
            if ($encodedMetadata) {
                $redirectParams['metadata'] = $encodedMetadata;
            } elseif (!empty($metadataParams)) {
                // Otherwise, encode the extracted metadata
                $redirectParams['metadata'] = base64_encode(json_encode($metadataParams));
            }
            
            // Also add individual fields for backward compatibility
            foreach ($metadataParams as $key => $value) {
                if ($value !== null && !in_array($key, ['order_id', 'status', 'transaction_id', 'payment_id'])) {
                    $redirectParams[$key] = $value;
                }
            }
            
            // Remove null values
            $redirectParams = array_filter($redirectParams, function($value) {
                return $value !== null;
            });
            
            // Redirect to success page with all metadata
            return redirect()->route('payment.success', $redirectParams)
                ->with('success', 'Payment completed successfully.')
                ->with('transaction', $transaction);
        }

        // For failed/cancelled payments, redirect back with metadata
        // If original_callback_url is provided (from Midtrans), redirect there
        if ($originalCallbackUrl) {
            $parsedOriginalUrl = parse_url($originalCallbackUrl);
            $originalQueryParams = [];
            
            if (isset($parsedOriginalUrl['query'])) {
                parse_str($parsedOriginalUrl['query'], $originalQueryParams);
            }
            
            $redirectParams = [
                'order_id' => $orderId,
                'transaction_id' => $transaction->id,
                'status' => $status,
            ];
            
            if (isset($originalQueryParams['metadata'])) {
                $redirectParams['metadata'] = $originalQueryParams['metadata'];
            } elseif ($encodedMetadata) {
                $redirectParams['metadata'] = $encodedMetadata;
            } elseif (!empty($metadataParams)) {
                $redirectParams['metadata'] = base64_encode(json_encode($metadataParams));
            }
            
            foreach ($metadataParams as $key => $value) {
                if ($value !== null && !in_array($key, ['order_id', 'status', 'transaction_id', 'payment_id'])) {
                    $redirectParams[$key] = $value;
                }
            }
            
            // Rebuild original callback URL with all parameters
            // Check if the original callback URL is a full URL or relative
            if (isset($parsedOriginalUrl['scheme']) && isset($parsedOriginalUrl['host'])) {
                // It's a full URL - rebuild it properly
                $baseUrl = $parsedOriginalUrl['scheme'] . '://';
                $baseUrl .= $parsedOriginalUrl['host'];
                if (isset($parsedOriginalUrl['port'])) {
                    $baseUrl .= ':' . $parsedOriginalUrl['port'];
                }
                $baseUrl .= ($parsedOriginalUrl['path'] ?? '');
            } else {
                // It's a relative URL - use it as-is for the path
                $baseUrl = $parsedOriginalUrl['path'] ?? '';
            }
            
            $finalParams = array_merge($originalQueryParams, $redirectParams);
            $finalParams = array_filter($finalParams, function($value) {
                return $value !== null;
            });
            
            // Build the final URL
            $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
            $finalUrl = $baseUrl . $separator . http_build_query($finalParams);
            
            return redirect($finalUrl)
                ->with('error', 'Payment failed or was cancelled.')
                ->with('transaction', $transaction);
        }
        
        $redirectParams = [
            'order_id' => $orderId,
            'transaction_id' => $transaction->id,
            'status' => $status,
        ];
        
        // If original request had encoded metadata parameter, preserve it
        if ($encodedMetadata) {
            $redirectParams['metadata'] = $encodedMetadata;
        } elseif (!empty($metadataParams)) {
            // Otherwise, encode the extracted metadata
            $redirectParams['metadata'] = base64_encode(json_encode($metadataParams));
        }
        
        // Also add individual fields for backward compatibility
        foreach ($metadataParams as $key => $value) {
            if ($value !== null && !in_array($key, ['order_id', 'status', 'transaction_id', 'payment_id'])) {
                $redirectParams[$key] = $value;
            }
        }
        
        // Remove null values
        $redirectParams = array_filter($redirectParams, function($value) {
            return $value !== null;
        });
        
        return redirect()->back()
            ->with('error', 'Payment failed or was cancelled.')
            ->with('transaction', $transaction)
            ->withInput($redirectParams);
    }
}

