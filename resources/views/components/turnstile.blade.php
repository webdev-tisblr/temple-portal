{{-- Cloudflare Turnstile widget. Renders NOTHING until the admin sets
     turnstile_site_key in System Settings → Cloudflare Turnstile, so the
     component can sit in forms permanently. Pair the form's POST route
     with the `turnstile` middleware (VerifyTurnstile) — client widget
     and server check ship together.

     The api.js script is emitted INLINE (not @push'ed to a stack) so the
     component works on standalone pages too — auth/login.blade.php is its
     own HTML document with no @stack('scripts'), and a stack-only script
     silently never loads there (widget renders no token → middleware
     rejects every submit). @once keeps it a single load per page. --}}
@php $turnstileSiteKey = \App\Models\SystemSetting::getValue('turnstile_site_key', ''); @endphp
@if($turnstileSiteKey !== '')
    @once
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
    <div class="cf-turnstile my-3" data-sitekey="{{ $turnstileSiteKey }}" data-theme="light"></div>
    @error('turnstile')
        <p class="text-red-400 text-sm mt-1">{{ $message }}</p>
    @enderror
@endif
