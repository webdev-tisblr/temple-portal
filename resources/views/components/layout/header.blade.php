{{--
    Site header — "Temple Header (1B)" design.
    Two tiers: a dark utility strip (timings · contact · language · login) that
    collapses on scroll, and a condensing main bar (wordmark · dropdown nav ·
    donate). Wired to real routes/i18n/auth; falls back gracefully.
--}}
@php
    // Dropdown groupings (top-level nav mirrors the design: Temple, Seva,
    // Events, Hall Booking, Store — Contact lives in the utility strip).
    // CMS pages are listed dynamically (published, top-level, admin order) so
    // adding/deleting a page in Admin → CMS Pages updates this menu — no more
    // hardcoded /parichay-style links pointing at deleted pages.
    $navLocale = app()->getLocale();
    $templeMenu = collect(\App\Models\Page::navPages())
        ->map(fn ($p) => [
            'label' => $p["title_{$navLocale}"] ?: $p['title_gu'],
            'url' => url('/' . $p['slug']),
        ])
        ->merge([
            ['label' => __('nav.trustees'), 'url' => route('trustees')],
            ['label' => __('nav.gallery'),  'url' => route('gallery')],
        ])
        ->values()
        ->all();

    // Live darshan/aarti line for the utility strip. Cached so it costs nothing
    // per request; null when the admin hasn't configured any active timing.
    $stripTiming = \Illuminate\Support\Facades\Cache::remember('header_strip_timing', 3600, function () {
        return \App\Models\DarshanTiming::where('is_active', true)
            ->orderByRaw("day_type = 'daily' desc")
            ->first();
    });
    $fmt = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('g:i A') : null;

    // Announcement ribbon state (Admin → Website Display). Computed here —
    // not in the partial — because the flow spacer below the header must
    // mirror the ribbon's visibility, and include vars don't leak upward.
    $display = \Illuminate\Support\Facades\Cache::remember('site.display.v1', 300, function () {
        return \App\Models\SystemSetting::query()
            ->where('key', 'like', 'site_%')
            ->pluck('value', 'key')
            ->all();
    });

    $rbEnabled = ($display['site_ribbon_enabled'] ?? '') === '1';
    $now = now();
    $rbFrom = $display['site_ribbon_starts_at'] ?? '';
    $rbTo = $display['site_ribbon_ends_at'] ?? '';
    $rbLive = $rbEnabled
        && ($rbFrom === '' || $now->greaterThanOrEqualTo($rbFrom))
        && ($rbTo === '' || $now->lessThanOrEqualTo($rbTo));

    $locale = app()->getLocale();
    $rbText = $display["site_ribbon_text_{$locale}"] ?? '';
    $rbText = $rbText !== '' ? $rbText : ($display['site_ribbon_text_gu'] ?? '');
    $rbLink = $display['site_ribbon_link'] ?? '';
    $rbKey = substr(sha1($rbText), 0, 8);
@endphp

