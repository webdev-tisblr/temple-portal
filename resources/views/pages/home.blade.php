@extends('layouts.app')

@section('content')

@php
    $loc = app()->getLocale();
    $isOpenNow = $schedule['is_open'] ?? false;
    $fmtT = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('h:i A') : null;

    // Hero background (Admin → Home Page Settings): one image OR one video.
    $hero = $hero ?? [];
    $pickLoc = function (array $bag) use ($loc) {
        $v = $bag[$loc] ?? '';
        return $v !== '' ? $v : ($bag['gu'] ?? '');
    };
    $heroImg = !empty($hero['image']) ? image_url($hero['image']) : asset('images/hanumanji-hero.jpg');
    $heroOverlay = isset($hero['overlay']) ? max(0, min(90, (int) $hero['overlay'])) : 40;
    $heroHeading = $pickLoc($hero['heading'] ?? []);
    $heroHeadingText = $heroHeading !== '' ? $heroHeading : __('common.temple_name');
    $heroSub = $pickLoc($hero['sub'] ?? []);
    $heroSubText = $heroSub !== '' ? $heroSub : __('home.hero_subtitle');

    // Resolve the video source into either a direct file or an embed iframe.
    // Audio + controls are admin-configurable (Home Page Settings). Note:
    // browsers block autoplay-with-sound until the visitor interacts, so
    // "sound on" realistically needs controls shown too.
    $heroIsVideo = ($hero['media_type'] ?? 'image') === 'video';
    $heroAudio = !empty($hero['video_audio']);
    $heroControls = !empty($hero['video_controls']);
    $heroVideoFile = null; $heroVideoIframe = null; $heroYtId = null;
    if ($heroIsVideo) {
        if (($hero['video_type'] ?? 'upload') === 'upload' && !empty($hero['video_file'])) {
            $heroVideoFile = image_url($hero['video_file']);
        } elseif (($hero['video_type'] ?? '') === 'url' && !empty($hero['video_url'])) {
            $u = $hero['video_url'];
            if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{6,})~', $u, $m)) {
                // The YouTube IFrame API builds the player into a div (most
                // reliable). We pass the id + mute flag; the JS wires play/pause.
                $heroYtId = $m[1];
                $heroVideoKind = 'youtube';
            } elseif (preg_match('~vimeo\.com/(\d+)~', $u, $m)) {
                $muted = $heroAudio ? '0' : '1';
                // background=1 forces muted + hides the API; only use it when the
                // admin wants neither sound nor a play/pause button.
                $heroVideoIframe = ($heroAudio || $heroControls)
                    ? "https://player.vimeo.com/video/{$m[1]}?autoplay=1&muted={$muted}&loop=1&controls=0"
                    : "https://player.vimeo.com/video/{$m[1]}?autoplay=1&muted=1&loop=1&background=1";
                $heroVideoKind = 'vimeo';
            } else {
                $heroVideoFile = $u; // assume a direct .mp4/.webm link
            }
        }
    }
    $heroVideoKind = $heroVideoKind ?? 'file';
    $heroHasVideo = $heroVideoFile || $heroVideoIframe || $heroYtId;
@endphp

{{-- =================================================================
     HERO
     ================================================================= --}}
