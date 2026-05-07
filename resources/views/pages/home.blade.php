@extends('layouts.app')

@section('content')

{{-- =================================================================
     HERO — Temple Identity
     ================================================================= --}}
<section class="relative min-h-[88vh] flex items-center justify-center overflow-hidden -mt-16 lg:-mt-20"
         style="background: #FBF5EA;">

    {{-- Hanumanji background photo, gently faded into parchment --}}
    <div class="absolute inset-0">
        <img src="{{ asset('images/hanumanji-hero.jpg') }}"
             alt="શ્રી પાતળિયા હનુમાનજી"
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
    <div class="relative z-10 text-center px-4 py-28 max-w-4xl mx-auto">

        {{-- Trust logo (always visible at the top of the hero) --}}
        <div class="flex justify-center mb-6">
            <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
                 alt="શ્રી પાતળિયા હનુમાનજી — Logo"
                 class="w-24 h-24 sm:w-28 sm:h-28 rounded-full diya-glow object-cover"
                 style="border: 2px solid rgba(200,148,52,0.55); box-shadow: 0 6px 24px rgba(200,148,52,0.25);">
        </div>

        {{-- Sacred badge --}}
        <div class="inline-flex items-center gap-2 px-5 py-2 rounded-full border mb-6"
             style="background: rgba(232,117,26,0.10); border-color: rgba(200,148,52,0.45);">
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
            <span class="text-sm tracking-[0.2em] uppercase font-medium" style="color: #7A1E1E;">|| જય શ્રી રામ ||</span>
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
        </div>

        {{-- Name --}}
        <h1 class="text-5xl sm:text-7xl lg:text-8xl font-black leading-[1.05] tracking-tight">
            <span style="color: #7A1E1E;">શ્રી પાતળિયા</span><br>
            <span style="color: #C45F12;">હનુમાનજી ધામ</span>
        </h1>

        <p class="mt-6 text-lg sm:text-xl font-light tracking-wide" style="color: #5E4F3D;">
            અંતરજાલ, ગાંધીધામ, કચ્છ — 370110
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
                {{ $isOpen ? 'મંદિર હાલ ખુલ્લું છે' : 'મંદિર હાલ બંધ છે' }}
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        @endif

        {{-- CTA row --}}
        <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="{{ route('donate') }}" class="w-full sm:w-auto btn-divine text-base px-10 py-4">
                🪔 દાન કરો
            </a>
            <a href="{{ route('darshan') }}#live" class="w-full sm:w-auto btn-temple text-base px-10 py-4">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                લાઇવ દર્શન
            </a>
        </div>
    </div>

    {{-- Bottom fade into surface --}}
    <div class="absolute bottom-0 left-0 right-0 h-32"
         style="background: linear-gradient(to top, #FBF5EA, transparent);"></div>
</section>

{{-- =================================================================
     ANNOUNCEMENTS — surface near top so urgent ones aren't missed.
     ================================================================= --}}
@if($announcements->isNotEmpty())
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 -mt-4 relative z-10 space-y-2 mb-2">
    @foreach($announcements as $ann)
        <div class="flex items-center gap-3 px-5 py-3.5 rounded-xl border shadow-sm"
             style="background: {{ $ann->is_urgent ? 'rgba(168,50,50,0.08)' : 'rgba(232,117,26,0.08)' }};
                    border-color: {{ $ann->is_urgent ? 'rgba(168,50,50,0.30)' : 'rgba(200,148,52,0.40)' }};">
            <span class="diya-glow text-lg" style="color: {{ $ann->is_urgent ? '#A83232' : '#E8751A' }};">🪔</span>
            <p class="text-sm flex-1" style="color: {{ $ann->is_urgent ? '#7A1E1E' : '#3E3226' }};">{{ $ann->title }}</p>
        </div>
    @endforeach
</section>
@endif

