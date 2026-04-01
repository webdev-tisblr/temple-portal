@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-amber-100/30">Suvidha</span>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">આસપાસના સ્થળો</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">આસપાસના સ્થળો</h1>
        <p class="mt-2 divine-subtext">Mandir aaspaas na prasiddha sthaano</p>
    </div>
</section>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    <p class="text-amber-100/60 leading-relaxed mb-10">
        Shree Pataliya Hanumanji Dham Gandhidham-Kutch ma aaveloo chhe.
        Aaaspas na aneka prasiddha dharma-sthaano, historical sites ane natural beauty na sthaano
        chhe je yatriyo visit kari shake chhe.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        @php
        $places = [
            [
                'name'     => 'Gandhidham Railway Station',
                'distance' => '~3 km',
                'type'     => 'Transport',
                'color'    => 'blue',
                'desc'     => 'Gandhidham Junction — Kutch no sabse moto railway station. Mumbai, Ahmedabad, Delhi sathe direct trains.',
                'icon'     => 'M3 10h18M3 14h18M8 6V3m8 3V3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            ],
            [
                'name'     => 'Kandla Port (Deendayal Port)',
                'distance' => '~12 km',
                'type'     => 'Historical',
                'color'    => 'teal',
                'desc'     => 'Bhaaratno sabse juno seaport. Industrial history ane sunrise views famous chhe.',
                'icon'     => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
            ],
            [
                'name'     => 'Pingleshwar Beach',
                'distance' => '~35 km',
                'type'     => 'Natural',
                'color'    => 'cyan',
                'desc'     => 'Ekant ane sunder beach. Pristine sandy shore, sunset views. Perfect for meditation and relaxation.',
                'icon'     => 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
            ],
            [
                'name'     => 'Mata no Madh (Ashapura Mata)',
                'distance' => '~85 km',
                'type'     => 'Dharmik',
                'color'    => 'orange',
                'desc'     => 'Kutch ni Kuldevi Ashapura Mata no famous mandir. Navraatri darimiyan lakhon bhakto darshan mate aave.',
                'icon'     => 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707',
            ],
            [
                'name'     => 'Bhuj City (Kutch Capital)',
                'distance' => '~55 km',
                'type'     => 'City / Tourism',
                'color'    => 'purple',
                'desc'     => 'Kutch nu matha-makkam. Prag Mahal, Aina Mahal, Kutch Museum ane famous Kutch handicrafts market.',
                'icon'     => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            ],
            [
                'name'     => 'Mandvi Beach',
                'distance' => '~60 km',
                'type'     => 'Natural',
                'color'    => 'emerald',
                'desc'     => 'Kutch no sabse prasiddha beach. Sunset views, camel rides ane Vijay Vilas Palace sathe famous tourist spot.',
                'icon'     => 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
            ],
        ];
        @endphp

        @foreach($places as $place)
        <div class="card-sacred p-6 flex gap-4">
            <div class="flex-shrink-0 w-12 h-12 bg-amber-900/30 rounded-xl flex items-center justify-center">
                <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $place['icon'] }}"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-start justify-between gap-2 mb-2">
                    <h3 class="font-bold text-gold text-lg leading-tight">{{ $place['name'] }}</h3>
                    <span class="flex-shrink-0 px-2 py-0.5 bg-amber-900/30 text-amber-400 text-xs font-medium rounded-full border border-amber-800/30">{{ $place['distance'] }}</span>
                </div>
                <span class="inline-block px-2 py-0.5 bg-amber-900/20 text-amber-100/40 text-xs rounded-full mb-2">{{ $place['type'] }}</span>
                <p class="text-sm text-amber-100/50 leading-relaxed">{{ $place['desc'] }}</p>
            </div>
        </div>
        @endforeach

    </div>

    <div class="mt-10 bg-amber-900/20 border border-amber-800/30 rounded-xl p-5 text-sm text-amber-100/50">
        <p><strong class="text-amber-100/70">Note:</strong> Aapelaa distances approximate chhe. Actual travel time traffic par depend karshhe. Proper planning sathe tour karo.</p>
    </div>

</div>

@endsection