<section class="hero-section group relative -mt-16 lg:-mt-20 min-h-[95vh] flex items-end overflow-hidden">
    @if($heroIsVideo && $heroHasVideo)
        {{-- Video background. Chrome is hidden; playback is driven by the custom
             button below via the provider JS API (YouTube/Vimeo) or the native
             element. The media is pointer-events-none so it never steals clicks
             from the hero CTAs. --}}
        <div class="hero-video absolute inset-0 overflow-hidden pointer-events-none"
             data-kind="{{ $heroVideoKind }}">
            @if($heroVideoKind === 'youtube')
                {{-- The YouTube IFrame API replaces this div with the player;
                     the resulting iframe is styled full-bleed on ready. --}}
                <div id="hero-yt-target" data-yt-id="{{ $heroYtId }}" data-mute="{{ $heroAudio ? '0' : '1' }}"></div>
                {{-- Cover: masks YouTube's own UI (center arrow, controls,
                     loading state) whenever the video isn't actively playing.
                     JS fades it out on PLAYING and back in on pause/load/end.

                     Deliberately a flat colour, NOT the hero image: it used to
                     carry $heroImg, which flashed the old wallpaper for the
                     second before playback began. The masking still works —
                     only the stale photo is gone. --}}
                <div id="hero-yt-cover" class="absolute inset-0 transition-opacity duration-500"
                     style="background:#290F08;"></div>
            @elseif($heroVideoIframe)
                <iframe class="hero-video-frame absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 border-0"
                        src="{{ $heroVideoIframe }}" allow="autoplay; encrypted-media" allowfullscreen tabindex="-1"
                        style="width:100vw; height:56.25vw; min-height:100%; min-width:177.78vh;"></iframe>
            @else
                {{-- No poster attribute on purpose: it showed the old hero
                     wallpaper for the moment before the video had buffered.
                     The flat background covers that gap instead. --}}
                <video class="hero-video-el absolute inset-0 w-full h-full object-cover object-center"
                       style="background:#290F08;"
                       autoplay loop playsinline @if(!$heroAudio) muted @endif>
                    <source src="{{ $heroVideoFile }}" type="video/mp4">
                </video>
            @endif
        </div>
    @else
        <img src="{{ $heroImg }}" alt="{{ __('common.temple_name') }}"
             class="absolute inset-0 w-full h-full object-cover object-center">
    @endif
    <div class="absolute inset-0 pointer-events-none"
         style="background:linear-gradient(180deg, rgba(41,15,8,{{ $heroOverlay/100 * 0.9 }}) 0%, rgba(41,15,8,{{ $heroOverlay/100 * 0.13 }}) 35%, rgba(58,22,10,{{ max(0.82, $heroOverlay/100 + 0.4) }}) 100%);"></div>

    @if($heroIsVideo && $heroHasVideo && $heroControls)
        {{-- Play/pause button: centred over the video (where YouTube's own
             control would be), a direct child of the hero at z-40 so it sits
             above the content/veil and is always clickable. Hidden while idle;
             fades in when the cursor is over the hero (and always shown on
             touch — see .hero-video-btn rule in app.css). --}}
        <button type="button" id="hero-video-btn"
                class="hero-video-btn absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-40 w-16 h-16 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 focus:opacity-100 transition-all duration-200 hover:scale-105"
                style="background:rgba(20,10,6,.55); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); color:#FFF7EC; box-shadow:0 4px 18px rgba(0,0,0,.4);"
                data-kind="{{ $heroVideoKind }}"
                aria-label="Play / pause background video">
            <svg class="hero-ic-pause w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
            <svg class="hero-ic-play w-7 h-7 hidden" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
        </button>
    @endif

    <div class="relative z-10 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-14 pt-28
                flex flex-col lg:flex-row lg:items-end gap-10">
        {{-- Left: identity + CTAs --}}
        <div class="flex-1 text-parch-50" style="color:#FDF6E6;">
            {{-- Open / closed pill --}}
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold mb-5"
                 style="background:{{ $isOpenNow ? 'rgba(31,90,42,.85)' : 'rgba(90,60,31,.85)' }};">
                <span class="w-2 h-2 rounded-full" style="background:{{ $isOpenNow ? '#7be08a' : '#e0b47b' }};"></span>
                @if($isOpenNow)
                    {{ __('home.temple_open') }}@if(!empty($closesAt)) · {{ __('home.temple_closes') }} {{ $closesAt }}@endif
                @else
                    {{ __('home.temple_closed') }}@if(!empty($schedule['next_opening'])) · {{ $schedule['next_opening']->format('h:i A') }}@endif
                @endif
            </div>

            <h1 class="font-marcellus leading-[1.05] text-4xl sm:text-5xl lg:text-6xl"
                style="color:#FFF7EC; text-shadow:0 2px 24px rgba(0,0,0,.45);">
                {{ $heroHeadingText }}
            </h1>
            <p class="mt-4 text-base sm:text-lg" style="color:rgba(253,246,230,.88);">
                {{ $heroSubText }}
            </p>

            <div class="flex flex-wrap items-center gap-3 mt-8">
                <a href="{{ route('donate') }}"
                   class="px-7 py-3.5 rounded-full font-extrabold text-sm sm:text-base transition hover:opacity-90"
                   style="background:#E8751A; color:#FFF7EC; box-shadow:0 6px 18px rgba(0,0,0,.35);">{{ __('home.donate') }}</a>
                <a href="{{ route('seva.index') }}"
                   class="px-6 py-3.5 rounded-full font-bold text-sm sm:text-base transition"
                   style="background:rgba(253,246,230,.14); border:1.5px solid rgba(253,246,230,.6); color:#FDF6E6; backdrop-filter:blur(4px);">{{ __('home.book_seva') }}</a>
                <a href="{{ route('darshan') }}"
                   class="inline-flex items-center gap-2 px-4 py-3.5 font-bold text-sm sm:text-base" style="color:#FDF6E6;">
                    <span class="w-2.5 h-2.5 rounded-full" style="background:#ff5a4e;"></span>{{ __('home.live_darshan') }}
                </a>
            </div>
        </div>

        {{-- Right: highlight slider — admin-selected campaigns, hall, events
             (Admin → Home Page Settings → Featured Cards). --}}
        @php
            $cards = [];
            foreach (($heroCampaigns ?? collect()) as $c) {
                $goal = (float) $c->goal_amount; $raised = (float) $c->raised_amount;
                $pct = $goal > 0 ? min(100, round($raised / $goal * 100)) : 0;
                $cards[] = ['label' => __('home.featured'), 'img' => $c->cover_image_url, 'title' => $c->title,
                    'pct' => $pct, 'raised' => $raised, 'goal' => $goal,
                    'cta' => __('home.contribute'), 'url' => route('projects.show', $c->slug)];
            }
            if (($heroShowHall ?? true) && isset($hall) && $hall) {
                $cards[] = ['label' => __('home.community_hall'), 'img' => $hall->image_path ? image_url($hall->image_path) : null,
                    'title' => $hall->name, 'text' => text_preview($hall->description ?? '', 90),
                    'cta' => __('home.check_availability'), 'url' => route('halls.show', $hall)];
            }
            foreach (($heroEvents ?? collect()) as $e) {
                $cards[] = ['label' => optional($e->start_date)->format('d/m') . ' · ' . __('home.festival'),
                    'img' => $e->image_path ? image_url($e->image_path) : null, 'title' => $e->title,
                    'text' => text_preview($e->description ?? '', 90),
                    'cta' => __('home.details'), 'url' => route('events.show', $e)];
            }
        @endphp
        @if(count($cards) > 0)
            <div class="w-full lg:w-[350px] flex-shrink-0 flex flex-col items-center"
                 x-data="{ i: 0, n: {{ count($cards) }}, t: null,
                           arm() { clearTimeout(this.t); if (this.n > 1) this.t = setTimeout(() => this.go(this.i + 1), 6000); },
                           go(k) { this.i = (k + this.n) % this.n; this.arm(); }, init() { this.arm(); } }">
                {{-- Circular highlight card: the image fills the circle and the
                     copy sits over a bottom gradient, so the advert reads as a
                     soft medallion over the hero instead of a solid rectangle
                     blocking the background. .hero-adcard (app.css) keeps it at
                     50% opacity with a periodic sheen until hovered. --}}
                <div class="hero-adcard relative rounded-full overflow-hidden"
                     style="width:min(340px,84vw); height:min(340px,84vw); box-shadow:0 18px 44px rgba(30,10,4,.45); background:rgba(251,245,234,.97);">
                    {{-- Slides sit side-by-side in a track that slides
                         horizontally, so there's no blank frame or text overlap
                         between slides (unlike a cross-fade). --}}
                    <div class="flex h-full transition-transform duration-500 ease-out"
                         :style="'transform: translateX(-' + (i * 100) + '%)'">
                    @foreach($cards as $k => $card)
                        <div class="relative w-full h-full flex-shrink-0">
                            <div class="absolute inset-0 bg-cover bg-no-repeat bg-center"
                                 style="@if($card['img'])background-image:url('{{ $card['img'] }}');@else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@endif"></div>
                            {{-- Legibility veil: light at the top, deep at the base where the copy sits. --}}
                            <div class="absolute inset-0" style="background:linear-gradient(180deg, rgba(30,12,6,.08) 32%, rgba(30,12,6,.55) 62%, rgba(30,12,6,.88) 100%);"></div>
                            <div class="absolute inset-x-0 bottom-0 px-10 pb-9 text-center">
                                <div class="text-[10px] eyebrow" style="color:#F5B36B;">{{ $card['label'] }}</div>
                                <div class="font-marcellus text-lg mt-1 line-clamp-2" style="color:#FFF7EC;">{{ $card['title'] }}</div>
                                @if(isset($card['pct']))
                                    <div class="mt-2.5 mx-6 h-[6px] rounded-full" style="background:rgba(255,247,236,.25);">
                                        <div class="h-[6px] rounded-full" style="width:{{ $card['pct'] }}%; background:linear-gradient(90deg,#E8751A,#F5B36B);"></div>
                                    </div>
                                    <div class="mt-1.5 text-xs" style="color:rgba(253,246,230,.9);">
                                        <span class="font-extrabold" style="color:#FFF7EC;">₹{{ number_format($card['raised']) }}</span>
                                        {{ __('home.of') }} ₹{{ number_format($card['goal']) }}
                                    </div>
                                @elseif(!empty($card['text']))
                                    <div class="text-xs mt-1.5 leading-relaxed line-clamp-2" style="color:rgba(253,246,230,.85);">{{ $card['text'] }}</div>
                                @endif
                                <a href="{{ $card['url'] }}" class="inline-block mt-3 font-extrabold text-xs px-6 py-2 rounded-full transition hover:opacity-90" style="background:#E8751A; color:#FFF7EC; box-shadow:0 4px 14px rgba(0,0,0,.35);">{{ $card['cta'] }}</a>
                            </div>
                        </div>
                    @endforeach
                    </div>
                </div>
                @if(count($cards) > 1)
                    <div class="flex gap-2 justify-center mt-4">
                        @foreach($cards as $k => $card)
                            <button type="button" @click="go({{ $k }})" class="w-2 h-2 rounded-full transition-all"
                                    :class="i === {{ $k }} ? 'w-5' : ''"
                                    :style="i === {{ $k }} ? 'background:#E8751A' : 'background:rgba(253,246,230,.5)'"></button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</section>