{{-- =================================================================
     ACTION TILES — quick-access to the most-used flows.
     Mirrors the mobile app's tile grid; horizontally scrollable on phones.
     ================================================================= --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        @php
            $tiles = [
                ['href' => route('seva.index'),     'label' => 'સેવા',        'icon' => '🙏'],
                ['href' => route('donate'),         'label' => 'દાન',         'icon' => '🪔'],
                ['href' => route('darshan'),        'label' => 'દર્શન',       'icon' => '📿'],
                ['href' => route('events.index'),   'label' => 'કાર્યક્રમ',   'icon' => '✨'],
                ['href' => route('store.index'),    'label' => 'સ્ટોર',       'icon' => '🛍️'],
                ['href' => route('halls.index'),    'label' => 'હોલ',         'icon' => '🏛️'],
            ];
        @endphp
        @foreach($tiles as $tile)
            <a href="{{ $tile['href'] }}"
               class="card-sacred flex flex-col items-center justify-center gap-2 py-5 px-3 text-center transition hover:-translate-y-0.5">
                <span class="text-3xl leading-none">{{ $tile['icon'] }}</span>
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
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="text-center mb-8">
        <div class="divine-divider"><span style="color: #C89434;">🪔</span></div>
        <h2 class="divine-heading">દર્શન સમય</h2>
        <p class="divine-subtext">પ્રભુના ચરણોમાં શીશ ઝુકાવો</p>
    </div>

    <div class="card-sacred p-6 sm:p-8">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            {{-- Morning --}}
            <div class="text-center sm:text-left">
                <div class="flex items-center justify-center sm:justify-start gap-2 mb-2">
                    <span class="text-2xl">☀️</span>
                    <p class="text-xs uppercase tracking-[0.25em] font-bold" style="color: #C45F12;">સવારે</p>
                </div>
                <p class="text-3xl font-black" style="color: #7A1E1E;">
                    {{ \Carbon\Carbon::parse($timings->morning_open)->format('h:i A') }}
                </p>
                <p class="text-sm mt-1" style="color: #5E4F3D;">
                    થી {{ \Carbon\Carbon::parse($timings->morning_close)->format('h:i A') }}
                </p>
            </div>

            {{-- Evening --}}
            <div class="text-center sm:text-left sm:border-l sm:pl-6"
                 style="border-color: rgba(122,30,30,0.10);">
                <div class="flex items-center justify-center sm:justify-start gap-2 mb-2">
                    <span class="text-2xl">🌙</span>
                    <p class="text-xs uppercase tracking-[0.25em] font-bold" style="color: #C45F12;">સાંજે</p>
                </div>
                <p class="text-3xl font-black" style="color: #7A1E1E;">
                    {{ $timings->evening_open ? \Carbon\Carbon::parse($timings->evening_open)->format('h:i A') : '-' }}
                </p>
                <p class="text-sm mt-1" style="color: #5E4F3D;">
                    {{ $timings->evening_close ? 'થી ' . \Carbon\Carbon::parse($timings->evening_close)->format('h:i A') : '' }}
                </p>
            </div>
        </div>

        @if($timings->aarti_morning || $timings->aarti_evening)
            <div class="mt-6 pt-5 border-t flex flex-col sm:flex-row items-center sm:justify-around gap-3"
                 style="border-color: rgba(122,30,30,0.10);">
                <p class="text-xs uppercase tracking-[0.2em] font-bold flex items-center gap-2" style="color: #C45F12;">
                    🪔 આરતી
                </p>
                @if($timings->aarti_morning)
                    <div class="text-center">
                        <p class="text-xs" style="color: #5E4F3D;">સવારે</p>
                        <p class="text-lg font-bold" style="color: #7A1E1E;">{{ \Carbon\Carbon::parse($timings->aarti_morning)->format('h:i A') }}</p>
                    </div>
                @endif
                @if($timings->aarti_evening)
                    <div class="text-center">
                        <p class="text-xs" style="color: #5E4F3D;">સાંજે</p>
                        <p class="text-lg font-bold" style="color: #7A1E1E;">{{ \Carbon\Carbon::parse($timings->aarti_evening)->format('h:i A') }}</p>
                    </div>
                @endif
            </div>
        @endif

        <div class="text-center mt-6">
            <a href="{{ route('darshan') }}" class="text-sm font-semibold inline-flex items-center gap-1 hover:underline"
               style="color: #C45F12;">
                પૂર્ણ સમય-પત્રક જુઓ
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     FEATURED CAMPAIGN — single hero card. The full grid lives at
     /projects.
     ================================================================= --}}
