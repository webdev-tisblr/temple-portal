@extends('layouts.app')

@section('content')
{{-- The single Razorpay handoff screen. All four money paths — donation,
     seva, hall booking, store checkout — render THIS view once their
     order exists, so anything fixed here is fixed for all of them. --}}
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    <div class="animate-pulse mb-6">
        <div class="w-16 h-16 bg-amber-900/30 border border-amber-700/30 rounded-full flex items-center justify-center mx-auto">
            <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
        </div>
    </div>
    <h1 class="text-xl font-semibold text-amber-100/70 mb-2">{{ __('seva.payment_processing') }}</h1>
    <p class="text-amber-100/40">Razorpay {{ __('seva.razorpay_opening') }}</p>
    <p class="text-sm text-amber-100/30 mt-4">{{ $description }} — ₹{{ inr($amount / 100) }}</p>

    {{-- Dead end insurance. checkout.js is a third-party script on a
         third-party host: on a weak mobile connection it can take ten
         seconds or simply never arrive, and the original code called
         `new Razorpay(...)` unconditionally — which throws, silently,
         leaving the devotee watching a pulsing circle forever with their
         order already created and no way forward. This panel is the way
         forward, and retrying reuses the SAME order rather than making
         another one. --}}
    <div id="rzp-fallback" hidden class="mt-8 card-sacred p-5 text-left">
        <p class="text-sm text-red-400 font-semibold">{{ __('common.payment_open_failed') }}</p>
        <button type="button" id="rzp-retry"
            class="mt-4 w-full py-2.5 btn-divine text-sm">
            {{ __('common.try_again') }}
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var options = {
        key: @js($razorpayKeyId),
        amount: {{ $amount }},
        currency: @js($currency),
        name: @js(__('common.trust_full')),
        description: @js($description),
        order_id: @js($orderId),
        prefill: {
            name: @js($devoteeName),
            contact: @js($devoteePhone),
            email: @js($devoteeEmail)
        },
        theme: { color: "#e8c36a" },
        handler: function (response) {
            window.location.href = @js($successUrl ?? route('seva.booking.success'))
                + "?payment_id=" + encodeURIComponent(response.razorpay_payment_id)
                + "&order_id=" + encodeURIComponent(response.razorpay_order_id)
                + "&signature=" + encodeURIComponent(response.razorpay_signature);
        },
        modal: {
            ondismiss: function () {
                window.location.href = @js($failureUrl ?? route('seva.booking.failure'));
            }
        }
    };

    var fallback = document.getElementById('rzp-fallback');
    var retry = document.getElementById('rzp-retry');
    var opened = false;
    var loading = false;

    function showFallback() {
        if (opened) return;
        loading = false;
        fallback.hidden = false;
        retry.disabled = false;
        retry.classList.remove('is-submitting');
    }

    function openCheckout() {
        if (opened) return;

        if (typeof Razorpay === 'undefined') {
            loadScript();
            return;
        }

        try {
            new Razorpay(options).open();
            opened = true;
            fallback.hidden = true;
        } catch (e) {
            showFallback();
        }
    }

    // Injected rather than written as a plain <script src> so a failed or
    // stalled fetch is an event we can actually react to.
    function loadScript() {
        if (loading) return;
        loading = true;

        var s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.onload = function () { loading = false; openCheckout(); };
        s.onerror = function () { s.remove(); showFallback(); };
        document.head.appendChild(s);
    }

    retry.addEventListener('click', function () {
        retry.disabled = true;
        retry.classList.add('is-submitting');
        fallback.hidden = true;
        openCheckout();
        // If the script is still not there, loadScript() is now running;
        // give it a window before offering the panel again.
        setTimeout(function () { if (!opened) showFallback(); }, 12000);
    });

    loadScript();
    setTimeout(function () { if (!opened) showFallback(); }, 15000);
});
</script>
@endsection