{{-- =================================================================
     CONTRIBUTION CAMPAIGNS
     ================================================================= --}}
@if(isset($campaigns) && $campaigns->isNotEmpty())
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="text-center mb-11">
            <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('home.support_dham') }}</div>
            <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.donation_campaigns') }}</h2>
            <p class="text-sm mt-2" style="color:#5E4F3D;">{{ __('home.campaigns_sub') }}</p>
        </div>
        {{-- Adaptive: 1 → full-width horizontal card, 2 → 50/50, 3 → 3-col --}}
        @include('partials.campaign-grid', ['campaignItems' => $campaigns->take(3)])
    </section>
@endif

{{-- =================================================================
     SEVA & PUJA
     ================================================================= --}}
@if(isset($sevaCategories) && count($sevaCategories) > 0)
    <section class="py-16" style="background:#F4EAD5; border-top:1px solid #e9dfc8; border-bottom:1px solid #e9dfc8;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-9">
                <div>
                    <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('home.receive_blessings') }}</div>
                    <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.seva_puja') }}</h2>
                    <p class="text-sm mt-2" style="color:#8B7355;">{{ __('home.seva_sub') }}</p>
                </div>
                <a href="{{ route('seva.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">{{ __('home.view_all_sevas') }} →</a>
            </div>
            {{-- Category cards: single-seva categories deep-link to the seva,
                 the rest open /seva preselected on that category's tab. --}}
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($sevaCategories as $cat)
                    @php
                        // Cached payload is locale-neutral; resolve here.
                        $catName = $cat['names'][app()->getLocale()] ?? null ?: $cat['names']['gu'];
                        $catUrl = ($cat['count'] === 1 && $cat['single_seva_id'])
                            ? route('seva.show', $cat['single_seva_id'])
                            : route('seva.index', ['category' => $cat['slug']]);
                    @endphp
                    <a href="{{ $catUrl }}" class="block text-center rounded-2xl p-2.5 pb-6 transition hover:shadow-lg"
                       style="background:#fff; border:1px solid #ecdfc4;">
                        <div class="w-full aspect-[4/3] rounded-xl overflow-hidden bg-cover bg-no-repeat bg-center"
                             style="@if($cat['image_path'])background-color:#fff;background-image:url('{{ image_url($cat['image_path']) }}');@else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@endif">
                        </div>
                        {{-- Title only — the seva count was dropped 2026-08-04
                             (user request); title sized up to carry the card. --}}
                        <div class="font-marcellus text-xl sm:text-2xl mt-4 mb-2" style="color:#7A1E1E;">{{ $catName }}</div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- Community Hall band — sits between Seva & Puja and Upcoming Events
     (order swapped with events 2026-08-04 per trust request). --}}
