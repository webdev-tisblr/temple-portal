{{-- Cloudflare Turnstile widget. Renders NOTHING until the admin sets
     turnstile_site_key in System Settings → Cloudflare Turnstile, so the
     component can sit in forms permanently. Pair the form's POST route
     with the `turnstile` middleware (VerifyTurnstile) — client widget
     and server check ship together.

     The api.js script is loaded once per page via @once; multiple
     widgets on one page each get their own div. --}}
@php $turnstileSiteKey = \App\Models\SystemSetting::getValue('turnstile_site_key', ''); @endphp
@if($turnstileSiteKey !== '')
    @once
        @push('scripts')
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
        @endpush
    @endonce
    <div class="cf-turnstile my-3" data-sitekey="{{ $turnstileSiteKey }}" data-theme="light"></div>
    @error('turnstile')
        <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
    @enderror
@endif