<header x-data="{ mobileMenu: false, scrolled: false, m: null }"
        @scroll.window="scrolled = (window.scrollY > 40)"
        class="fixed top-0 left-0 right-0 z-50 {{ request()->routeIs('home') ? 'home-page' : '' }}">

    {{-- Announcement ribbon (Admin → Website Display) — lives inside the
         fixed header so it never overlaps the strip/nav. --}}
    @include('partials.site-ribbon')

    {{-- ===================== UTILITY STRIP (desktop) ===================== --}}
    <div :class="scrolled ? 'lg:h-0 lg:opacity-0 lg:pointer-events-none' : 'lg:h-10 lg:opacity-100'"
         class="hidden lg:block overflow-hidden transition-all duration-300"
         style="background:#241710;color:rgba(255,246,238,0.9);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-10 flex items-center justify-between text-[13px] whitespace-nowrap">
            <div class="flex items-center gap-2 min-w-0">
                <span style="color:#ECA557;">◈</span>
                @if($stripTiming && $stripTiming->morning_open && $stripTiming->evening_close)
                    <span class="truncate">{{ __('footer.darshan_times') }}: {{ $fmt($stripTiming->morning_open) }} – {{ $fmt($stripTiming->evening_close) }}@if($stripTiming->aarti_evening) · {{ __('footer.evening_aarti') }} {{ $fmt($stripTiming->aarti_evening) }}@endif</span>
                @else
                    <span class="truncate">{{ __('common.trust_subtitle') }}</span>
                @endif
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('contact') }}" class="hover:text-[#F2B673] transition-colors">{{ __('nav.contact') }}</a>
                <span class="opacity-40">|</span>
                {{-- Inline language links — a dropdown can't escape the strip's
                     overflow-hidden (used for the scroll-collapse), so use links. --}}
                <span class="inline-flex items-center gap-2">
                    @foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $code => $label)
                        <a href="{{ route('locale.set', $code) }}"
                           class="transition-colors {{ app()->getLocale() === $code ? 'text-[#F2B673] font-semibold' : 'text-white/85 hover:text-[#F2B673]' }}">{{ $label }}</a>
                        @if (!$loop->last)<span class="opacity-30">·</span>@endif
                    @endforeach
                </span>
                <span class="opacity-40">|</span>
                @auth('devotee')
                    {{-- Inline links (not an absolute dropdown): the strip is
                         overflow-hidden for the scroll-collapse, which clips
                         any absolutely-positioned menu — same reason the
                         language switcher above uses inline links.

                         Profile icon has three states (2026-08-09): photo /
                         initial + "add photo" nudge / namaste for guests.
                         Everything user-specific lives in THIS branch only —
                         CacheGuestResponse never caches a logged-in render,
                         so the cached copy is always the @else copy. --}}
                    @php
                        $hdrDevotee = auth('devotee')->user();
                        $hdrPhoto = $hdrDevotee?->profile_photo_path;
                        $hdrInitial = mb_substr(trim((string) $hdrDevotee?->name), 0, 1) ?: '॥';
                    @endphp
                    <span class="inline-flex items-center gap-2">
                        @if($hdrPhoto)
                            <a href="{{ route('dashboard.index') }}"
                               class="inline-flex items-center gap-1.5 hover:text-[#F2B673] transition-colors"
                               title="{{ __('nav.my_profile') }}" aria-label="{{ __('nav.my_profile') }}">
                                <img src="{{ image_url($hdrPhoto) }}" alt=""
                                     class="w-6 h-6 rounded-full object-cover object-center ring-1 ring-[#F2B673]/60 flex-none">
                                {{ __('nav.dashboard') }}
                            </a>
                        @else
                            {{-- No photo yet — an invitation, not an error: the
                                 avatar wears a small saffron "+" badge and links
                                 straight to the profile page where it's edited. --}}
                            <a href="{{ route('dashboard.profile') }}"
                               class="relative inline-flex items-center justify-center w-6 h-6 rounded-full bg-[#F2B673]/20 ring-1 ring-[#F2B673]/50 text-[11px] font-semibold text-[#F2B673] hover:bg-[#F2B673]/30 transition-colors flex-none"
                               title="{{ __('nav.add_profile_photo') }}" aria-label="{{ __('nav.add_profile_photo') }}">
                                <span aria-hidden="true">{{ $hdrInitial }}</span>
                                <span aria-hidden="true"
                                      class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-[#E8751A] text-white text-[8px] leading-[12px] text-center font-bold ring-1 ring-[#241710]">+</span>
                            </a>
                            <a href="{{ route('dashboard.index') }}" class="inline-flex items-center hover:text-[#F2B673] transition-colors">
                                {{ __('nav.dashboard') }}
                            </a>
                        @endif
                        <span class="opacity-30">·</span>
                        <form method="POST" action="{{ route('logout') }}" class="inline-flex">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-1.5 hover:text-red-400 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                {{ __('nav.logout') }}
                            </button>
                        </form>
                    </span>
                @else
                    {{-- Logged out — a "Namaste" (folded hands) glyph rather
                         than a generic person, drawn inline at the same 1.8
                         stroke weight as its neighbours. login_url() appends
                         the CURRENT page as ?next= so the OTP returns the
                         devotee here (item 3.1); still safe to guest-cache,
                         because CacheGuestResponse keys on the full path and
                         the value depends on nothing but that path. --}}
                    <a href="{{ login_url() }}" class="inline-flex items-center gap-1.5 hover:text-[#F2B673] transition-colors"
                       title="{{ __('nav.namaste_login') }}" aria-label="{{ __('nav.namaste_login') }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                            {{-- Plain user glyph. A namaste figure was tried here and
                                 rejected (2026-08-10): on the web this slot
                                 reads as "your account", so the conventional
                                 icon is the clearer one. --}}
                            <circle cx="12" cy="8" r="3.4"/>
                            <path d="M4.8 20.2c.9-3.6 3.7-5.6 7.2-5.6s6.3 2 7.2 5.6"/>
                        </svg>
                        {{ __('nav.login') }}
                    </a>
                @endauth
            </div>
        </div>
    </div>

    {{-- ===================== MAIN BAR ===================== --}}
    {{-- At rest the bar is transparent. On the home page (.home-page on the
         header) its nav items flip to cream so they stay legible over the
         hero image; every other page keeps the dark stone text. On scroll it
         condenses into the light parchment-glass bar with dark text. --}}
    <div :class="scrolled
            ? 'nav-scrolled bg-[rgba(251,245,234,0.82)] backdrop-blur-xl border-[rgba(122,30,30,0.10)] shadow-[0_12px_30px_-18px_rgba(60,30,10,0.5)] py-2.5'
            : 'nav-over-hero bg-transparent border-transparent py-3.5 lg:py-4'"
         class="border-b transition-all duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-6">

            {{-- Wordmark --}}
            <a href="{{ route('home') }}" class="flex items-center gap-3 flex-shrink-0 group">
                <span class="w-12 h-12 rounded-full border-2 border-saffron-400 overflow-hidden flex-none bg-parch-100 diya-glow">
                    <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="{{ __('common.temple_name') }}" class="w-full h-full object-cover">
                </span>
                <span class="leading-none">
                    <span class="hdr-item block font-marcellus text-lg lg:text-xl text-stone-700">{{ __('common.temple_name') }}</span>
                    <span class="hdr-sub block text-[10px] lg:text-[11px] text-saffron-500 font-semibold mt-1">{{ __('common.trust_subtitle') }}</span>
                </span>
            </a>

            {{-- Desktop nav --}}
            <nav class="hidden lg:flex items-center gap-7">
                <a href="{{ route('darshan') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.darshan') }}</a>
                {{-- Temple ▾ --}}
                <div class="relative" x-data="{ open:false, t:null }" @mouseenter="clearTimeout(t); open=true" @mouseleave="t=setTimeout(()=>open=false,180)">
                    <button class="hdr-item flex items-center gap-1.5 py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">
                        {{ __('nav.mandir') }}<svg class="w-2.5 h-2.5 opacity-55" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1.5" x-transition:enter-end="opacity-100 translate-y-0"
                         class="absolute top-full left-[-16px] pt-3 z-[60]">
                        <div class="min-w-[240px] rounded-2xl p-2 border" style="background:rgba(255,253,250,0.97);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);border-color:rgba(234,217,195,0.9);box-shadow:0 26px 46px -20px rgba(60,30,10,0.5);">
                            @foreach($templeMenu as $item)
                                <a href="{{ $item['url'] }}" class="block px-3.5 py-2.5 rounded-xl text-[15px] text-stone-700 whitespace-nowrap hover:bg-[#FBEFE2] hover:text-maroon-500 transition">{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </div>
                </div>
                {{-- Seva & Projects — separate top-level items (Donate has its own button). --}}
                <a href="{{ route('seva.index') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.seva') }}</a>
                <a href="{{ route('projects.index') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.projects') }}</a>
                <a href="{{ route('events.index') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.events') }}</a>
                <a href="{{ route('halls.index') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.halls') }}</a>
                <a href="{{ route('store.index') }}" class="hdr-item py-2 text-[15px] font-medium text-stone-700 hover:text-maroon-500 transition-colors">{{ __('nav.store') }}</a>
            </nav>

            {{-- Right cluster --}}
            <div class="flex items-center gap-2 sm:gap-3 flex-none">
                @auth('devotee')
                    <a href="{{ route('store.cart') }}" class="hdr-item relative hidden sm:flex items-center p-2 text-stone-600 hover:text-maroon-500 transition-colors" title="{{ __('nav.cart') }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                        @if(count(session('cart', [])) > 0)
                            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] flex items-center justify-center px-1 text-[10px] font-bold text-white bg-saffron-400 rounded-full leading-none">{{ array_sum(session('cart')) }}</span>
                        @endif
                    </a>
                @endauth
                <a href="{{ route('donate') }}" class="hidden sm:inline-flex btn-divine text-xs px-6 py-3">{{ __('nav.donate') }}</a>
                <button @click="mobileMenu = true" class="hdr-item lg:hidden p-2 text-stone-700 hover:text-maroon-500 transition" aria-label="{{ __('nav.menu') }}">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>
    </div>

    {{-- ===================== MOBILE DRAWER ===================== --}}
    <div x-show="mobileMenu" x-cloak class="fixed inset-0 z-[100] lg:hidden" @keydown.escape.window="mobileMenu = false">
        <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             class="absolute inset-0 bg-[rgba(20,12,8,0.5)] backdrop-blur-sm" @click="mobileMenu = false"></div>
        <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-end="translate-x-full"
             class="absolute right-0 top-0 bottom-0 w-[84%] max-w-sm flex flex-col shadow-2xl" style="background:#FBF6EF;">

            <div class="flex items-center justify-between px-5 py-4" style="background:#241710;color:#F3E9DC;">
                <span class="font-marcellus text-lg">{{ __('nav.menu') }}</span>
                <button @click="mobileMenu = false" class="p-1 text-[#F3E9DC]/80 hover:text-white text-xl leading-none">✕</button>
            </div>

            <div class="flex-1 overflow-y-auto px-3 py-2">
                <a href="{{ route('darshan') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.darshan') }}</a>
                {{-- Temple (accordion) --}}
                <div class="border-b border-[#F0E5D6]">
                    <button @click="m = (m === 'temple' ? null : 'temple')" class="w-full flex items-center justify-between px-3 py-3.5 text-base font-medium text-stone-700">
                        {{ __('nav.mandir') }}<svg class="w-3 h-3 opacity-55 transition-transform" :class="m==='temple' && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="m === 'temple'" x-transition class="pl-5 pb-2 flex flex-col gap-2.5">
                        @foreach($templeMenu as $item)
                            <a href="{{ $item['url'] }}" class="text-[15px] text-stone-500 hover:text-maroon-500 transition">{{ $item['label'] }}</a>
                        @endforeach
                    </div>
                </div>
                {{-- Seva & Projects — separate items (Donate has its own button below). --}}
                <a href="{{ route('seva.index') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.seva') }}</a>
                <a href="{{ route('projects.index') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.projects') }}</a>
                <a href="{{ route('events.index') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.events') }}</a>
                <a href="{{ route('halls.index') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.halls') }}</a>
                <a href="{{ route('store.index') }}" class="flex items-center justify-between px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">
                    <span>{{ __('nav.store') }}</span>
                    @auth('devotee')
                        @if(count(session('cart', [])) > 0)
                            <span class="min-w-[20px] h-[20px] flex items-center justify-center px-1.5 text-[10px] font-bold text-white bg-saffron-400 rounded-full leading-none">{{ array_sum(session('cart')) }}</span>
                        @endif
                    @endauth
                </a>
                <a href="{{ route('contact') }}" class="block px-3 py-3.5 text-base font-medium text-stone-700 border-b border-[#F0E5D6] hover:text-maroon-500">{{ __('nav.contact') }}</a>
            </div>

            <div class="px-4 pt-3.5 pb-5 border-t border-[#EAD9C3] space-y-3">
                <a href="{{ route('donate') }}" class="block w-full text-center btn-divine py-3.5">{{ __('nav.donate') }}</a>
                @auth('devotee')
                    <a href="{{ route('dashboard.index') }}" class="block w-full text-center py-2.5 text-sm text-maroon-500 border border-saffron-400/60 rounded-full hover:bg-[#FBEFE2] transition">{{ __('nav.dashboard') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full text-center py-2 text-xs text-stone-400 hover:text-red-500 transition">{{ __('nav.logout') }}</button>
                    </form>
                @else
                    {{-- Same three-state rule as the desktop strip: guests get
                         the namaste glyph, never a generic person icon. --}}
                    <a href="{{ login_url() }}" class="flex w-full items-center justify-center gap-2 py-2.5 text-sm text-maroon-500 border border-saffron-400/60 rounded-full hover:bg-[#FBEFE2] transition"
                       title="{{ __('nav.namaste_login') }}" aria-label="{{ __('nav.namaste_login') }}">
                        <svg class="w-5 h-5 flex-none" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                            {{-- Plain user glyph. A namaste figure was tried here and
                                 rejected (2026-08-10): on the web this slot
                                 reads as "your account", so the conventional
                                 icon is the clearer one. --}}
                            <circle cx="12" cy="8" r="3.4"/>
                            <path d="M4.8 20.2c.9-3.6 3.7-5.6 7.2-5.6s6.3 2 7.2 5.6"/>
                        </svg>
                        {{ __('nav.login') }}
                    </a>
                @endauth
                {{-- Language (mobile) --}}
                <div class="pt-3 border-t border-[#EAD9C3]">
                    <p class="pb-2 text-[11px] text-saffron-500">{{ __('common.language') }}</p>
                    <div class="flex gap-2">
                        @foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $code => $label)
                            <a href="{{ route('locale.set', $code) }}"
                               class="flex-1 text-center py-2 text-sm rounded-full border transition {{ app()->getLocale() === $code ? 'text-maroon-500 border-saffron-400 bg-[#FBEFE2] font-semibold' : 'text-stone-500 border-[#EAD9C3] hover:text-maroon-500' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>

{{-- Spacer: clears the un-scrolled header (strip 40px + bar) on desktop,
     the compact bar on mobile. --}}
{{-- Ribbon spacer: mirrors the in-header ribbon's height (h-9 on both) so
     the fixed header never overlaps content while the ribbon is live.
     Dismiss stays in sync via the ribbon's $dispatch('ribbon-dismissed'). --}}
@if($rbLive && $rbText !== '')
    <div x-data="{ open: localStorage.getItem('ribbon_{{ $rbKey }}') !== '1' }"
         x-show="open" x-cloak
         @ribbon-dismissed.window="open = false"
         class="h-9" aria-hidden="true"></div>
@endif
<div class="h-16 lg:h-[120px]"></div>
