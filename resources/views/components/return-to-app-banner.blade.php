{{--
    "Return to the app" prompt — shown ONLY to devotees whose session came
    from the mobile app's browser handoff (AuthWebController::appLogin sets
    session 'from_app'). Authenticated responses bypass both the server
    guest-cache (CacheGuestResponse bails on auth('devotee')->check()) and
    the Cloudflare edge rule, so none of this session state can leak into
    cached guest HTML.

    Two variants, one card:
      • thanks  — /donate/thank-you. The completion moment: this is the ONE
                  page that must prompt a return, and it used to be the one
                  page that suppressed the bar (request()->is('donate*')).
                  Shown immediately, worded around the finished donation.
      • roaming — any other page the devotee wandered onto mid-errand.
                  Delayed, softer, with an explicit "stay on the website".

    Both are bottom sheets inside a pointer-events-none wrapper, never a
    blocking overlay: on iOS this session is a live money path (Apple
    3.2.2(iv) pushes donations to the website) and nothing here may sit on
    top of a payment form or swallow a checkout tap. Checkout/handoff
    surfaces are excluded outright by $quietPath below.

    Tapping "open the app" fires the patadiyahanumanji:// scheme built from
    the session's return intent (see App\Support\AppDeepLink and
    specs/03-deeplink-contract.md). It NEVER auto-navigates to a store:
    iOS keeps document.hidden === false behind its "Open in …?" alert and
    fires no visibilitychange/pagehide, so any blind timer redirect bounces
    devotees who DO have the app installed. The fallback now needs positive
    evidence the page never went away, and even then only reveals a link
    the devotee has to tap.
--}}
@php
    $fromApp = session()->has('from_app') && auth('devotee')->check();

    $isThanks = request()->routeIs('donate.thanks');

    // Pages where a return prompt would be noise or, worse, in the way:
    // the handoff destinations themselves plus every form that takes
    // money. `donate*` deliberately no longer swallows the thank-you page.
    $quietPath = request()->is(
        'donate',
        'donate/*',
        'dashboard*',
        'auth*',
        'login*',
        'profile*',
        'store/cart*',
        'store/checkout*',
        'seva/*',
        'halls/*',
        'hall-booking*',
    );

    $showCard = $fromApp && ($isThanks || ! $quietPath);

    $schemeEnabled = $showCard
        && \App\Models\SystemSetting::getValue('app_scheme_enabled', '0') === '1';

    // Store URLs are a manual, last-resort escape hatch only. Blank (the
    // current production value for iOS, since the listing isn't live yet)
    // simply means the hint renders without a link.
    $iosStoreUrl = $schemeEnabled ? trim((string) \App\Models\SystemSetting::getValue('app_ios_store_url', '')) : '';
    $androidStoreUrl = $schemeEnabled ? trim((string) \App\Models\SystemSetting::getValue('app_android_store_url', '')) : '';

    // The thank-you controller stashes a request-scoped link that also
    // carries the completed donation id; everywhere else we rebuild from
    // the session intent alone.
    $deepLink = $showCard
        ? (session('from_app_return_url') ?: \App\Support\AppDeepLink::forSession())
        : '';
@endphp

