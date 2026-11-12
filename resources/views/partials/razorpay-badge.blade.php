{{-- "Secured by Razorpay" trust badge shared by the payment page and receipt. $label overrides the lead-in text. --}}
<div class="trust">
    <div class="rzp-badge" role="img" aria-label="{{ $label ?? 'Secured by Razorpay' }}">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
        <span>{{ $label ?? 'Secured by' }}</span>
        <span class="rzp-badge__name">Razor<b>pay</b></span>
    </div>
    <p class="trust__methods">UPI &middot; Cards &middot; Netbanking &middot; Wallets &middot; EMI</p>
</div>