@include('partials.home-hall-band')

{{-- =================================================================
     PRASAD HIGHLIGHT (temple prasad laddoos — between hall band and
     events, added 2026-08-05 per trust request)
     ================================================================= --}}
@if(!empty($prasadProducts))
    <section class="py-16" style="background:#F4EAD5; border-top:1px solid #e9dfc8; border-bottom:1px solid #e9dfc8;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-9">
                <div>
                    <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('home.prasad_eyebrow') }}</div>
                    <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.prasad_title') }}</h2>
                    <p class="text-sm mt-2" style="color:#8B7355;">{{ __('home.prasad_sub') }}</p>
                </div>
                <a href="{{ route('store.index') }}" class="text-sm font-extrabold whitespace-nowrap" style="color:#C45F12;">{{ __('home.prasad_view_all') }} →</a>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($prasadProducts as $p)
                    @php
                        // Cached payload is locale-neutral; resolve here.
                        $pName = $p['names'][app()->getLocale()] ?? null ?: $p['names']['gu'];
                    @endphp
                    <a href="{{ route('store.product', $p['slug']) }}" class="block text-center rounded-2xl p-2.5 pb-5 transition hover:shadow-lg"
                       style="background:#fff; border:1px solid #ecdfc4;">
                        <div class="w-full aspect-[4/3] rounded-xl overflow-hidden bg-cover bg-no-repeat bg-center"
                             style="@if($p['image_path'])background-color:#fff;background-image:url('{{ image_url($p['image_path']) }}');@else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@endif">
                        </div>
                        <div class="font-marcellus text-lg sm:text-xl mt-4" style="color:#7A1E1E;">{{ $pName }}</div>
                        <div class="text-sm font-extrabold mt-1" style="color:#C45F12;">
                            {{ $p['display_price'] }}
                            @unless($p['in_stock'])
                                <span class="text-xs font-semibold" style="color:#8B7355;">· {{ __('store.out_of_stock') }}</span>
                            @endunless
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

