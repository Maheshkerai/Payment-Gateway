{{-- Shared look of the Razorpay payment page and receipt. Expects $summary (see PaymentSummary). --}}
<style>
    :root {
        --accent: {{ $summary['theme_color'] }};
        --accent-rgb: {{ $summary['accent_rgb'] }};
        --on-accent: {{ $summary['accent_text'] }};
        --bg: #f1f5f9;
        --card: #ffffff;
        --text: #0f172a;
        --muted: #64748b;
        --line: #e2e8f0;
        --soft: #f8fafc;
        --success: #047857;
        --success-bg: #d1fae5;
        --pending: #b45309;
        --pending-bg: #fef3c7;
        --danger: #b91c1c;
        --danger-bg: #fee2e2;
        --rzp-blue: #3395ff;
        --rzp-navy: #072654;
        --shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 12px 32px rgba(15, 23, 42, .08);
    }
    @media (prefers-color-scheme: dark) {
        :root {
            --bg: #0b1220;
            --card: #111a2e;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --line: #1f2b45;
            --soft: #0d1627;
            --success: #34d399;
            --success-bg: rgba(52, 211, 153, .14);
            --pending: #fbbf24;
            --pending-bg: rgba(251, 191, 36, .14);
            --danger: #f87171;
            --danger-bg: rgba(248, 113, 113, .14);
            --rzp-navy: #cfe3ff;
            --shadow: 0 1px 2px rgba(0, 0, 0, .4), 0 12px 32px rgba(0, 0, 0, .35);
        }
    }
    *, *::before, *::after { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body {
        margin: 0;
        background: var(--bg);
        color: var(--text);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        font-size: 15px;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
    }
    .page { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; }
    .card {
        position: relative;
        width: 100%;
        max-width: 480px;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 20px;
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .card::before { content: ""; display: block; height: 5px; background: var(--accent); }
    .card__body { padding: 24px 28px 22px; }

    /* brand header */
    .brand { display: flex; align-items: center; gap: 14px; }
    .brand__logo {
        flex: none; width: 48px; height: 48px; border-radius: 12px; overflow: hidden;
        display: grid; place-items: center;
        background: rgba(var(--accent-rgb), .12); color: var(--accent);
        border: 1px solid rgba(var(--accent-rgb), .22);
        font-size: 20px; font-weight: 700;
    }
    .brand__logo img { width: 100%; height: 100%; object-fit: contain; background: #fff; display: block; }
    .brand__text { min-width: 0; flex: 1; }
    .brand__name { margin: 0; font-size: 17px; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
    .brand__sub { margin: 2px 0 0; font-size: 12.5px; color: var(--muted); }

    .pill {
        flex: none; display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap;
    }
    .pill::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .pill--pending { color: var(--pending); background: var(--pending-bg); }
    .pill--success { color: var(--success); background: var(--success-bg); }
    .pill--danger { color: var(--danger); background: var(--danger-bg); }

    /* amount */
    .amount { margin: 22px 0 6px; text-align: center; padding: 18px 12px; background: var(--soft); border: 1px solid var(--line); border-radius: 14px; }
    .amount__label { display: block; font-size: 12.5px; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
    .amount__value { display: block; margin-top: 2px; font-size: 34px; line-height: 1.2; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }

    /* product */
    .section-title { margin: 20px 0 8px; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .product { display: flex; gap: 14px; align-items: flex-start; padding: 14px; border: 1px solid var(--line); border-radius: 14px; }
    .product__image { flex: none; width: 56px; height: 56px; border-radius: 10px; object-fit: cover; background: var(--soft); border: 1px solid var(--line); }
    .product__name { margin: 0; font-size: 15.5px; font-weight: 650; line-height: 1.3; overflow-wrap: anywhere; }
    .product__desc { margin: 3px 0 0; color: var(--muted); font-size: 13.5px; overflow-wrap: anywhere; }

    /* detail rows */
    .details { margin: 0; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
    .details__row { display: flex; justify-content: space-between; gap: 16px; padding: 11px 14px; font-size: 14px; }
    .details__row + .details__row { border-top: 1px solid var(--line); }
    .details dt { color: var(--muted); flex: none; }
    .details dd { margin: 0; text-align: right; font-weight: 550; overflow-wrap: anywhere; min-width: 0; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; font-weight: 500; word-break: break-all; }

    /* buttons */
    .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 9px;
        width: 100%; padding: 14px 18px; border: 0; border-radius: 12px; cursor: pointer;
        font: inherit; font-size: 16px; font-weight: 650; text-decoration: none;
        background: var(--accent); color: var(--on-accent);
        transition: filter .15s ease, transform .05s ease, box-shadow .15s ease;
        box-shadow: 0 6px 16px rgba(var(--accent-rgb), .28);
    }
    .btn:hover { filter: brightness(.94); }
    .btn:active { transform: translateY(1px); }
    .btn:focus-visible, .link-btn:focus-visible { outline: 3px solid rgba(var(--accent-rgb), .45); outline-offset: 2px; }
    .btn[disabled] { opacity: .65; cursor: progress; box-shadow: none; }
    .btn--ghost { background: transparent; color: var(--text); border: 1px solid var(--line); box-shadow: none; font-weight: 600; font-size: 15px; }
    .btn--ghost:hover { background: var(--soft); filter: none; }
    .actions { display: grid; gap: 10px; margin-top: 18px; }

    .alert { margin: 16px 0 0; padding: 11px 14px; border-radius: 12px; font-size: 14px; background: var(--danger-bg); color: var(--danger); }
    .alert[hidden] { display: none; }

    /* Razorpay branding */
    .trust { margin-top: 18px; padding-top: 16px; border-top: 1px dashed var(--line); text-align: center; }
    .rzp-badge { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; color: var(--muted); }
    .rzp-badge svg { color: var(--rzp-blue); flex: none; }
    .rzp-badge__name { font-weight: 800; font-size: 15px; letter-spacing: -.01em; color: var(--rzp-navy); }
    .rzp-badge__name b { color: var(--rzp-blue); font-weight: 800; }
    .trust__methods { margin: 6px 0 0; font-size: 12px; color: var(--muted); }

    /* processing overlay */
    .overlay {
        position: absolute; inset: 0; z-index: 5; display: grid; place-content: center; justify-items: center; gap: 14px;
        background: rgba(var(--accent-rgb), .04); background-color: color-mix(in srgb, var(--card) 92%, transparent);
        -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); text-align: center; padding: 24px;
    }
    .overlay[hidden] { display: none; }
    .spinner { width: 38px; height: 38px; border-radius: 50%; border: 4px solid rgba(var(--accent-rgb), .2); border-top-color: var(--accent); animation: spin .8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* success receipt */
    .success { text-align: center; padding-top: 4px; }
    .success__icon { width: 76px; height: 76px; margin: 6px auto 12px; border-radius: 50%; background: var(--success-bg); display: grid; place-items: center; }
    .success__icon svg { width: 40px; height: 40px; color: var(--success); }
    .success__icon path { stroke-dasharray: 30; stroke-dashoffset: 30; animation: draw .55s .15s ease forwards; }
    @keyframes draw { to { stroke-dashoffset: 0; } }
    .success__title { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
    .success__sub { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
    .card--success::before { background: #10b981; }
    .redirect-note { margin: 14px 0 0; text-align: center; font-size: 13px; color: var(--muted); }
    .link-btn { background: none; border: 0; padding: 0; font: inherit; color: var(--accent); cursor: pointer; text-decoration: underline; }

    @media (max-width: 480px) {
        .page { padding: 12px; align-items: start; }
        .card__body { padding: 20px 18px 18px; }
        .amount__value { font-size: 30px; }
        .brand { flex-wrap: wrap; }
        .pill { order: 3; }
        .details__row--stack { flex-direction: column; gap: 2px; }
        .details__row--stack dd { text-align: left; }
    }
    @media (prefers-reduced-motion: reduce) {
        .spinner { animation-duration: 2s; }
        .success__icon path { animation: none; stroke-dashoffset: 0; }
    }
    @media print {
        body { background: #fff; }
        .page { padding: 0; min-height: 0; }
        .card { box-shadow: none; border-color: #ccc; }
        .actions, .redirect-note, .overlay { display: none !important; }
    }
</style>
