@extends('layouts.app')

@section('content')

{{-- =================================================================
     HERO — admin slider when slides exist, static identity hero otherwise
     ================================================================= --}}
@if(isset($heroSlides) && $heroSlides->isNotEmpty())
    @php($heroLocale = app()->getLocale())
    <section class="relative min-h-[72vh] overflow-hidden -mt-16 lg:-mt-20"
             style="background:#FBF5EA;"
             x-data="{
                current: 0,
                count: {{ $heroSlides->count() }},
                durations: @js($heroSlides->pluck('duration_seconds')->map(fn ($d) => max(3, (int) $d) * 1000)),
                timer: null,
                touchX: null,
                go(i) { this.current = (i + this.count) % this.count; this.arm(); },
                next() { this.go(this.current + 1) },
                prev() { this.go(this.current - 1) },
                arm() { clearTimeout(this.timer); if (this.count > 1) this.timer = setTimeout(() => this.next(), this.durations[this.current]); },
                init() { this.arm(); },
             }"
             @touchstart.passive="touchX = $event.touches[0].clientX"
             @touchend.passive="if (touchX !== null) { const dx = $event.changedTouches[0].clientX - touchX; if (dx > 45) prev(); else if (dx < -45) next(); touchX = null; }">
        @foreach($heroSlides as $i => $slide)
            @php
                $alignWrap = match ($slide->align) {
                    'left' => 'items-start text-left',
                    'right' => 'items-end text-right',
                    default => 'items-center text-center',
                };
                $isLight = $slide->theme === 'light';
                $veil = $isLight
                    ? 'rgba(0,0,0,' . ($slide->overlay_opacity / 100) . ')'
                    : 'rgba(251,245,234,' . ($slide->overlay_opacity / 100) . ')';
                $headColor = $isLight ? '#FFFFFF' : '#7A1E1E';
                $subColor = $isLight ? 'rgba(255,255,255,0.88)' : '#5E4F3D';
            @endphp
            <div x-show="current === {{ $i }}"
                 x-cloak
                 @if($slide->transition === 'slide')
                     x-transition:enter="transition-transform ease-out duration-700"
                     x-transition:enter-start="translate-x-full"
                     x-transition:enter-end="translate-x-0"
                     x-transition:leave="transition-transform ease-in duration-700 absolute"
                     x-transition:leave-start="translate-x-0"
                     x-transition:leave-end="-translate-x-full"
                 @else
                     x-transition:enter="transition-opacity ease-out duration-1000"
                     x-transition:enter-start="opacity-0"
                     x-transition:enter-end="opacity-100"
                     x-transition:leave="transition-opacity ease-in duration-700 absolute"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                 @endif
                 class="absolute inset-0">
                {{-- Slide image (mobile variant when provided) --}}
                <picture>
                    @if($slide->image_path_mobile)
                        <source media="(max-width: 640px)" srcset="{{ image_url($slide->image_path_mobile) }}">
                    @endif
                    <img src="{{ image_url($slide->image_path) }}"
                         alt="{{ $slide->headingFor($heroLocale) ?? '' }}"
                         class="w-full h-full object-cover object-center {{ $slide->transition === 'zoom' ? 'hero-kenburns' : '' }}"
                         @if($slide->transition === 'zoom') style="animation-duration: {{ max(3, $slide->duration_seconds) + 2 }}s;" @endif>
                </picture>
                <div class="absolute inset-0" style="background: {{ $veil }};"></div>
                <div class="absolute inset-0" style="background: linear-gradient(to top, #FBF5EA, transparent 30%);"></div>

                {{-- Text block --}}
                <div class="absolute inset-0 flex flex-col justify-center {{ $alignWrap }} px-6 sm:px-14 lg:px-24 pt-20 pb-16">
                    <div class="max-w-2xl">
                        @if($slide->headingFor($heroLocale))
                            <h2 class="text-3xl sm:text-5xl font-black leading-tight drop-shadow-sm" style="color: {{ $headColor }};">
                                {{ $slide->headingFor($heroLocale) }}
                            </h2>
                        @endif
                        @if($slide->subFor($heroLocale))
                            <p class="mt-4 text-base sm:text-lg leading-relaxed" style="color: {{ $subColor }};">
                                {{ $slide->subFor($heroLocale) }}
                            </p>
                        @endif
                        @if($slide->ctaLabelFor($heroLocale) && $slide->cta_url)
                            <a href="{{ $slide->cta_url }}"
                               class="inline-block mt-6 px-8 py-3 rounded-full text-sm sm:text-base font-bold text-white shadow-lg transition hover:opacity-90 hover:shadow-xl"
                               style="background: linear-gradient(90deg, #E8751A, #C89434);">
                                {{ $slide->ctaLabelFor($heroLocale) }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        @if($heroSlides->count() > 1)
            {{-- Arrows (desktop) --}}
            <button type="button" @click="prev()" aria-label="Previous"
                    class="hidden sm:flex absolute left-4 top-1/2 -translate-y-1/2 z-20 w-11 h-11 items-center justify-center rounded-full bg-black/25 text-white hover:bg-black/45 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button type="button" @click="next()" aria-label="Next"
                    class="hidden sm:flex absolute right-4 top-1/2 -translate-y-1/2 z-20 w-11 h-11 items-center justify-center rounded-full bg-black/25 text-white hover:bg-black/45 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
            </button>
            {{-- Dots --}}
            <div class="absolute bottom-6 left-1/2 -translate-x-1/2 z-20 flex gap-2.5">
                @foreach($heroSlides as $i => $slide)
                    <button type="button" @click="go({{ $i }})" aria-label="Slide {{ $i + 1 }}"
                            class="w-2.5 h-2.5 rounded-full transition-all"
                            :class="current === {{ $i }} ? 'w-7' : 'opacity-50'"
                            style="background:#C45F12;"></button>
                @endforeach
            </div>
        @endif

        @push('head')
        <style>
            .hero-kenburns { animation-name: heroKenburns; animation-timing-function: ease-out; animation-fill-mode: forwards; }
            @keyframes heroKenburns { from { transform: scale(1); } to { transform: scale(1.08); } }
        </style>
        @endpush
    </section>
@else
<section class="relative min-h-[72vh] flex items-center justify-center overflow-hidden -mt-16 lg:-mt-20"
         style="background: #FBF5EA;">

    {{-- Hanumanji background photo, gently faded into parchment --}}
    <div class="absolute inset-0">
        <img src="{{ asset('images/hanumanji-hero.jpg') }}"
             alt="{{ __('common.temple_name') }}"
             class="w-full h-full object-cover object-center"
             style="opacity: 0.30;">
        {{-- Parchment veil — keeps image readable as background --}}
        <div class="absolute inset-0"
             style="background: linear-gradient(to bottom,
                rgba(251,245,234,0.55) 0%,
                rgba(251,245,234,0.25) 35%,
                rgba(251,245,234,0.55) 70%,
                rgba(251,245,234,0.98) 100%);"></div>
        {{-- Saffron radial glow over the murti --}}
        <div class="absolute inset-0"
             style="background: radial-gradient(ellipse at center 38%, rgba(232,117,26,0.10) 0%, transparent 60%);"></div>
    </div>

    {{-- Floating divine particles --}}
    <div class="absolute inset-0 pointer-events-none" x-data="divineParticles"></div>

    {{-- Soft gold halo from bottom --}}
    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[820px] h-[360px] rounded-full opacity-30"
         style="background: radial-gradient(ellipse, rgba(200,148,52,0.35), transparent 70%);"></div>

    {{-- Content --}}
    <div class="relative z-10 text-center px-4 pt-24 pb-12 sm:pt-28 sm:pb-16 max-w-4xl mx-auto">

        {{-- Sacred badge --}}
        <div class="inline-flex items-center gap-2 px-5 py-2 rounded-full border mb-6"
             style="background: rgba(232,117,26,0.10); border-color: rgba(200,148,52,0.45);">
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
            <span class="text-sm tracking-[0.2em] uppercase font-medium" style="color: #7A1E1E;">{{ __('home.hero_jai') }}</span>
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
        </div>

        {{-- Name --}}
        <h1 class="text-5xl sm:text-7xl lg:text-8xl font-black leading-[1.05] tracking-tight">
            <span style="color: #7A1E1E;">{{ __('home.hero_line1') }}</span><br>
            <span style="color: #C45F12;">{{ __('home.hero_line2') }}</span>
        </h1>

        <p class="mt-6 text-lg sm:text-xl font-light tracking-wide" style="color: #5E4F3D;">
            {{ __('common.address') }}
        </p>

        {{-- Ornamental divider --}}
        <div class="divine-divider">
            <span class="text-xs" style="color: #C89434;">✦</span>
        </div>

        {{-- Today-open status pill (lightweight client computation from
             the regular timing record). --}}
        @if($timings)
            @php
                $now = now();
                $h = (int) $now->format('H');
                $m = (int) $now->format('i');
                $cur = $h * 60 + $m;
                $win = function ($open, $close) use ($cur) {
                    if (! $open || ! $close) return false;
                    [$oh, $om] = array_pad(explode(':', (string) $open), 2, 0);
                    [$ch, $cm] = array_pad(explode(':', (string) $close), 2, 0);
                    return $cur >= ((int) $oh * 60 + (int) $om) && $cur < ((int) $ch * 60 + (int) $cm);
                };
                $isOpen = $win($timings->morning_open, $timings->morning_close)
                       || $win($timings->afternoon_open, $timings->afternoon_close)
                       || $win($timings->evening_open, $timings->evening_close);
            @endphp
            <a href="{{ route('darshan') }}"
               class="inline-flex items-center gap-2 mt-2 px-4 py-2 rounded-full text-sm font-semibold transition hover:shadow-lg"
               style="background: {{ $isOpen ? 'rgba(63,122,63,0.14)' : 'rgba(168,50,50,0.10)' }};
                      color: {{ $isOpen ? '#2D5F2D' : '#7A1E1E' }};
                      border: 1px solid {{ $isOpen ? 'rgba(63,122,63,0.40)' : 'rgba(168,50,50,0.40)' }};">
                <span class="relative flex h-2.5 w-2.5">
                    @if($isOpen)
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full opacity-75" style="background: #3F7A3F;"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background: #3F7A3F;"></span>
                    @else
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5" style="background: #A83232;"></span>
                    @endif
                </span>
                {{ $isOpen ? __('home.temple_open') : __('home.temple_closed') }}
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endif

        {{-- CTA row --}}
        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="{{ route('donate') }}" class="w-full sm:w-auto btn-divine text-base px-10 py-4">
                🪔 {{ __('nav.donate') }}
            </a>
            <a href="{{ route('darshan') }}#live" class="w-full sm:w-auto btn-temple text-base px-10 py-4">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                {{ __('home.live_darshan') }}
            </a>
        </div>
    </div>

    {{-- Bottom fade into surface --}}
    <div class="absolute bottom-0 left-0 right-0 h-20"
         style="background: linear-gradient(to top, #FBF5EA, transparent);"></div>
