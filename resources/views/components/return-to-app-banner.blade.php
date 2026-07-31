{{--
    "Return to the app" bar — shown ONLY to devotees whose session came
    from the mobile app's browser handoff (AuthWebController::appLogin
    sets session 'from_app'). Authenticated responses bypass both the
    server guest-cache and the Cloudflare edge rule, so this can never
    leak into cached guest HTML.

    Hidden on the handoff destinations themselves (/donate*, /dashboard*)
    — the bar is for devotees wandering elsewhere, and must not distract
    mid-payment. Dismissal lives in sessionStorage (per visit): the next
    handoff shows it again.

    Tapping the bar (or its button) fires the patadiyahanumanji:// scheme;
    if the app hasn't taken over within ~1.6s (build < v1.4.6 without the
    scheme), it falls back to the device's store listing — so enabling
    app_scheme_enabled is safe even while old builds are still out there.
--}}
@php
    $showBar = session()->has('from_app')
        && auth('devotee')->check()
        && ! request()->is('donate*', 'dashboard*', 'auth*');
    $schemeEnabled = $showBar
        && \App\Models\SystemSetting::getValue('app_scheme_enabled', '0') === '1';
    $iosStoreUrl = $schemeEnabled ? \App\Models\SystemSetting::getValue('app_ios_store_url', '') : '';
    $androidStoreUrl = $schemeEnabled ? \App\Models\SystemSetting::getValue('app_android_store_url', '') : '';
@endphp

@if($showBar)
<div
    x-data="{
        visible: false,
        scheme: @js($schemeEnabled),
        ios: @js($iosStoreUrl),
        android: @js($androidStoreUrl),
        init() {
            if (sessionStorage.getItem('sph_return_app_dismissed') === '1') return;
            const ua = navigator.userAgent || '';
            const mobile = /iPad|iPhone|iPod|Android/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (! mobile) return; // handoff sessions are mobile, but be safe
            this.visible = true;
        },
        dismiss() {
            this.visible = false;
            sessionStorage.setItem('sph_return_app_dismissed', '1');
        },
        openApp() {
            if (! this.scheme) return;
            // Store fallback for app builds without the scheme: if this
            // page is still visible ~1.6s after firing the deep link, the
            // app didn't open — send them to update instead.
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const store = isIOS ? this.ios : this.android;
            const timer = setTimeout(() => {
                if (! document.hidden && store) window.location.href = store;
            }, 1600);
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) clearTimeout(timer);
            }, { once: true });
            window.addEventListener('pagehide', () => clearTimeout(timer), { once: true });
            window.location.href = 'patadiyahanumanji://home';
        },
    }"
    x-show="visible"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="translate-y-full"
    x-transition:enter-end="translate-y-0"
    class="fixed bottom-0 inset-x-0 z-[70] px-3 pb-3"
    style="padding-bottom: calc(0.75rem + env(safe-area-inset-bottom));"
>
    <div class="mx-auto max-w-md flex items-center gap-3 rounded-2xl bg-[#7A1E1E] text-[#FBF5EA] shadow-2xl px-4 py-3"
         :class="scheme ? 'cursor-pointer' : ''"
         @click="openApp()">
        <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="" class="w-9 h-9 rounded-full border border-amber-200/30 shrink-0">
        <p class="flex-1 text-sm font-medium leading-snug">{{ __('common.return_to_app_text') }}</p>
        @if($schemeEnabled)
            <span class="shrink-0 rounded-full bg-[#FBF5EA] text-[#7A1E1E] text-sm font-bold px-4 py-2">
                {{ __('common.return_to_app_button') }}
            </span>
        @endif
        <button @click.stop="dismiss()" aria-label="Close" class="shrink-0 text-amber-100/60 hover:text-amber-100 p-1">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
@endif
