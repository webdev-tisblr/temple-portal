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
    $heroVideoFile = null; $heroVideoIframe = null;
    if ($heroIsVideo) {
        if (($hero['video_type'] ?? 'upload') === 'upload' && !empty($hero['video_file'])) {
            $heroVideoFile = image_url($hero['video_file']);
        } elseif (($hero['video_type'] ?? '') === 'url' && !empty($hero['video_url'])) {
            $u = $hero['video_url'];
            if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{6,})~', $u, $m)) {
                $mute = $heroAudio ? '0' : '1';
                // A custom hover play/pause button drives playback via the JS
                // API, so hide YouTube's own chrome (controls=0) and enable the
                // API (enablejsapi=1). autoplay stays muted per browser policy.
                $heroVideoIframe = "https://www.youtube.com/embed/{$m[1]}?autoplay=1&mute={$mute}&loop=1&playlist={$m[1]}&controls=0&showinfo=0&modestbranding=1&rel=0&playsinline=1&enablejsapi=1";
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
    $heroHasVideo = $heroVideoFile || $heroVideoIframe;
@endphp

{{-- =================================================================
     HERO
     ================================================================= --}}
<section class="relative -mt-16 lg:-mt-20 min-h-[95vh] flex items-end overflow-hidden">
    @if($heroIsVideo && $heroHasVideo)
        {{-- Video background + a custom hover play/pause button. The player
             chrome is hidden; playback is driven via the provider JS API
             (YouTube/Vimeo) or the native element (file), so one button works
             uniformly. The media itself is pointer-events-none so it never
             steals clicks from the hero CTAs; only the button is clickable. --}}
        <div class="hero-video group absolute inset-0 overflow-hidden"
             data-kind="{{ $heroVideoKind }}" data-controls="{{ $heroControls ? '1' : '0' }}">
            @if($heroVideoIframe)
                <iframe class="hero-video-frame absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 border-0 pointer-events-none"
                        src="{{ $heroVideoIframe }}" allow="autoplay; encrypted-media" allowfullscreen tabindex="-1"
                        style="width:100vw; height:56.25vw; min-height:100%; min-width:177.78vh;"></iframe>
            @else
                <video class="hero-video-el absolute inset-0 w-full h-full object-cover object-center pointer-events-none"
                       autoplay loop playsinline poster="{{ $heroImg }}" @if(!$heroAudio) muted @endif>
                    <source src="{{ $heroVideoFile }}" type="video/mp4">
                </video>
            @endif
            @if($heroControls)
                <button type="button"
                        class="hero-video-btn absolute bottom-6 right-6 z-30 w-12 h-12 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity duration-200"
                        style="background:rgba(20,10,6,.55); -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px); color:#FFF7EC; pointer-events:auto;"
                        aria-label="Play / pause background video">
                    <svg class="hero-ic-pause w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>
                    <svg class="hero-ic-play w-5 h-5 hidden" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                </button>
            @endif
        </div>
    @else
        <img src="{{ $heroImg }}" alt="{{ __('common.temple_name') }}"
             class="absolute inset-0 w-full h-full object-cover object-center">
    @endif
    <div class="absolute inset-0 pointer-events-none"
         style="background:linear-gradient(180deg, rgba(41,15,8,{{ $heroOverlay/100 * 0.9 }}) 0%, rgba(41,15,8,{{ $heroOverlay/100 * 0.13 }}) 35%, rgba(58,22,10,{{ max(0.82, $heroOverlay/100 + 0.4) }}) 100%);"></div>

    <div class="relative z-10 w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-14 pt-28
                flex flex-col lg:flex-row lg:items-end gap-10">
        {{-- Left: identity + CTAs --}}
        <div class="flex-1 text-parch-50" style="color:#FDF6E6;">
            {{-- Open / closed pill --}}
            <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold tracking-wide mb-5"
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
                    'cta' => __('home.check_availability'), 'url' => route('halls.index')];
            }
            foreach (($heroEvents ?? collect()) as $e) {
                $cards[] = ['label' => optional($e->start_date)->format('d M') . ' · ' . __('home.festival'),
                    'img' => $e->image_path ? image_url($e->image_path) : null, 'title' => $e->title,
                    'text' => text_preview($e->description ?? '', 90),
                    'cta' => __('home.details'), 'url' => route('events.show', $e)];
            }
        @endphp
        @if(count($cards) > 0)
            <div class="w-full lg:w-[350px] flex-shrink-0"
                 x-data="{ i: 0, n: {{ count($cards) }}, t: null,
                           arm() { clearTimeout(this.t); if (this.n > 1) this.t = setTimeout(() => this.go(this.i + 1), 6000); },
                           go(k) { this.i = (k + this.n) % this.n; this.arm(); }, init() { this.arm(); } }">
                <div class="rounded-2xl overflow-hidden" style="background:rgba(251,245,234,.97); box-shadow:0 18px 44px rgba(30,10,4,.45);">
                    {{-- Fixed-height stage: slides are absolutely stacked so the
                         card never grows/shrinks while one fades into the next. --}}
                    <div class="relative" style="height:380px;">
                        @foreach($cards as $k => $card)
                            <div x-show="i === {{ $k }}"
                                 x-transition:enter.opacity.duration.600ms
                                 x-transition:leave.opacity.duration.0ms
                                 class="absolute inset-0 flex flex-col">
                                <div class="h-44 flex-shrink-0 bg-cover bg-center" style="background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@if($card['img']) background-image:url('{{ $card['img'] }}'); @endif"></div>
                                <div class="p-5 flex flex-col flex-1">
                                    <div class="text-[10px] tracking-[0.2em] font-extrabold" style="color:#C45F12;">{{ strtoupper($card['label']) }}</div>
                                    <div class="font-marcellus text-lg mt-1.5 line-clamp-2" style="color:#7A1E1E;">{{ $card['title'] }}</div>
                                    @if(isset($card['pct']))
                                        <div class="mt-3 h-[7px] rounded-full" style="background:#f0e6cf;">
                                            <div class="h-[7px] rounded-full" style="width:{{ $card['pct'] }}%; background:linear-gradient(90deg,#E8751A,#C45F12);"></div>
                                        </div>
                                        <div class="flex justify-between mt-2 text-xs">
                                            <span class="font-extrabold" style="color:#7A1E1E;">₹{{ number_format($card['raised']) }}</span>
                                            <span style="color:#5E4F3D;">{{ __('home.of') }} ₹{{ number_format($card['goal']) }}</span>
                                        </div>
                                    @elseif(!empty($card['text']))
                                        <div class="text-xs mt-2 leading-relaxed line-clamp-3" style="color:#5E4F3D;">{{ $card['text'] }}</div>
                                    @endif
                                    <a href="{{ $card['url'] }}" class="block mt-auto text-center font-extrabold text-xs py-2.5 rounded-full transition hover:opacity-90" style="background:#7A1E1E; color:#FFF7EC;">{{ $card['cta'] }}</a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if(count($cards) > 1)
                        <div class="flex gap-2 justify-center pb-4">
                            @foreach($cards as $k => $card)
                                <button type="button" @click="go({{ $k }})" class="w-2 h-2 rounded-full transition-all"
                                        :class="i === {{ $k }} ? 'w-5' : ''"
                                        :style="i === {{ $k }} ? 'background:#E8751A' : 'background:#e3d4b4'"></button>
                            @endforeach
                        </div>
                    @endif
                </div>
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
            <div class="text-[11px] tracking-[0.24em] font-extrabold" style="color:#C45F12;">{{ __('home.support_dham') }}</div>
            <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.donation_campaigns') }}</h2>
            <p class="text-sm mt-2" style="color:#5E4F3D;">{{ __('home.campaigns_sub') }}</p>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($campaigns->take(3) as $c)
                @php
                    $goal = (float) $c->goal_amount; $raised = (float) $c->raised_amount;
                    $pct = $goal > 0 ? min(100, round($raised / $goal * 100)) : 0;
                @endphp
                <a href="{{ route('projects.show', $c->slug) }}" class="block rounded-2xl overflow-hidden transition hover:-translate-y-0.5"
                   style="background:#fff; border:1px solid #ecdfc4; box-shadow:0 2px 10px rgba(122,30,30,.06);">
                    <div class="h-40 bg-cover bg-center" style="background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@if($c->cover_image_url) background-image:url('{{ $c->cover_image_url }}'); @endif"></div>
                    <div class="p-6">
                        <div class="font-marcellus text-xl" style="color:#7A1E1E;">{{ $c->title }}</div>
                        @if($c->description)
                            <div class="text-sm mt-1.5 leading-relaxed line-clamp-2" style="color:#5E4F3D;">{{ text_preview($c->description, 110) }}</div>
                        @endif
                        <div class="mt-4 h-2 rounded-full" style="background:#f0e6cf;">
                            <div class="h-2 rounded-full" style="width:{{ $pct }}%; background:linear-gradient(90deg,#E8751A,#C45F12);"></div>
                        </div>
                        <div class="flex justify-between mt-2.5 text-xs">
                            <span class="font-extrabold" style="color:#7A1E1E;">₹{{ number_format($raised) }}</span>
                            <span style="color:#5E4F3D;">{{ __('home.of') }} ₹{{ number_format($goal) }} · {{ $pct }}%</span>
                        </div>
                        <div class="mt-4 text-center font-extrabold text-sm py-2.5 rounded-full" style="background:#7A1E1E; color:#FFF7EC;">{{ __('home.contribute') }}</div>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif

