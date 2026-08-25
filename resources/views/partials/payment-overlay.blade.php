{{-- Payment handoff hold screen.

     Raised by resources/js/submit-lock.js the moment a form carrying
     `data-payment-form` is submitted, and taken down again on bfcache
     restore (browser Back out of Razorpay) or after the hard release
     timeout. Rendered server-side rather than built in JS so the copy
     is translated by Laravel like everything else — a devotee reading
     the site in Gujarati must not get an English holding screen at the
     one moment they are most likely to panic and press again.

     Lives in the layout, so every page that has a payment button on it
     has the overlay available without remembering to include it. --}}
<div id="payment-overlay" data-payment-overlay hidden aria-hidden="true" role="status" aria-live="polite">
    <div class="payment-overlay__card">
        <div class="payment-overlay__spinner" aria-hidden="true"></div>
        <p class="payment-overlay__title">{{ __('common.payment_redirect_title') }}</p>
        <p class="payment-overlay__text">{{ __('common.payment_redirect_body') }}</p>
        <p class="payment-overlay__slow" data-payment-overlay-slow hidden>{{ __('common.payment_redirect_slow') }}</p>
    </div>
</div>
