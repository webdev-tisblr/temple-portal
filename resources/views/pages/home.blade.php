@extends('layouts.app')

@section('content')

@php
    $loc = app()->getLocale();
    $isOpenNow = $schedule['is_open'] ?? false;
    $fmtT = fn ($t) => $t ? \Carbon\Carbon::parse($t)->format('h:i A') : null;
    // Hero background: first admin slide, else the bundled temple photo.
    $heroImg = (isset($heroSlides) && $heroSlides->isNotEmpty() && $heroSlides->first()->image_path)
        ? image_url($heroSlides->first()->image_path)
        : asset('images/hanumanji-hero.jpg');
    $heroSlide = (isset($heroSlides) && $heroSlides->isNotEmpty()) ? $heroSlides->first() : null;
@endphp

{{-- =================================================================
     HERO
     ================================================================= --}}
<section class="relative -mt-16 lg:-mt-20 min-h-[95vh] flex items-end overflow-hidden">
    <img src="{{ $heroImg }}" alt="{{ __('common.temple_name') }}"
         class="absolute inset-0 w-full h-full object-cover object-center">
    <div class="absolute inset-0"
         style="background:linear-gradient(180deg, rgba(41,15,8,.35) 0%, rgba(41,15,8,.05) 35%, rgba(58,22,10,.82) 100%);"></div>

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
                {{ $heroSlide?->headingFor($loc) ?? __('common.temple_name') }}
            </h1>
            <p class="mt-4 text-base sm:text-lg" style="color:rgba(253,246,230,.88);">
                {{ $heroSlide?->subFor($loc) ?? __('home.hero_subtitle') }}
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

        {{-- Right: highlight slider (campaign / hall / event) --}}
        @php
            $cards = [];
            if (isset($campaigns) && $campaigns->isNotEmpty()) {
                $c = $campaigns->first();
                $goal = (float) $c->goal_amount; $raised = (float) $c->raised_amount;
                $pct = $goal > 0 ? min(100, round($raised / $goal * 100)) : 0;
                $cards[] = ['label' => __('home.featured'), 'img' => $c->cover_image_url, 'title' => $c->title,
                    'pct' => $pct, 'raised' => $raised, 'goal' => $goal,
                    'cta' => __('home.contribute'), 'url' => route('projects.show', $c->slug)];
            }
            if (isset($hall) && $hall) {
                $cards[] = ['label' => __('home.community_hall'), 'img' => $hall->image_path ? image_url($hall->image_path) : null,
                    'title' => $hall->name, 'text' => text_preview($hall->description ?? '', 90),
                    'cta' => __('home.check_availability'), 'url' => route('halls.index')];
            }
            if (isset($events) && $events->isNotEmpty()) {
                $e = $events->first();
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
                            <div x-show="i === {{ $k }}" x-transition.opacity.duration.500ms
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
            <a href="{{ route('events.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">{{ __('home.all_events') }}</a>
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