{{-- =================================================================
     SEVA & PUJA
     ================================================================= --}}
@if(isset($sevas) && $sevas->isNotEmpty())
    <section class="py-16" style="background:#F4EAD5; border-top:1px solid #e9dfc8; border-bottom:1px solid #e9dfc8;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-9">
                <div>
                    <div class="text-[11px] tracking-[0.24em] font-extrabold" style="color:#C45F12;">{{ __('home.receive_blessings') }}</div>
                    <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.seva_puja') }}</h2>
                </div>
                <a href="{{ route('seva.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">{{ __('home.view_all_sevas') }} →</a>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
                @foreach($sevas->take(4) as $seva)
                    <a href="{{ route('seva.show', $seva) }}" class="block text-center p-2.5 pb-6 transition hover:shadow-lg"
                       style="background:#fff; border:1px solid #ecdfc4; border-radius:120px 120px 16px 16px;">
                        <div class="h-40 sm:h-44 bg-cover bg-center flex items-center justify-center"
                             style="border-radius:110px 110px 8px 8px; background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);@if($seva->image_path) background-image:url('{{ image_url($seva->image_path) }}'); @endif">
                        </div>
                        <div class="font-marcellus text-base sm:text-lg mt-4" style="color:#7A1E1E;">{{ $seva->name }}</div>
                        @if($seva->description)
                            <div class="text-xs mt-1 px-3 leading-snug line-clamp-2" style="color:#5E4F3D;">{{ text_preview($seva->description, 60) }}</div>
                        @endif
                        <div class="font-extrabold text-[15px] mt-3" style="color:#C45F12;">
                            @if($seva->is_variable_price && $seva->min_price)₹{{ number_format((float) $seva->min_price) }}+@else₹{{ number_format((float) $seva->price) }}@endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif

