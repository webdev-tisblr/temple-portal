@extends('layouts.app')

@section('content')

{{-- === HERO: Temple Entrance Experience === --}}
<section class="relative min-h-[90vh] flex items-center justify-center overflow-hidden -mt-16 lg:-mt-20" style="background: #0a0604;">
    {{-- Hanumanji Background Image --}}
    <div class="absolute inset-0">
        <img src="{{ asset('images/hanumanji-hero.jpg') }}" alt="શ્રી પાતળિયા હનુમાનજી" class="w-full h-full object-cover object-center opacity-40" style="filter: brightness(0.5) saturate(0.7);">
        {{-- Dark overlay gradient --}}
        <div class="absolute inset-0" style="background: linear-gradient(to bottom, rgba(10,6,4,0.5) 0%, rgba(10,6,4,0.3) 30%, rgba(10,6,4,0.5) 70%, rgba(10,6,4,0.95) 100%);"></div>
        {{-- Warm radial glow over the murti --}}
        <div class="absolute inset-0" style="background: radial-gradient(ellipse at center 40%, rgba(196,154,42,0.12) 0%, transparent 60%);"></div>
    </div>

    {{-- Floating divine particles --}}
    <div class="absolute inset-0 pointer-events-none" x-data="divineParticles"></div>

    {{-- Warm diya glow from bottom --}}
    <div class="absolute bottom-0 left-1/2 -translate-x-1/2 w-[800px] h-[350px] rounded-full opacity-25" style="background: radial-gradient(ellipse, #c49a2a, transparent 70%);"></div>

    {{-- Content --}}
    <div class="relative z-10 text-center px-4 py-32">
        {{-- Sacred badge --}}
        <div class="inline-flex items-center gap-2 px-5 py-2 rounded-full border border-amber-800/30 mb-8" style="background: rgba(196,154,42,0.08);">
            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span>
            <span class="text-amber-400/80 text-sm tracking-[0.2em] uppercase font-medium">|| જય શ્રી રામ ||</span>
            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full animate-pulse"></span>
        </div>

        <h1 class="text-5xl sm:text-7xl lg:text-8xl font-black leading-[1.05] tracking-tight">
            <span class="text-gold">શ્રી પાતળિયા</span><br>
            <span style="color: #c49a2a;">હનુમાનજી ધામ</span>
        </h1>

        <p class="mt-6 text-xl sm:text-2xl text-amber-100/40 font-light tracking-wide">અંતરજાલ, ગાંધીધામ, કચ્છ (૩૭૦૧૧૦)</p>

        {{-- Ornamental divider --}}
        <div class="divine-divider">
            <span class="text-amber-600 text-xs">✦</span>
        </div>

        <p class="text-amber-400/60 font-medium tracking-wider text-sm uppercase">ગુજરાતમાં હનુમાનજીનું પ્રસિદ્ધ ધામ</p>

        <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="{{ route('donate') }}" class="w-full sm:w-auto btn-divine text-base px-10 py-4">
                દાન કરો
            </a>
            <a href="#" class="w-full sm:w-auto btn-temple text-base px-10 py-4">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                લાઇવ દર્શન
            </a>
        </div>
    </div>

    {{-- Bottom fade --}}
    <div class="absolute bottom-0 left-0 right-0 h-32" style="background: linear-gradient(to top, #0a0604, transparent);"></div>
</section>

{{-- === ANNOUNCEMENTS === --}}
@if($announcements->isNotEmpty())
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @foreach($announcements as $ann)
        <div class="flex items-center gap-3 px-5 py-3.5 mb-2 rounded-xl border {{ $ann->is_urgent ? 'border-red-900/30 bg-red-950/30' : 'border-amber-900/20 bg-amber-950/20' }}">
            <span class="text-amber-500 diya-glow">🪔</span>
            <p class="text-sm {{ $ann->is_urgent ? 'text-red-300' : 'text-amber-200/70' }} flex-1">{{ $ann->title }}</p>
        </div>
    @endforeach
</section>
@endif

{{-- === DARSHAN TIMINGS === --}}
@if($timings)
<section class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="text-center mb-10">
        <div class="divine-divider"><span class="text-amber-600">🪔</span></div>
        <h2 class="divine-heading">દર્શન સમય</h2>
        <p class="divine-subtext">પ્રભુના ચરણોમાં શીશ ઝુકાવો</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        @foreach([['સવારે', 'morning_open', 'morning_close', '☀️'], ['સાંજે', 'evening_open', 'evening_close', '🌙'], ['આરતી', 'aarti_morning', 'aarti_evening', '🪔']] as [$label, $f1, $f2, $icon])
            <div class="card-sacred p-6 text-center inner-glow">
                <span class="text-3xl block mb-3">{{ $icon }}</span>
                <p class="text-amber-600 text-xs uppercase tracking-[0.2em] mb-2">{{ $label }}</p>
                @if($label === 'આરતી')
                    <p class="text-xl font-bold text-gold">{{ $timings->$f1 ? \Carbon\Carbon::parse($timings->$f1)->format('h:i A') : '-' }}</p>
                    <p class="text-amber-100/30 text-xs my-1">અને</p>
                    <p class="text-xl font-bold text-gold">{{ $timings->$f2 ? \Carbon\Carbon::parse($timings->$f2)->format('h:i A') : '-' }}</p>
                @else
                    <p class="text-2xl font-black text-gold">{{ \Carbon\Carbon::parse($timings->$f1)->format('h:i A') }}</p>
                    <p class="text-amber-100/30 text-sm mt-1">થી {{ \Carbon\Carbon::parse($timings->$f2)->format('h:i A') }}</p>
                @endif
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- === SEVA & POOJA === --}}
<section class="py-16 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <div class="divine-divider"><span class="text-amber-600">🙏</span></div>
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
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>
    </div>