{{-- =================================================================
     UPCOMING EVENTS (compact strip)
     ================================================================= --}}
@if(isset($events) && $events->count() > 0)
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-8">
        <div class="flex items-end justify-between mb-8">
            <div>
                <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('home.festival') }}</div>
                <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.upcoming') }}</h2>
            </div>
            <a href="{{ route('events.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">{{ __('home.view_all_events') }}</a>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($events->take(3) as $e)
                <a href="{{ route('events.show', $e) }}" class="block rounded-2xl overflow-hidden transition hover:-translate-y-0.5"
                   style="background:#fff; border:1px solid #ecdfc4;">
                    <div class="w-full aspect-[4/3] bg-cover bg-no-repeat bg-center" style="@if($e->image_path)background-color:#fff;background-image:url('{{ image_url($e->image_path) }}');@else background:repeating-linear-gradient(45deg,#e8d3b4 0 12px,#f1e0c4 12px 24px);@endif"></div>
                    <div class="p-5">
                        @if($e->start_date)
                            <div class="text-[11px] font-extrabold" style="color:#C45F12;">{{ $e->start_date->format('d/m/Y') }}</div>
                        @endif
                        <div class="font-marcellus text-lg mt-1" style="color:#7A1E1E;">{{ $e->title }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif


{{-- =================================================================
     VISIT
     ================================================================= --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-20">
    <div class="grid lg:grid-cols-[380px_1fr] gap-6 items-stretch">
        <div class="flex flex-col gap-4">
            <h2 class="font-marcellus text-3xl" style="color:#7A1E1E;">{{ __('home.come_for_darshan') }}</h2>
            <p class="text-sm -mt-2" style="color:#8B7355;">{{ __('home.come_for_darshan_sub') }}</p>
            <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('common.address_label') }}</div>
                <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">{{ __('common.trust_full') }}<br>{{ $visit['address'] ?? __('common.address') }}</div>
            </div>
            <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('nav.contact') }}</div>
                <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">
                    @if(!empty($visit['phone'])){{ $visit['phone'] }}<br>@endif
                    @if(!empty($visit['email'])){{ $visit['email'] }}@endif
                </div>
            </div>
            @if(isset($todayTiming) && $todayTiming)
                <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                    <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('footer.darshan_times') }}</div>
                    <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">
                        {{ __('footer.morning') }} {{ $fmtT($todayTiming->morning_open) }} – {{ $fmtT($todayTiming->morning_close) }}<br>
                        @if($todayTiming->evening_open){{ __('footer.evening') }} {{ $fmtT($todayTiming->evening_open) }} – {{ $fmtT($todayTiming->evening_close) }}@endif
                    </div>
                </div>
            @endif
        </div>
        <div class="rounded-2xl overflow-hidden min-h-[380px]" style="border:1px solid #ecdfc4;">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3580.4742050651294!2d70.0855247!3d23.0494366!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3950b83a7a237f4d%3A0xa8bcdb1e5393f156!2sPatadiya%20Hanuman%20Temple!5e1!3m2!1sen!2sin!4v1785498678062!5m2!1sen!2sin"
                    width="100%" height="100%" style="border:0; min-height:380px;" allowfullscreen="" loading="lazy" class="w-full h-full"></iframe>
        </div>
    </div>