@if($featuredCampaign)
    @php
        $raised = (float) $featuredCampaign->raised_amount;
        $goal = (float) $featuredCampaign->goal_amount;
        $pct = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
    @endphp
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <div class="card-sacred p-8 sm:p-10 inner-glow">
        <div class="text-center mb-6">
            <span class="text-3xl diya-glow inline-block">🪔</span>
            <p class="text-xs uppercase tracking-[0.25em] font-bold mt-3" style="color: #C45F12;">વિશેષ અભિયાન</p>
            <h3 class="divine-heading text-2xl sm:text-3xl mt-2">{{ $featuredCampaign->title }}</h3>
            @if($featuredCampaign->description)
                <p class="mt-3 max-w-2xl mx-auto" style="color: #5E4F3D;">
                    {{ \Illuminate\Support\Str::limit(strip_tags($featuredCampaign->description), 200) }}
                </p>
            @endif
        </div>

        <div class="max-w-md mx-auto mt-6">
            <div class="flex items-baseline justify-between text-sm mb-2">
                <span class="font-black text-xl" style="color: #7A1E1E;">₹{{ number_format($raised) }}</span>
                <span style="color: #5E4F3D;">/ ₹{{ number_format($goal) }}</span>
            </div>
            <div class="w-full h-3 rounded-full overflow-hidden" style="background: rgba(200,148,52,0.18);">
                <div class="h-full rounded-full transition-all duration-1000"
                     style="width: {{ $pct }}%; background: linear-gradient(90deg, #E8751A, #C89434);"></div>
            </div>
            <p class="text-xs mt-2 flex items-center justify-between" style="color: #5E4F3D;">
                <span>{{ $pct }}% પૂર્ણ</span>
                <span>{{ $featuredCampaign->donor_count ?? 0 }} ભક્તોએ યોગદાન આપ્યું</span>
            </p>
        </div>

        <div class="text-center mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="{{ route('donate') }}" class="btn-divine text-base px-10 py-3.5">🙏 યોગદાન આપો</a>
            <a href="{{ route('projects.index') }}" class="text-sm font-semibold inline-flex items-center gap-1 hover:underline"
               style="color: #C45F12;">
                બધા પ્રોજેક્ટ્સ જુઓ
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>
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
            <h2 class="divine-heading">સેવા અને પૂજા</h2>
            <p class="divine-subtext">ઓનલાઈન સેવા બુક કરો અને ભગવાનના આશીર્વાદ મેળવો</p>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($sevas as $seva)
                @include('components.seva-card', ['seva' => $seva])
            @endforeach
        </div>
        <div class="text-center mt-10">
            <a href="{{ route('seva.index') }}" class="btn-temple">
                બધી સેવાઓ જુઓ
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     UPCOMING EVENTS — date-chip + title cards.
     ================================================================= --}}
