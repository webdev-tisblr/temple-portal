{{--
    App-install bottom sheet — shown to mobile-web visitors only.

    A gentle slide-up card (not a blocking popup): it detects the device and
    links straight to the right store (iPhone → App Store, Android → Play
    Store), remembers a dismissal for 14 days, and never appears on desktop or
    inside the temple app's own WebView / an installed PWA. Store links + the
    on/off switch come from admin System Settings → General → Mobile App.
--}}
@php
    $bannerEnabled = \App\Models\SystemSetting::getValue('app_install_banner_enabled', '1') === '1';
    $iosStoreUrl = \App\Models\SystemSetting::getValue('app_ios_store_url', '');
    $androidStoreUrl = \App\Models\SystemSetting::getValue('app_android_store_url', '');
@endphp

@if($bannerEnabled && ($iosStoreUrl || $androidStoreUrl))
<div
    x-data="{
        visible: false,
        target: null,
        ios: @js($iosStoreUrl),
        android: @js($androidStoreUrl),
        key: 'sph_app_banner_until',
        init() {
            const ua = navigator.userAgent || '';

            // Never nag inside our own app's WebView, an installed PWA, or
            // when the app explicitly appends ?in_app=1 to embedded links.
            const inApp = new URLSearchParams(location.search).has('in_app')
                || /wv|PatadiyaHanumanji/i.test(ua)
                || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                || window.navigator.standalone === true;
            if (inApp) return;

            const isIOS = /iPad|iPhone|iPod/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isAndroid = /Android/.test(ua);

            if (isIOS && this.ios) this.target = this.ios;
            else if (isAndroid && this.android) this.target = this.android;
            else if (isIOS || isAndroid) this.target = this.ios || this.android;
            else return; // desktop / unknown device — stay hidden

            // Honour a recent dismissal.
            const until = parseInt(localStorage.getItem(this.key) || '0', 10);
            if (Date.now() < until) return;

            setTimeout(() => { this.visible = true; }, 1400);
        },
        dismiss() {
            this.visible = false;
            localStorage.setItem(this.key, String(Date.now() + 14 * 24 * 60 * 60 * 1000));
        }
    }"
    x-cloak
    class="fixed inset-x-0 bottom-0 z-[90] px-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pointer-events-none">

    <div x-show="visible"
         x-transition:enter="transition ease-out duration-500"
         x-transition:enter-start="opacity-0 translate-y-10"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-10"
         class="pointer-events-auto mx-auto max-w-md relative overflow-hidden rounded-3xl border border-[rgba(200,148,52,0.35)] shadow-[0_20px_50px_-12px_rgba(60,30,10,0.55)]"
         style="background: linear-gradient(135deg,#FFFCF5 0%,#FBEFE0 100%);">

        {{-- Soft saffron glow accent --}}
        <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full opacity-40 pointer-events-none"
             style="background: radial-gradient(circle, rgba(232,117,26,0.35), transparent 70%);"></div>

        <button @click="dismiss()" class="absolute top-2.5 right-2.5 z-10 w-7 h-7 flex items-center justify-center rounded-full text-stone-400 hover:text-stone-600 hover:bg-black/5 transition text-sm leading-none" aria-label="{{ __('app_banner.later') }}">✕</button>

        <div class="relative flex items-center gap-4 p-4 pr-9">
            {{-- App icon --}}
            <span class="flex-none w-14 h-14 rounded-2xl overflow-hidden border-2 border-saffron-300 shadow-md bg-white">
                <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="{{ __('common.temple_name') }}" class="w-full h-full object-cover">
            </span>

            <div class="min-w-0 flex-1">
                <p class="font-marcellus text-[15px] text-stone-700 leading-snug">{{ __('common.temple_name') }}</p>
                <p class="text-[12.5px] text-stone-500 leading-snug mt-0.5">{{ __('app_banner.subtitle') }}</p>
                <div class="flex items-center gap-1 mt-1 text-[11px]">
                    <span class="text-saffron-400 tracking-tight">★★★★★</span>
                    <span class="text-stone-400">· {{ __('app_banner.badge') }}</span>
                </div>
            </div>
        </div>

        <div class="relative px-4 pb-4">
            <a :href="target" target="_blank" rel="noopener"
               @click="dismiss()"
               class="btn-divine w-full flex items-center justify-center gap-2 py-3 text-sm">
                {{ __('app_banner.cta') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
        </div>
    </div>
</div>
@endif
