@extends('layouts.app')

@section('content')

{{-- Page Header --}}
<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        {{-- Breadcrumbs --}}
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">દર્શન સમય</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">દર્શન સમય</h1>
        <p class="mt-2 divine-subtext">શ્રી પાતળિયા હનુમાનજી મંદિર — દર્શન, આરતી અને સમય</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Darshan Timings Cards --}}
    @if(isset($timings) && $timings->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-14">
            @foreach($timings as $timing)
                <div class="card-sacred inner-glow overflow-hidden">
                    {{-- Card Header --}}
                    <div class="bg-gradient-to-r from-amber-900/60 to-amber-800/40 px-5 py-4 border-b border-amber-800/30">
                        <h2 class="text-lg font-bold text-gold">
                            @if($timing->day_type === 'weekday')
                                સોમ – શુક્ર (સામાન્ય દિવસ)
                            @elseif($timing->day_type === 'weekend')
                                શનિ – રવિ (સપ્તાહ અંત)
                            @elseif($timing->day_type === 'festival')
                                ઉત્સવ / તહેવાર
                            @else
                                {{ ucfirst($timing->day_type) }}
                            @endif
                        </h2>
                    </div>

                    {{-- Timings Grid --}}
                    <div class="p-5 space-y-4">
                        {{-- Morning --}}
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-amber-600 uppercase tracking-wide font-medium">સવારે</p>
                                <p class="text-amber-100/70 font-semibold">
                                    {{ \Carbon\Carbon::parse($timing->morning_open)->format('h:i A') }}
                                    &ndash;
                                    {{ \Carbon\Carbon::parse($timing->morning_close)->format('h:i A') }}
                                </p>
                            </div>
                        </div>

                        {{-- Evening --}}
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-amber-600 uppercase tracking-wide font-medium">સાંજે</p>
                                <p class="text-amber-100/70 font-semibold">
                                    {{ \Carbon\Carbon::parse($timing->evening_open)->format('h:i A') }}
                                    &ndash;
                                    {{ \Carbon\Carbon::parse($timing->evening_close)->format('h:i A') }}
                                </p>
                            </div>
                        </div>

                        {{-- Aarti --}}
                        <div class="flex items-start gap-3">
                            <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                                <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xs text-amber-600 uppercase tracking-wide font-medium">આરતી</p>
                                <p class="text-amber-100/70 font-semibold text-sm">
                                    સવારે: {{ $timing->aarti_morning ? \Carbon\Carbon::parse($timing->aarti_morning)->format('h:i A') : '—' }}
                                </p>
                                <p class="text-amber-100/70 font-semibold text-sm">
                                    સાંજ: {{ $timing->aarti_evening ? \Carbon\Carbon::parse($timing->aarti_evening)->format('h:i A') : '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-10 text-amber-100/40">
            <p>સમય ઉપલબ્ધ નથી. કૃપા કરી પછીથી ફરી તપાસો.</p>
        </div>
    @endif

    {{-- Live Darshan Section --}}
    <div class="mb-14">
        <h2 class="text-2xl font-bold text-gold mb-6">લાઇવ દર્શન</h2>

        @if(!empty($youtubeUrl))
            <div class="card-sacred overflow-hidden">
                <div class="aspect-video">
                    @php
                        // Convert watch URL to embed URL if needed
                        $embedUrl = preg_replace('/watch\?v=/', 'embed/', $youtubeUrl);
                        $embedUrl = preg_replace('/youtu\.be\//', 'www.youtube.com/embed/', $embedUrl);
                    @endphp
                    <iframe
                        src="{{ $embedUrl }}"
                        class="w-full h-full"
                        frameborder="0"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                        allowfullscreen
                        title="શ્રી પાતળિયા હનુમાનજી — લાઇવ દર્શન">
                    </iframe>
                </div>
                <div class="px-5 py-3 bg-amber-900/20 border-t border-amber-800/30">
                    <p class="text-sm text-gold font-medium text-center">|| જય શ્રી રામ || — લાઇવ દર્શન, શ્રી પાતળિયા હનુમાનજી</p>
                </div>
            </div>
        @else
            <div class="card-sacred p-12 text-center">
                <div class="w-16 h-16 bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold mb-2">લાઇવ દર્શન ટૂંક સમયમાં</h3>
                <p class="divine-subtext max-w-sm mx-auto">
                    ટૂંક સમયમાં આ સ્ક્રીન પર મંદિરના લાઇવ દર્શન ઉપલબ્ધ થશે. કૃપા કરી પ્રતિક્ષા કરો.
                </p>
            </div>
        @endif
    </div>

    {{-- Note / Guidelines --}}
    <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-gold mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            નોંધ
        </h3>
        <ul class="text-sm text-amber-100/60 space-y-1 list-disc list-inside">
            <li>ઉત્સવ અને ખાસ પ્રસંગે સમય બદલાઈ શકે છે.</li>
            <li>વધુ માહિતી માટે મંદિર ટ્રસ્ટ ઓફિસ અથવા <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold underline transition">સંપર્ક</a> પૃષ્ઠ જુઓ.</li>
        </ul>
    </div>

</div>

@endsection