@if($events->isNotEmpty())
<section class="py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">📿</span></div>
            <h2 class="divine-heading">આગામી કાર્યક્રમો</h2>
            <p class="divine-subtext">મંદિરના આગામી ઉત્સવ અને વિશેષ પ્રસંગો</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($events as $event)
                <a href="{{ route('events.show', $event) }}"
                   class="card-sacred p-6 inner-glow flex gap-4 group">
                    <div class="w-16 h-16 rounded-2xl flex flex-col items-center justify-center flex-shrink-0 border"
                         style="background: linear-gradient(145deg, #FFFCF5, #FBF5EA);
                                border-color: rgba(200,148,52,0.35);">
                        <span class="text-xl font-black leading-none" style="color: #7A1E1E;">{{ $event->start_date->format('d') }}</span>
                        <span class="text-[10px] font-bold uppercase mt-0.5" style="color: #C45F12;">{{ $event->start_date->format('M') }}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-bold text-lg group-hover:underline truncate" style="color: #7A1E1E;">{{ $event->title }}</h3>
                        @if($event->location)
                            <p class="text-xs mt-1" style="color: #8A7860;">📍 {{ $event->location }}</p>
                        @endif
                        @if($event->description)
                            <p class="text-sm mt-2 line-clamp-2" style="color: #5E4F3D;">
                                {!! strip_tags($event->description) !!}
                            </p>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
        <div class="text-center mt-10">
            <a href="{{ route('events.index') }}" class="btn-temple">
                બધા કાર્યક્રમો જુઓ
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
            <h2 class="divine-heading">ગેલેરી</h2>
            <p class="divine-subtext">મંદિરના તાજેતરના ફોટા</p>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
            @foreach($galleryPreview as $img)
                <a href="{{ route('gallery') }}"
                   class="block aspect-square overflow-hidden rounded-xl border group"
                   style="border-color: rgba(122,30,30,0.12);">
                    <img src="{{ image_url($img->thumbnail_path ?: $img->image_path) }}"
                         alt="{{ $img->title }}"
                         loading="lazy"
                         class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                </a>
            @endforeach
        </div>

        <div class="text-center mt-8">
            <a href="{{ route('gallery') }}" class="btn-temple">
                બધા ફોટા જુઓ
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     ABOUT — short intro snippet pulled from the Parichay CMS page.
     ================================================================= --}}
@if($intro)
<section class="py-14">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div class="divine-divider"><span style="color: #C89434;">🙏</span></div>
        <h2 class="divine-heading mb-6">{{ $intro->title }}</h2>
        <div class="text-lg leading-relaxed" style="color: #3E3226;">
            {!! \Illuminate\Support\Str::limit(strip_tags($intro->body), 480) !!}
        </div>
        <div class="mt-7">
            <a href="/parichay" class="btn-temple">
                વધુ વાંચો
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
                </svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- =================================================================
     VISIT US — address + map + contact.
     ================================================================= --}}
<section class="py-14 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span style="color: #C89434;">📍</span></div>
            <h2 class="divine-heading">અમારી મુલાકાત લો</h2>
            <p class="divine-subtext">અંતરજાલ, ગાંધીધામ, કચ્છ — 370110</p>
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
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">સરનામું</p>
                    <p class="text-sm leading-relaxed" style="color: #3E3226;">
                        શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ<br>
                        અંતરજાલ, ગાંધીધામ, કચ્છ — 370110
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
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">સંપર્ક</p>
                    <a href="{{ route('contact') }}" class="text-sm font-semibold hover:underline" style="color: #7A1E1E;">
                        ફોન & ઈમેલ વિગતો
                    </a>
                    <p class="text-xs mt-2" style="color: #5E4F3D;">પ્રશ્ન / સંદેશ માટે સંપર્ક કરો</p>
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
                    <p class="text-xs uppercase tracking-wide mb-0.5" style="color: #C45F12;">દર્શન સમય</p>
                    @if($timings)
                        <p class="text-sm font-semibold" style="color: #7A1E1E;">
                            સવારે {{ \Carbon\Carbon::parse($timings->morning_open)->format('h:i') }}
                            – {{ \Carbon\Carbon::parse($timings->morning_close)->format('h:i A') }}
                        </p>
                        @if($timings->evening_open && $timings->evening_close)
                            <p class="text-sm font-semibold mt-1" style="color: #7A1E1E;">
                                સાંજે {{ \Carbon\Carbon::parse($timings->evening_open)->format('h:i') }}
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
