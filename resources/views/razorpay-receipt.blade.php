@php
    use MaheshKerai\PaymentGateways\Support\PaymentSummary;

    // $transaction, $verification, $redirectUrl, $redirectAfter come from PaymentController::razorpayCallback().
    $summary = $summary ?? PaymentSummary::fromMetadata([], (string) config('app.name'));

    $currency = strtoupper((string) ($verification['currency'] ?? $currency ?? 'INR'));
    $amount = (float) ($verification['amount'] ?? $transaction->amount);
    $amountLabel = PaymentSummary::formatAmount($amount, $currency);
    $merchantName = $summary['merchant_name'] !== '' ? $summary['merchant_name'] : 'Payment';
    $paidAt = ($transaction->updated_at ?? now())->copy()->timezone(config('app.timezone') ?: 'UTC');
    $razorpayPaymentId = (string) ($verification['payment_id'] ?? $transaction->payment_id);
    $redirectAfter = max(0, (int) ($redirectAfter ?? 0));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment successful &middot; {{ $merchantName }}</title>
    @if($redirectAfter > 0)
        <noscript><meta http-equiv="refresh" content="{{ $redirectAfter }};url={{ $redirectUrl }}"></noscript>
    @endif
    @include('payment-gateways::partials.razorpay-card-styles', ['summary' => $summary])
</head>
<body>
<main class="page">
    <section class="card card--success" aria-labelledby="receipt-title">
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
                    <p class="brand__name">{{ $merchantName }}</p>
                    <p class="brand__sub">Payment receipt</p>
                </div>
            </header>

            <div class="success">
                <div class="success__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                </div>
                <h1 class="success__title" id="receipt-title">Payment successful</h1>
                <p class="success__sub">
                    @if($summary['customer_name'] !== '')
                        Thank you, {{ $summary['customer_name'] }}. Your payment has been received.
                    @else
                        Thank you. Your payment has been received.
                    @endif
                </p>
            </div>

            <div class="amount">
                <span class="amount__label">Amount paid</span>
                <strong class="amount__value">{{ $amountLabel }}</strong>
            </div>

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
                <div class="details__row"><dt>Status</dt><dd><span class="pill pill--success">Successful</span></dd></div>
                <div class="details__row"><dt>Paid on</dt><dd>{{ $paidAt->format('d M Y, h:i A T') }}</dd></div>
                <div class="details__row details__row--stack"><dt>Order ID</dt><dd class="mono">{{ $transaction->order_id }}</dd></div>
                <div class="details__row"><dt>Transaction ID</dt><dd class="mono">#{{ $transaction->id }}</dd></div>
                @if($razorpayPaymentId !== '')
                    <div class="details__row details__row--stack"><dt>Razorpay payment ID</dt><dd class="mono">{{ $razorpayPaymentId }}</dd></div>
                @endif
                @if($summary['customer_name'] !== '')
                    <div class="details__row"><dt>Customer</dt><dd>{{ $summary['customer_name'] }}</dd></div>
                @endif
                @if($summary['customer_email'] !== '')
                    <div class="details__row"><dt>Email</dt><dd>{{ $summary['customer_email'] }}</dd></div>
                @endif
                @foreach($summary['details'] as $row)
                    <div class="details__row"><dt>{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>
                @endforeach
            </dl>

            <div class="actions">
                <a class="btn" id="continue-button" href="{{ $redirectUrl }}">Continue</a>
                <button type="button" class="btn btn--ghost" onclick="window.print()">Print or save receipt</button>
            </div>

            @if($redirectAfter > 0)
                <p class="redirect-note" id="redirect-note" aria-live="polite">
                    Taking you back in <span id="countdown">{{ $redirectAfter }}</span>s &middot;
                    <button type="button" class="link-btn" id="stay-button">Stay on this page</button>
                </p>
            @endif

            @include('payment-gateways::partials.razorpay-badge', ['label' => 'Payment processed securely by'])
        </div>
    </section>
</main>

@if($redirectAfter > 0)
<script>
(function () {
    var remaining = {{ $redirectAfter }};
    var target = @json($redirectUrl);
    var counter = document.getElementById('countdown');
    var note = document.getElementById('redirect-note');
    var timer = setInterval(function () {
        remaining -= 1;
        counter.textContent = remaining;
        if (remaining <= 0) {
            clearInterval(timer);
            window.location.assign(target);
        }
    }, 1000);

    document.getElementById('stay-button').addEventListener('click', function () {
        clearInterval(timer);
        note.hidden = true;
    });
})();
</script>
@endif
</body>
</html>
