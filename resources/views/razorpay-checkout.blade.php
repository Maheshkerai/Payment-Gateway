@php
    use MaheshKerai\PaymentGateways\Support\PaymentSummary;

    // $summary / $orderDate are supplied by PaymentController; fall back for views rendered without them.
    $summary = $summary ?? PaymentSummary::fromMetadata($paymentData ?? [], (string) config('app.name'));
    $orderDate = $orderDate ?? now();

    $currency = strtoupper($paymentData['currency'] ?? 'INR');
    $amount = (float) ($paymentData['amount'] ?? 0);
    $amountLabel = PaymentSummary::formatAmount($amount, $currency);
    $merchantName = $summary['merchant_name'] !== '' ? $summary['merchant_name'] : 'Payment';

    // "Pay Now" is the package default; show the amount on the button instead.
    $buttonText = $paymentData['button_text'] ?? 'Pay Now';
    $buttonText = ($buttonText === '' || $buttonText === 'Pay Now') ? 'Pay ' . $amountLabel : $buttonText;

    $options = [
        'key' => $paymentData['key'] ?? '',
        'amount' => $paymentData['amount_in_paise'] ?? (int) round($amount * 100),
        'currency' => $currency,
        'order_id' => $paymentData['razorpay_order_id'] ?? $paymentData['order_id'] ?? '',
        'name' => $merchantName,
        'description' => $summary['product_name'] !== '' ? $summary['product_name'] : ($summary['description'] !== '' ? $summary['description'] : 'Payment'),
        'image' => $summary['merchant_logo'],
        'prefill' => [
            'name' => $paymentData['customer_name'] ?? '',
            'email' => $paymentData['customer_email'] ?? '',
            'contact' => $paymentData['customer_phone'] ?? '',
        ],
        'theme' => ['color' => $summary['theme_color']],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Pay {{ $amountLabel }} &middot; {{ $merchantName }}</title>
    @include('payment-gateways::partials.razorpay-card-styles', ['summary' => $summary])
</head>
<body>
<main class="page">
    <section class="card" aria-labelledby="merchant-name">
        <div class="card__body">
            <header class="brand">
                <div class="brand__logo">
                    @if($summary['merchant_logo'] !== '')
                        <img src="{{ $summary['merchant_logo'] }}" alt="{{ $merchantName }} logo" referrerpolicy="no-referrer">
                    @else
                        <span aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($merchantName, 0, 1)) }}</span>
                    @endif
                </div>
                <div class="brand__text">
                    <h1 class="brand__name" id="merchant-name">{{ $merchantName }}</h1>
                    <p class="brand__sub">Secure checkout</p>
                </div>
                <span class="pill pill--pending" id="status-pill">Awaiting payment</span>
            </header>

            <div class="amount">
                <span class="amount__label">Amount to pay</span>
                <strong class="amount__value">{{ $amountLabel }}</strong>
            </div>

            <div class="alert" id="alert" role="alert" aria-live="assertive" hidden></div>

            @if($summary['product_name'] !== '' || $summary['description'] !== '')
                <h2 class="section-title">Order summary</h2>
                <div class="product">
                    @if($summary['product_image'] !== '')
                        <img class="product__image" src="{{ $summary['product_image'] }}" alt="" referrerpolicy="no-referrer">
                    @endif
                    <div>
                        @if($summary['product_name'] !== '')
                            <h3 class="product__name">{{ $summary['product_name'] }}</h3>
                        @endif
                        @if($summary['description'] !== '')
                            <p class="product__desc">{{ $summary['description'] }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <h2 class="section-title">Payment details</h2>
            <dl class="details">
                <div class="details__row details__row--stack"><dt>Order ID</dt><dd class="mono">{{ $paymentData['order_id'] ?? '' }}</dd></div>
                @if(!empty($transactionId))
                    <div class="details__row"><dt>Transaction ID</dt><dd class="mono">#{{ $transactionId }}</dd></div>
                @endif
                <div class="details__row"><dt>Date</dt><dd>{{ $orderDate->format('d M Y, h:i A T') }}</dd></div>
                @if($summary['customer_name'] !== '' || ($paymentData['customer_name'] ?? '') !== '')
                    <div class="details__row"><dt>Customer</dt><dd>{{ $summary['customer_name'] !== '' ? $summary['customer_name'] : $paymentData['customer_name'] }}</dd></div>
                @endif
                @if(($paymentData['customer_email'] ?? '') !== '')
                    <div class="details__row"><dt>Email</dt><dd>{{ $paymentData['customer_email'] }}</dd></div>
                @endif
                @foreach($summary['details'] as $row)
                    <div class="details__row"><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>
                @endforeach
            </dl>

            <form action="{{ route('payment-gateways.razorpay.callback') }}" method="POST" id="razorpay-payment-form" class="actions">
                @csrf
                <input type="hidden" name="callback_url" value="{{ $callbackUrl ?? '' }}">
                <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id" value="">
                <input type="hidden" name="razorpay_order_id" id="razorpay_order_id" value="">
                <input type="hidden" name="razorpay_signature" id="razorpay_signature" value="">

                <button type="button" class="btn" id="pay-button">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                    <span id="pay-button-label">{{ $buttonText }}</span>
                </button>
            </form>

            @include('payment-gateways::partials.razorpay-badge')

            <noscript><p class="alert">JavaScript is required to complete this payment securely. Please enable it and reload this page.</p></noscript>
        </div>

        <div class="overlay" id="overlay" role="status" aria-live="polite" hidden>
            <div class="spinner" aria-hidden="true"></div>
            <div>
                <strong>Verifying your payment&hellip;</strong>
                <p class="brand__sub">Please don&rsquo;t close or refresh this page.</p>
            </div>
        </div>
    </section>
</main>

<script type="application/json" id="rzp-options">@json($options)</script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
    var options = JSON.parse(document.getElementById('rzp-options').textContent);
    var form = document.getElementById('razorpay-payment-form');
    var button = document.getElementById('pay-button');
    var label = document.getElementById('pay-button-label');
    var pill = document.getElementById('status-pill');
    var alertBox = document.getElementById('alert');
    var overlay = document.getElementById('overlay');
    var idleLabel = label.textContent;

    function setPill(text, kind) {
        pill.textContent = text;
        pill.className = 'pill pill--' + kind;
    }

    function showError(message) {
        alertBox.textContent = message;
        alertBox.hidden = false;
    }

    function reset() {
        button.disabled = false;
        label.textContent = idleLabel;
        setPill('Awaiting payment', 'pending');
    }

    options.handler = function (response) {
        document.getElementById('razorpay_payment_id').value = response.razorpay_payment_id || '';
        document.getElementById('razorpay_order_id').value = response.razorpay_order_id || options.order_id;
        document.getElementById('razorpay_signature').value = response.razorpay_signature || '';
        alertBox.hidden = true;
        overlay.hidden = false;
        setPill('Verifying', 'pending');
        form.submit();
    };
    options.modal = {
        ondismiss: reset,
        confirm_close: true
    };

    button.addEventListener('click', function () {
        if (typeof window.Razorpay === 'undefined') {
            showError('We could not load the secure Razorpay checkout. Please check your connection and reload the page.');
            return;
        }

        alertBox.hidden = true;
        button.disabled = true;
        label.textContent = 'Opening secure checkout…';

        var checkout = new window.Razorpay(options);
        checkout.on('payment.failed', function (event) {
            var error = (event && event.error) || {};
            setPill('Payment failed', 'danger');
            showError((error.description || 'The payment could not be completed.') + ' You can try again.');
        });
        checkout.open();
    });

    // Coming back with the browser's Back button must not leave the page stuck on "Verifying".
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            overlay.hidden = true;
            reset();
        }
    });
})();
</script>
</body>
</html>