@if($showCard)
<div
    x-data="{
        visible: false,
        failed: false,
        scheme: @js($schemeEnabled),
        thanks: @js($isThanks),
        link: @js($deepLink),
        ios: @js($iosStoreUrl),
        android: @js($androidStoreUrl),
        store: '',
        key: @js($isThanks ? 'sph_return_thanks_dismissed' : 'sph_return_app_dismissed'),
        init() {
            const ua = navigator.userAgent || '';
            const isIOS = /iPad|iPhone|iPod/.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isAndroid = /Android/.test(ua);

            // Tell the install banner to stand down for the rest of this
            // visit — offering an install to someone who arrived FROM the
            // app is contradictory, and doing it client-side keeps the
            // decision out of any cacheable HTML.
            try { sessionStorage.setItem('sph_from_app', '1'); } catch (e) {}

            if (! isIOS && ! isAndroid) return; // handoff sessions are mobile
            this.store = isIOS ? this.ios : this.android;

            // One dismissal per visit, per variant. Dismissing the roaming
            // prompt must not silence the completion prompt.
            try {
                if (sessionStorage.getItem(this.key) === '1') return;
            } catch (e) {}

            // The completion prompt is the point of the page, so it does
            // not wait; the roaming one gives the page a moment first.
            if (this.thanks) this.visible = true;
            else setTimeout(() => { this.visible = true; }, 1500);
        },
        dismiss() {
            this.visible = false;
            try { sessionStorage.setItem(this.key, '1'); } catch (e) {}
        },
        openApp() {
            if (! this.scheme || ! this.link) return;
            this.failed = false;

            // Positive evidence that the page went away. On iOS the
            // scheme prompt is a native alert: the page stays visible and
            // focused until the devotee answers it, so ONLY these signals
            // (or a suspended timer, below) prove anything either way.
            let left = false;
            const mark = () => { left = true; };
            const onVisibility = () => { if (document.visibilityState === 'hidden') left = true; };

            window.addEventListener('pagehide', mark);
            window.addEventListener('blur', mark);
            window.addEventListener('freeze', mark);
            document.addEventListener('visibilitychange', onVisibility);

            const startedAt = Date.now();
            window.location.href = this.link;

            setTimeout(() => {
                window.removeEventListener('pagehide', mark);
                window.removeEventListener('blur', mark);
                window.removeEventListener('freeze', mark);
                document.removeEventListener('visibilitychange', onVisibility);

                // A timer that fires far later than it was set means the
                // tab was frozen while the app was in front — treat that
                // as a successful handoff too.
                const suspended = (Date.now() - startedAt) > 4500;
                const focused = typeof document.hasFocus !== 'function' || document.hasFocus();
                const stillHere = ! left && ! suspended && focused
                    && document.visibilityState === 'visible';

                // Never navigate. Just offer a link the devotee can tap.
                if (stillHere) this.failed = true;
            }, 3000);
        },
    }"
    x-cloak
    class="fixed inset-x-0 bottom-0 z-[80] px-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pointer-events-none"
>
    <div x-show="visible"
         x-transition:enter="transition ease-out duration-500"
         x-transition:enter-start="opacity-0 translate-y-10"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 translate-y-10"
         role="region"
         aria-live="polite"
         :aria-label="thanks ? @js(__('app_banner.return_thanks_title')) : @js(__('app_banner.return_title'))"
         class="pointer-events-auto mx-auto max-w-md relative overflow-hidden rounded-3xl border border-[rgba(200,148,52,0.35)] shadow-[0_20px_50px_-12px_rgba(60,30,10,0.55)]"
         style="background: linear-gradient(135deg,#FFFCF5 0%,#FBEFE0 100%);">

        <div class="absolute -top-10 -right-10 w-32 h-32 rounded-full opacity-40 pointer-events-none"
             style="background: radial-gradient(circle, rgba(232,117,26,0.35), transparent 70%);"></div>

        <button @click="dismiss()"
                class="absolute top-2.5 right-2.5 z-10 w-7 h-7 flex items-center justify-center rounded-full text-stone-400 hover:text-stone-600 hover:bg-black/5 transition text-sm leading-none"
                aria-label="{{ __('app_banner.return_stay') }}">✕</button>

        <div class="relative flex items-center gap-4 p-4 pr-9">
            <span class="flex-none w-14 h-14 rounded-2xl overflow-hidden border-2 border-saffron-300 shadow-md bg-white">
                <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="" class="w-full h-full object-cover">
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-marcellus text-[15px] text-stone-700 leading-snug"
                   x-text="thanks ? @js(__('app_banner.return_thanks_title')) : @js(__('app_banner.return_title'))"></p>
                <p class="text-[12.5px] text-stone-500 leading-snug mt-0.5"
                   x-text="thanks ? @js(__('app_banner.return_thanks_body')) : @js(__('app_banner.return_body'))"></p>
            </div>
        </div>

        <div class="relative px-4 pb-4">
            @if($schemeEnabled)
                <button type="button" @click="openApp()"
                        class="btn-divine w-full flex items-center justify-center gap-2 py-3 text-sm">
                    {{ __('app_banner.return_cta') }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            @endif

            <button type="button" @click="dismiss()"
                    class="w-full mt-2 py-2 text-[12.5px] text-stone-500 hover:text-stone-700 transition">
                {{ __('app_banner.return_stay') }}
            </button>

            {{-- Only ever revealed after the checks above found the page
                 never went anywhere. Requires an explicit tap; when no
                 store URL is configured it degrades to plain advice. --}}
            <div x-show="failed" x-cloak x-transition
                 class="mt-2 rounded-xl bg-amber-100/70 border border-amber-300/60 px-3 py-2 text-[12px] text-stone-600 leading-snug">
                <span>{{ __('app_banner.return_failed') }}</span>
                <template x-if="store">
                    <a :href="store" target="_blank" rel="noopener"
                       class="block mt-1 font-semibold text-[#7A1E1E] underline">{{ __('app_banner.return_failed_store') }}</a>
                </template>
            </div>
        </div>
    </div>
</div>
@endif