@include('partials.home-hall-band')

{{-- =================================================================
     UPCOMING EVENTS (compact strip)
     ================================================================= --}}
@if(isset($events) && $events->count() > 0)
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="flex items-end justify-between mb-8">
            <div>
                <div class="text-[11px] tracking-[0.24em] font-extrabold" style="color:#C45F12;">{{ __('home.festival') }}</div>
                <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5" style="color:#7A1E1E;">{{ __('home.upcoming') }}</h2>
            </div>
            <a href="{{ route('events.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">{{ __('home.view_all_events') }}</a>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($events->take(3) as $e)
                <a href="{{ route('events.show', $e) }}" class="block rounded-2xl overflow-hidden transition hover:-translate-y-0.5"
                   style="background:#fff; border:1px solid #ecdfc4;">
                    <div class="h-40 bg-cover bg-center" style="background:repeating-linear-gradient(45deg,#e8d3b4 0 12px,#f1e0c4 12px 24px);@if($e->image_path) background-image:url('{{ image_url($e->image_path) }}'); @endif"></div>
                    <div class="p-5">
                        @if($e->start_date)
                            <div class="text-[11px] font-extrabold" style="color:#C45F12;">{{ $e->start_date->format('d M Y') }}</div>
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
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20">
    <div class="grid lg:grid-cols-[380px_1fr] gap-6 items-stretch">
        <div class="flex flex-col gap-4">
            <h2 class="font-marcellus text-3xl" style="color:#7A1E1E;">{{ __('home.come_for_darshan') }}</h2>
            <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                <div class="text-[11px] tracking-[0.18em] font-extrabold" style="color:#C45F12;">{{ __('common.address_label') }}</div>
                <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">{{ __('common.trust_full') }}<br>{{ $visit['address'] ?? __('common.address') }}</div>
            </div>
            <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                <div class="text-[11px] tracking-[0.18em] font-extrabold" style="color:#C45F12;">{{ __('nav.contact') }}</div>
                <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">
                    @if(!empty($visit['phone'])){{ $visit['phone'] }}<br>@endif
                    @if(!empty($visit['email'])){{ $visit['email'] }}@endif
                </div>
            </div>
            @if(isset($todayTiming) && $todayTiming)
                <div class="rounded-2xl p-6" style="background:#fff; border:1px solid #ecdfc4;">
                    <div class="text-[11px] tracking-[0.18em] font-extrabold" style="color:#C45F12;">{{ __('footer.darshan_times') }}</div>
                    <div class="text-sm mt-2 leading-relaxed" style="color:#3E3226;">
                        {{ __('footer.morning') }} {{ $fmtT($todayTiming->morning_open) }} – {{ $fmtT($todayTiming->morning_close) }}<br>
                        @if($todayTiming->evening_open){{ __('footer.evening') }} {{ $fmtT($todayTiming->evening_open) }} – {{ $fmtT($todayTiming->evening_close) }}@endif
                    </div>
                </div>
            @endif
        </div>
        <div class="rounded-2xl overflow-hidden min-h-[380px]" style="border:1px solid #ecdfc4;">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3670.0!2d70.13!3d23.08!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMjPCsDA0JzQ4LjAiTiA3MMKwMDcnNDguMCJF!5e0!3m2!1sen!2sin!4v1"
                    width="100%" height="100%" style="border:0; min-height:380px;" allowfullscreen="" loading="lazy" class="w-full h-full"></iframe>
        </div>
    </div>