</section>

{{-- === UPCOMING EVENTS === --}}
@if($events->isNotEmpty())
<section class="py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <div class="divine-divider"><span class="text-amber-600">📿</span></div>
            <h2 class="divine-heading">આગામી કાર્યક્રમો</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($events as $event)
                <div class="card-sacred p-6 inner-glow">
                    <div class="flex items-start gap-4">
                        <div class="w-16 h-16 rounded-2xl flex flex-col items-center justify-center flex-shrink-0 border border-amber-800/30" style="background: linear-gradient(145deg, #1a0f08, #0f0804);">
                            <span class="text-gold text-xl font-black leading-none">{{ $event->start_date->format('d') }}</span>
                            <span class="text-amber-600 text-[10px] font-bold uppercase">{{ $event->start_date->format('M') }}</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-gold text-lg">{{ $event->title }}</h3>
                            <p class="text-amber-100/30 text-xs mt-1">{{ $event->location }}</p>
                        </div>
                    </div>
                    @if($event->description)
                        <p class="text-sm text-amber-100/40 mt-4 line-clamp-2">{!! strip_tags($event->description) !!}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- === DONATION CAMPAIGN === --}}
@if($campaigns->isNotEmpty())
<section class="py-16 bg-temple-light">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        @foreach($campaigns as $campaign)
            <div class="card-sacred p-8 sm:p-10 inner-glow">
                <div class="text-center mb-6">
                    <span class="text-3xl diya-glow">🪔</span>
                    <h3 class="divine-heading text-2xl sm:text-3xl mt-3">{{ $campaign->title }}</h3>
                    @if($campaign->description)
                        <p class="mt-3 text-amber-100/40">{{ $campaign->description }}</p>
                    @endif
                </div>
                @php $pct = $campaign->goal_amount > 0 ? min(100, round(($campaign->raised_amount / $campaign->goal_amount) * 100)) : 0; @endphp
                <div class="max-w-md mx-auto mt-6">
                    <div class="flex justify-between text-sm mb-2">
                        <span class="font-bold text-gold text-lg">₹{{ number_format((float) $campaign->raised_amount) }}</span>
                        <span class="text-amber-100/30">/ ₹{{ number_format((float) $campaign->goal_amount) }}</span>
                    </div>
                    <div class="w-full h-3 rounded-full overflow-hidden" style="background: rgba(196,154,42,0.1);">
                        <div class="h-full rounded-full transition-all duration-1000" style="width: {{ $pct }}%; background: linear-gradient(90deg, #b8860b, #e8c36a);"></div>
                    </div>
                    <p class="text-xs text-amber-100/30 mt-2">{{ $pct }}% પૂર્ણ &bull; {{ $campaign->donor_count }} ભક્તોએ યોગદાન આપ્યું</p>
                </div>
                <div class="text-center mt-8">
                    <a href="{{ route('donate') }}" class="btn-divine text-base px-10 py-4">🙏 યોગદાન આપો</a>
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif

{{-- === ABOUT / INTRO === --}}
@if($intro)
<section class="py-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
        <div class="divine-divider"><span class="text-amber-600">🙏</span></div>
        <h2 class="divine-heading mb-8">{{ $intro->title }}</h2>
        <div class="text-amber-100/50 text-lg leading-relaxed">
            {!! Str::limit(strip_tags($intro->body), 500) !!}
        </div>
        <div class="mt-8">
            <a href="/parichay" class="btn-temple">
                વધુ વાંચો <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>
    </div>
</section>
@endif

{{-- === LOCATION === --}}
<section class="py-16 bg-temple-light">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="divine-divider"><span class="text-amber-600">📍</span></div>
            <h2 class="divine-heading">અમારું સ્થાન</h2>
            <p class="divine-subtext">અંતરજાલ, ગાંધીધામ, કચ્છ - 370205</p>
        </div>
        <div class="rounded-3xl overflow-hidden border border-amber-900/20" style="box-shadow: 0 10px 40px rgba(0,0,0,0.3);">
            <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3670.0!2d70.13!3d23.08!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zMjPCsDA0JzQ4LjAiTiA3MMKwMDcnNDguMCJF!5e0!3m2!1sen!2sin!4v1"
                    width="100%" height="400" style="border:0; filter: brightness(0.7) contrast(1.1) saturate(0.8);" allowfullscreen="" loading="lazy" class="w-full"></iframe>
        </div>
    </div>
</section>

@endsection