</section>
@endif

{{-- =================================================================
     ACTION TILES — quick-access to the most-used flows.
     Mirrors the mobile app's tile grid; horizontally scrollable on phones.
     ================================================================= --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-2 sm:-mt-4 pt-2 pb-10 relative z-10">
    @php
        // SVG icon strokes match the mobile app's Material outlines for
        // the same actions (volunteer_activism / favorite / visibility /
        // event / shopping_bag / account_balance). Each tile renders the
        // path inside a saffron-tinted chip so the tile has weight even
        // when the screen is wide.
        $tiles = [
            [
                'href' => route('seva.index'),
                'label' => __('nav.seva'),
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4.5c-1.5 1.7-3 3.4-3 5.6 0 1.66 1.34 3 3 3s3-1.34 3-3c0-2.2-1.5-3.9-3-5.6zM5 14v2a2 2 0 002 2h2.5l1.5 2h2l1.5-2H17a2 2 0 002-2v-2c0-.55-.45-1-1-1H6c-.55 0-1 .45-1 1z"/>',
            ],
            [
                'href' => route('donate'),
                'label' => __('home.daan'),
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>',
            ],
            [
                'href' => route('darshan'),
                'label' => __('nav.darshan'),
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M1.5 12s4-7.5 10.5-7.5S22.5 12 22.5 12s-4 7.5-10.5 7.5S1.5 12 1.5 12z"/><circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6"/>',
            ],
            [
                'href' => route('events.index'),
                'label' => __('nav.events'),
                'svg' => '<rect x="3" y="4.5" width="18" height="16" rx="2" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 9.5h18M8 3v3M16 3v3"/>',
            ],
            [
                'href' => route('store.index'),
                'label' => __('nav.store'),
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6 7h12l-1 13H7L6 7zM9 7V5a3 3 0 016 0v2"/>',
            ],
            [
                'href' => route('halls.index'),
                'label' => __('home.hall'),
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 20.5h18M4 20.5V10l8-5 8 5v10.5M8 20.5V13m4 7.5V13m4 7.5V13"/>',
            ],
        ];
    @endphp

    <div class="grid grid-cols-3 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @foreach($tiles as $tile)
            <a href="{{ $tile['href'] }}"
               class="card-sacred flex flex-col items-center justify-center gap-2.5 py-5 px-3 text-center transition hover:-translate-y-0.5">
                <span class="flex items-center justify-center w-12 h-12 rounded-2xl"
                      style="background: rgba(232,117,26,0.10); color: #C45F12;">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {!! $tile['svg'] !!}
                    </svg>
                </span>
                <span class="text-sm font-semibold" style="color: #7A1E1E;">{{ $tile['label'] }}</span>
            </a>
        @endforeach
    </div>
</section>

{{-- =================================================================
     DARSHAN — 2-column compact card (morning + evening side-by-side
     plus aarti-times callout). Replaces the older 3-up grid.
     ================================================================= --}}
@if($timings)
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="text-center mb-8">
        <div class="divine-divider"><span style="color: #C89434;">🪔</span></div>
        <h2 class="divine-heading">{{ __('footer.darshan_times') }}</h2>
        <p class="divine-subtext">{{ __('home.darshan_sub') }}</p>
    </div>

    {{-- Four time-tiles in a single row on desktop, 2×2 on mobile.
         Each tile is a self-contained card with its own icon chip /
         label / time — visually heavier and faster to scan than the
         old two-column block-with-aarti-row layout. --}}
    @php
        $slots = [
            [
                'label' => __('home.morning_darshan'),
                'time' => $timings->morning_open ? \Carbon\Carbon::parse($timings->morning_open)->format('h:i A') : null,
                'subtitle' => $timings->morning_close ? __('home.till_prefix') . \Carbon\Carbon::parse($timings->morning_close)->format('h:i A') : null,
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3v2M12 19v2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M3 12h2M19 12h2M5.6 18.4 7 17M17 7l1.4-1.4"/><circle cx="12" cy="12" r="4" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7"/>',
            ],
            [
                'label' => __('home.morning_aarti'),
                'time' => $timings->aarti_morning ? \Carbon\Carbon::parse($timings->aarti_morning)->format('h:i A') : null,
                'subtitle' => null,
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3c0 2-2 2.5-2 5a2 2 0 104 0c0-2.5-2-3-2-5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M6 14h12a2 2 0 01-2 2H8a2 2 0 01-2-2zM4 19h16"/>',
            ],
            [
                'label' => __('home.evening_aarti'),
                'time' => $timings->aarti_evening ? \Carbon\Carbon::parse($timings->aarti_evening)->format('h:i A') : null,
                'subtitle' => null,
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3c0 2-2 2.5-2 5a2 2 0 104 0c0-2.5-2-3-2-5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M6 14h12a2 2 0 01-2 2H8a2 2 0 01-2-2zM4 19h16"/>',
            ],
            [
                'label' => __('home.evening_darshan'),
                'time' => $timings->evening_open ? \Carbon\Carbon::parse($timings->evening_open)->format('h:i A') : null,
                'subtitle' => $timings->evening_close ? __('home.till_prefix') . \Carbon\Carbon::parse($timings->evening_close)->format('h:i A') : null,
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M20 14.5A8 8 0 119.5 4 7 7 0 0020 14.5z"/>',
            ],
        ];
        // Drop any slot the temple hasn't configured (e.g. no morning aarti).
        $slots = array_values(array_filter($slots, fn ($s) => ! empty($s['time'])));
    @endphp

    <div class="grid grid-cols-2 lg:grid-cols-{{ count($slots) >= 4 ? 4 : count($slots) }} gap-3 sm:gap-4">
        @foreach($slots as $slot)
            <div class="card-sacred p-5 text-center inner-glow">
                <span class="flex items-center justify-center w-12 h-12 rounded-2xl mx-auto mb-3"
                      style="background: rgba(232,117,26,0.10); color: #C45F12;">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        {!! $slot['svg'] !!}
                    </svg>
                </span>
                <p class="text-[11px] uppercase tracking-[0.2em] font-bold" style="color: #C45F12;">{{ $slot['label'] }}</p>
                <p class="text-2xl sm:text-3xl font-black mt-1.5 tabular-nums" style="color: #7A1E1E;">{{ $slot['time'] }}</p>
                @if($slot['subtitle'])
                    <p class="text-xs mt-1" style="color: #5E4F3D;">{{ $slot['subtitle'] }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="text-center mt-7">
        <a href="{{ route('darshan') }}" class="text-sm font-semibold inline-flex items-center gap-1 hover:underline"
           style="color: #C45F12;">
            {{ __('home.view_full_schedule') }}
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>
</section>
@endif

{{-- =================================================================
     ACTIVE CAMPAIGNS — adaptive layout:
       1 campaign  → single full-width hero card
       2 campaigns → 2-up grid (each card full-width on mobile)
       3+ campaigns → horizontal scroll-snap carousel (3-up desktop,
                      1-up mobile) with prev/next arrows
     ================================================================= --}}
@if(isset($campaigns) && $campaigns->isNotEmpty())
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="text-center mb-8">
        <div class="divine-divider"><span style="color: #C89434;">🪔</span></div>
        <h2 class="divine-heading">{{ __('home.contribution_campaigns') }}</h2>
        <p class="divine-subtext">{{ __('home.campaigns_sub') }}</p>
    </div>

    @php
        // Reusable card markup factored out so we don't repeat it
        // three times across the three layout branches below.
        // (Couldn't @include a partial without making a new file —
        // an inline closure keeps the home page self-contained.)
    @endphp

    @if($campaigns->count() === 1)
        {{-- Single full-width card --}}
        @php $c = $campaigns->first(); @endphp
        @php
            $raised = (float) $c->raised_amount;
            $goal = (float) $c->goal_amount;
            $pct = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
        @endphp
        <div class="card-sacred p-8 sm:p-10 inner-glow max-w-5xl mx-auto">
            <div class="text-center mb-6">
                <p class="text-xs uppercase tracking-[0.25em] font-bold" style="color: #C45F12;">{{ __('home.featured_campaign') }}</p>
                <h3 class="divine-heading text-2xl sm:text-3xl mt-2">{{ $c->title }}</h3>
                @if($c->description)
                    <p class="mt-3 max-w-2xl mx-auto" style="color: #5E4F3D;">{{ text_preview($c->description, 200) }}</p>
                @endif
            </div>
            <div class="max-w-md mx-auto mt-6">
                <div class="flex items-baseline justify-between text-sm mb-2">
                    <span class="font-black text-xl" style="color: #7A1E1E;">₹{{ number_format($raised) }}</span>
                    <span style="color: #5E4F3D;">/ ₹{{ number_format($goal) }}</span>
                </div>
                <div class="w-full h-3 rounded-full overflow-hidden" style="background: rgba(200,148,52,0.18);">
                    <div class="h-full rounded-full transition-all duration-1000" style="width: {{ $pct }}%; background: linear-gradient(90deg, #E8751A, #C89434);"></div>
                </div>
                <p class="text-xs mt-2 flex items-center justify-between" style="color: #5E4F3D;">
                    <span>{{ $pct }}% {{ __('home.complete') }}</span>
                    <span>{{ $c->donor_count ?? 0 }} {{ __('home.donors_contributed') }}</span>
                </p>
            </div>
            <div class="text-center mt-8">
                <a href="{{ route('projects.show', $c->slug) }}" class="btn-divine text-base px-10 py-3.5">🙏 {{ __('home.contribute') }}</a>
            </div>
        </div>

    @elseif($campaigns->count() === 2)
        {{-- 2-up full-width grid --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-6xl mx-auto">
            @foreach($campaigns as $c)
                @include('components.home-campaign-card', ['campaign' => $c])
            @endforeach
        </div>

    @else
        {{-- 3+ → scroll-snap carousel. Each card is min-w-[300px] on
             phones (full viewport width within the gutter), 1/3 on
             desktop. Prev/Next arrows drive scrollBy. --}}
        <div x-data="{
                scrollByCard(dir) {
                    const el = this.$refs.track;
                    const card = el.querySelector('[data-campaign-card]');
                    const step = card ? card.offsetWidth + 24 : 320;
                    el.scrollBy({ left: dir * step, behavior: 'smooth' });
                }
            }"
            class="relative">

            <div x-ref="track"
                 class="flex gap-6 overflow-x-auto snap-x snap-mandatory pb-3"
                 style="scrollbar-width: thin; scroll-padding: 0 1rem;">
                @foreach($campaigns as $c)
                    <div data-campaign-card
                         class="snap-start flex-shrink-0 w-[85%] sm:w-[60%] lg:w-[calc((100%-3rem)/3)]">
                        @include('components.home-campaign-card', ['campaign' => $c])
                    </div>
                @endforeach
            </div>

            <button @click="scrollByCard(-1)" aria-label="Previous"
                class="hidden md:flex absolute -left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full items-center justify-center transition hover:shadow-lg"
                style="background: #FFFCF5; color: #7A1E1E; border: 1px solid rgba(122,30,30,0.15); box-shadow: 0 4px 14px rgba(122,30,30,0.10);">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <button @click="scrollByCard(1)" aria-label="Next"
                class="hidden md:flex absolute -right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full items-center justify-center transition hover:shadow-lg"
                style="background: #FFFCF5; color: #7A1E1E; border: 1px solid rgba(122,30,30,0.15); box-shadow: 0 4px 14px rgba(122,30,30,0.10);">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
    @endif

    <div class="text-center mt-8">
        <a href="{{ route('projects.index') }}" class="btn-temple">
            {{ __('home.view_all_projects') }}
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
        </a>
    </div>
</section>
@endif

{{-- =================================================================
     SEVA & POOJA — 3-up grid using existing seva-card component.
     ================================================================= --}}
@if($sevas->isNotEmpty())
<section class="py-12 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">🙏</span></div>
            <h2 class="divine-heading">{{ __('home.seva_puja') }}</h2>
            <p class="divine-subtext">{{ __('home.seva_sub') }}</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($sevas as $seva)
                @include('components.seva-card', ['seva' => $seva])
            @endforeach
        </div>
        <div class="text-center mt-10">
            <a href="{{ route('seva.index') }}" class="btn-temple">
                {{ __('home.view_all_sevas') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     UPCOMING EVENTS — 3-up scroll-snap carousel.
     ≤3 → plain grid; 4+ → carousel with prev/next arrows.
     ================================================================= --}}
@if($events->isNotEmpty())
<section class="py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">📿</span></div>
            <h2 class="divine-heading">{{ __('home.upcoming_events') }}</h2>
            <p class="divine-subtext">{{ __('home.events_sub') }}</p>
        </div>

        @if($events->count() <= 3)
            <div class="grid grid-cols-1 md:grid-cols-{{ $events->count() }} gap-6 max-w-5xl mx-auto">
                @foreach($events as $event)
                    @include('components.home-event-card', ['event' => $event])
                @endforeach
            </div>
        @else
            <div x-data="{
                    scrollByCard(dir) {
                        const el = this.$refs.track;
                        const card = el.querySelector('[data-event-card]');
                        const step = card ? card.offsetWidth + 24 : 320;
                        el.scrollBy({ left: dir * step, behavior: 'smooth' });
                    }
                }" class="relative">

                <div x-ref="track"
                     class="flex gap-6 overflow-x-auto snap-x snap-mandatory pb-3"
                     style="scrollbar-width: thin; scroll-padding: 0 1rem;">
                    @foreach($events as $event)
                        <div data-event-card
                             class="snap-start flex-shrink-0 w-[85%] sm:w-[60%] lg:w-[calc((100%-3rem)/3)]">
                            @include('components.home-event-card', ['event' => $event])
                        </div>
                    @endforeach
                </div>

                <button @click="scrollByCard(-1)" aria-label="Previous"
                    class="hidden md:flex absolute -left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full items-center justify-center transition hover:shadow-lg"
                    style="background: #FFFCF5; color: #7A1E1E; border: 1px solid rgba(122,30,30,0.15); box-shadow: 0 4px 14px rgba(122,30,30,0.10);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button @click="scrollByCard(1)" aria-label="Next"
                    class="hidden md:flex absolute -right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full items-center justify-center transition hover:shadow-lg"
                    style="background: #FFFCF5; color: #7A1E1E; border: 1px solid rgba(122,30,30,0.15); box-shadow: 0 4px 14px rgba(122,30,30,0.10);">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        @endif

        <div class="text-center mt-10">
            <a href="{{ route('events.index') }}" class="btn-temple">
                {{ __('home.view_all_events') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     GALLERY PREVIEW — masonry strip of recent images.
     ================================================================= --}}
@if($galleryPreview->isNotEmpty())
<section class="py-12 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">📸</span></div>
            <h2 class="divine-heading">{{ __('nav.gallery') }}</h2>
            <p class="divine-subtext">{{ __('home.gallery_sub') }}</p>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
            @foreach($galleryPreview as $img)
                @php($thumb = $img->thumb_url)
                @if($thumb)
                    <a href="{{ route('gallery') }}"
                       class="block aspect-square overflow-hidden rounded-xl border group relative"
                       style="border-color: rgba(122,30,30,0.12);">
                        <img src="{{ $thumb }}"
                             alt="{{ $img->title }}"
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        @if(($img->type ?? 'photo') === 'video')
                            <span class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                <span class="w-10 h-10 rounded-full bg-black/50 border border-white/40 flex items-center justify-center">
                                    <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                </span>
                            </span>
                        @endif
                    </a>
                @endif
            @endforeach
        </div>

        <div class="text-center mt-8">
            <a href="{{ route('gallery') }}" class="btn-temple">
                {{ __('home.view_all_photos') }}
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- Parichay (about) section intentionally removed from the home
     page — full content lives at /parichay and is reachable from the
     header nav + footer quick-links. --}}

{{-- =================================================================
     VISIT US — address + map + contact.
     ================================================================= --}}
<section class="py-14 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">📍</span></div>
            <h2 class="divine-heading">{{ __('home.come_for_darshan') }}</h2>
            <p class="divine-subtext">{{ __('common.address') }}</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- Address --}}
            <div class="card-sacred p-6 flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">{{ __('common.address_label') }}</p>
                    <p class="text-sm leading-relaxed" style="color: #3E3226;">
                        {{ __('common.trust_full') }}<br>
                        {{ __('common.address') }}
                    </p>
                </div>
            </div>

            {{-- Phone --}}
            <div class="card-sacred p-6 flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">{{ __('nav.contact') }}</p>
                    <a href="{{ route('contact') }}" class="text-sm font-semibold hover:underline" style="color: #7A1E1E;">
                        {{ __('home.phone_email') }}
                    </a>
                    <p class="text-xs mt-2" style="color: #5E4F3D;">{{ __('home.contact_prompt') }}</p>
                </div>
            </div>

            {{-- Hours --}}
            <div class="card-sacred p-6 flex items-start gap-3">
                <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">{{ __('footer.darshan_times') }}</p>
                    @if($timings)
                        <p class="text-sm font-semibold" style="color: #7A1E1E;">
                            {{ __('footer.morning') }} {{ \Carbon\Carbon::parse($timings->morning_open)->format('h:i') }}
                            – {{ \Carbon\Carbon::parse($timings->morning_close)->format('h:i A') }}
                        </p>
                        @if($timings->evening_open && $timings->evening_close)
                            <p class="text-sm font-semibold mt-1" style="color: #7A1E1E;">
                                {{ __('footer.evening') }} {{ \Carbon\Carbon::parse($timings->evening_open)->format('h:i') }}
                                – {{ \Carbon\Carbon::parse($timings->evening_close)->format('h:i A') }}
                            </p>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-3xl overflow-hidden border mt-8"
             style="border-color: rgba(122,30,30,0.15); box-shadow: 0 10px 40px rgba(122,30,30,0.10);">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3670.0!2d70.13!3d23.08!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMjPCsDA0JzQ4LjAiTiA3MMKwMDcnNDguMCJF!5e0!3m2!1sen!2sin!4v1"
                    width="100%" height="380" style="border:0;" allowfullscreen="" loading="lazy" class="w-full"></iframe>
        </div>
    </div>
</section>

@endsection