</section>

@include('partials.home-darshan-widget')

@endsection

@push('scripts')
<script>
// Hero background-video play/pause. One custom button drives YouTube (IFrame
// API), Vimeo (Player API) or a native <video>, so the control works the same
// everywhere and never collides with the hero CTAs.
(function () {
    var wrap = document.querySelector('.hero-video');
    if (!wrap || wrap.dataset.controls !== '1') return;

    var btn = wrap.querySelector('.hero-video-btn');
    var icPause = wrap.querySelector('.hero-ic-pause');
    var icPlay = wrap.querySelector('.hero-ic-play');
    var kind = wrap.dataset.kind;
    var playing = true;

    function paint() {
        if (!icPause || !icPlay) return;
        icPause.classList.toggle('hidden', !playing);
        icPlay.classList.toggle('hidden', playing);
    }

    var api = null; // { play, pause }

    if (kind === 'youtube') {
        var frame = wrap.querySelector('.hero-video-frame');
        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
        window.onYouTubeIframeAPIReady = function () {
            var p = new YT.Player(frame, {
                events: {
                    onStateChange: function (e) {
                        if (e.data === YT.PlayerState.PLAYING) { playing = true; paint(); }
                        else if (e.data === YT.PlayerState.PAUSED) { playing = false; paint(); }
                    }
                }
            });
            api = { play: function () { p.playVideo(); }, pause: function () { p.pauseVideo(); } };
        };
    } else if (kind === 'vimeo') {
        var vframe = wrap.querySelector('.hero-video-frame');
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
        var vid = wrap.querySelector('.hero-video-el');
        if (vid) {
            vid.addEventListener('play', function () { playing = true; paint(); });
            vid.addEventListener('pause', function () { playing = false; paint(); });
            api = { play: function () { vid.play(); }, pause: function () { vid.pause(); } };
        }
    }

    btn && btn.addEventListener('click', function () {
        if (!api) return;
        if (playing) { api.pause(); } else { api.play(); }
        // Optimistic flip; the provider event will confirm/correct.
        playing = !playing; paint();
    });
})();
</script>
@endpush
