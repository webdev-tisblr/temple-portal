{{--
    App-install bottom sheet — shown to mobile-web visitors only.

    A gentle slide-up card (not a blocking popup): it detects the device and
    links straight to the right store (iPhone → App Store, Android → Play
    Store), remembers a dismissal for 14 days, and never appears on desktop or
    inside the temple app's own WebView / an installed PWA. Store links + the
    on/off switch come from admin System Settings → General → Mobile App.
--}}
@php
    // Enabled/frequency/schedule come from Home Page Settings (site_banner_*);
    // store URLs stay in System Settings → Mobile App. Shares the ribbon's
    // cached settings bag so this costs nothing extra per request.
    $display = \Illuminate\Support\Facades\Cache::remember('site.display.v1', 300, function () {
        return \App\Models\SystemSetting::query()->where('key', 'like', 'site_%')->pluck('value', 'key')->all();
    });

    $bannerEnabled = ($display['site_banner_enabled'] ?? '1') === '1';
    $now = now();
    $bnFrom = $display['site_banner_starts_at'] ?? '';
    $bnTo = $display['site_banner_ends_at'] ?? '';
    $bannerLive = $bannerEnabled
        && ($bnFrom === '' || $now->greaterThanOrEqualTo($bnFrom))
        && ($bnTo === '' || $now->lessThanOrEqualTo($bnTo));

    $cooldownDays = (int) ($display['site_banner_cooldown_days'] ?? 14);
    $delayMs = (int) (((float) ($display['site_banner_delay_seconds'] ?? 2)) * 1000);

    $iosStoreUrl = \App\Models\SystemSetting::getValue('app_ios_store_url', '');
    $androidStoreUrl = \App\Models\SystemSetting::getValue('app_android_store_url', '');
@endphp

@if($bannerLive && ($iosStoreUrl || $androidStoreUrl))
<div
    x-data="{
        visible: false,
        target: null,
        ios: @js($iosStoreUrl),
        android: @js($androidStoreUrl),
        cooldownDays: {{ $cooldownDays }},
        delayMs: {{ $delayMs }},
        key: 'sph_app_banner_until',
        init() {
            const ua = navigator.userAgent || '';

            // Never nag inside our own app's WebView, an installed PWA, or
            // when the app explicitly appends ?in_app=1 to embedded links.
            const params = new URLSearchParams(location.search);

            // A session that arrived through the app handoff sets this
            // marker (return-to-app-banner) — or the app can append
            // ?from_app=1 / ?in_app=1 when it opens a page without the
            // logged-in handoff. Either way: never advertise an install
            // to someone who is standing in the app. The check is
            // client-side ON PURPOSE — a server-side session read here
            // would bake one visitor's state into the guest page cache.
            let fromApp = false;
            try { fromApp = sessionStorage.getItem('sph_from_app') === '1'; } catch (e) {}
            if (params.has('from_app')) {
                fromApp = true;
                try { sessionStorage.setItem('sph_from_app', '1'); } catch (e) {}
            }

            const inApp = params.has('in_app')
                || fromApp
                || /wv|PatadiyaHanumanji/i.test(ua)
                || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                || window.navigator.standalone === true;
            if (inApp) return;

            const isIOS = /iPad|iPhone|iPod/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isAndroid = /Android/.test(ua);

            // Strictly per-platform. The old `this.ios || this.android`
            // fallthrough handed iPhone users a Play Store link whenever
            // app_ios_store_url was blank — which is exactly the state
            // production is in until the App Store listing goes live.
            if (isIOS) this.target = this.ios;
            else if (isAndroid) this.target = this.android;
            else return; // desktop / unknown device — stay hidden

            if (! this.target) return; // no listing for this platform yet

            // Honour a recent dismissal (cooldown configured in admin;
            // 0 days = show every visit).
            if (this.cooldownDays > 0) {
                const until = parseInt(localStorage.getItem(this.key) || '0', 10);
                if (Date.now() < until) return;
            }

            setTimeout(() => { this.visible = true; }, this.delayMs);
        },
        dismiss() {
            this.visible = false;
            if (this.cooldownDays > 0) {
                localStorage.setItem(this.key, String(Date.now() + this.cooldownDays * 24 * 60 * 60 * 1000));
            }
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