</section>

@include('partials.home-darshan-widget')

@endsection

@push('scripts')
<script>
// Hero background-video play/pause. The button (a direct child of the hero,
// above the content) drives YouTube (IFrame API builds the player), Vimeo
// (Player API) or a native <video>, so it works the same everywhere.
(function () {
    // The player is built whenever there's a hero video (YouTube needs the API
    // even with no button); the button is wired only when controls are on.
    var wrap = document.querySelector('.hero-video');
    if (!wrap) return;
    var kind = wrap.dataset.kind;
    var btn = document.getElementById('hero-video-btn'); // null when controls off
    var icPause = btn && btn.querySelector('.hero-ic-pause');
    var icPlay = btn && btn.querySelector('.hero-ic-play');
    var playing = true;
    var api = null; // { play, pause }

    function paint() {
        if (icPause) icPause.classList.toggle('hidden', !playing);
        if (icPlay) icPlay.classList.toggle('hidden', playing);
    }

    function styleCover(iframe) {
        iframe.style.position = 'absolute';
        iframe.style.top = '50%';
        iframe.style.left = '50%';
        iframe.style.transform = 'translate(-50%,-50%)';
        iframe.style.width = '100vw';
        iframe.style.height = '56.25vw';
        iframe.style.minHeight = '100%';
        iframe.style.minWidth = '177.78vh';
        iframe.style.border = '0';
        iframe.style.pointerEvents = 'none';
    }

    if (kind === 'youtube') {
        var target = document.getElementById('hero-yt-target');
        if (!target) return;
        var vid = target.dataset.ytId;
        var mute = target.dataset.mute === '1' ? 1 : 0;
        var cover = document.getElementById('hero-yt-cover');
        var showCover = function (on) { if (cover) cover.style.opacity = on ? '1' : '0'; };

        window.onYouTubeIframeAPIReady = function () {
            var p = new YT.Player('hero-yt-target', {
                videoId: vid,
                playerVars: {
                    // No playlist/loop params (they add YouTube's prev/next UI);
                    // we loop manually on ENDED instead. controls:0 hides chrome.
                    autoplay: 1, mute: mute, controls: 0,
                    showinfo: 0, modestbranding: 1, rel: 0, playsinline: 1,
                    fs: 0, disablekb: 1, iv_load_policy: 3
                },
                events: {
                    onReady: function (e) {
                        styleCover(e.target.getIframe());
                        // Nudge muted autoplay (some browsers need the explicit call).
                        try { if (mute) { e.target.mute(); } e.target.playVideo(); } catch (err) {}
                    },
                    onStateChange: function (e) {
                        // The poster cover hides YouTube's own UI except while
                        // the video is actually playing.
                        if (e.data === YT.PlayerState.PLAYING) { playing = true; showCover(false); paint(); }
                        else if (e.data === YT.PlayerState.PAUSED) { playing = false; showCover(true); paint(); }
                        else if (e.data === YT.PlayerState.ENDED) { e.target.seekTo(0); e.target.playVideo(); }
                    }
                }
            });
            api = { play: function () { p.playVideo(); }, pause: function () { p.pauseVideo(); } };
        };
        // Load the API once; if it's already present, call the hook directly.
        if (window.YT && window.YT.Player) {
            window.onYouTubeIframeAPIReady();
        } else if (!document.getElementById('yt-iframe-api')) {
            var tag = document.createElement('script');
            tag.id = 'yt-iframe-api';
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        }
    } else if (kind === 'vimeo') {
        var vframe = document.querySelector('.hero-video .hero-video-frame');
        var vtag = document.createElement('script');
        vtag.src = 'https://player.vimeo.com/api/player.js';
        vtag.onload = function () {
            var vp = new Vimeo.Player(vframe);
            vp.on('play', function () { playing = true; paint(); });
            vp.on('pause', function () { playing = false; paint(); });
            api = { play: function () { vp.play(); }, pause: function () { vp.pause(); } };
        };
        document.head.appendChild(vtag);
    } else {
        var el = document.querySelector('.hero-video .hero-video-el');
        if (el) {
            el.addEventListener('play', function () { playing = true; paint(); });
            el.addEventListener('pause', function () { playing = false; paint(); });
            api = { play: function () { el.play(); }, pause: function () { el.pause(); } };
        }
    }

    if (btn) {
        btn.addEventListener('click', function () {
            if (!api) return;
            if (playing) { api.pause(); } else { api.play(); }
            playing = !playing; paint(); // optimistic; provider event confirms
        });
    }
})();
</script>
@endpush
