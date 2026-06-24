@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('halls.facilities')],
        ['label' => __('placesaround.title')],
    ]"
    title="{{ __('placesaround.title') }}"
    subtitle="{{ __('placesaround.subtitle') }}" />

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    <p class="text-amber-100/60 leading-relaxed mb-10">
        {{ __('placesaround.intro') }}
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        @php
        $places = [
            [
                'name'     => __('placesaround.p1n'),
                'distance' => __('placesaround.p1dist'),
                'type'     => __('placesaround.t_transport'),
                'desc'     => __('placesaround.p1d'),
                'icon'     => 'M3 10h18M3 14h18M8 6V3m8 3V3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
            ],
            [
                'name'     => __('placesaround.p2n'),
                'distance' => __('placesaround.p2dist'),
                'type'     => __('placesaround.t_historical'),
                'desc'     => __('placesaround.p2d'),
                'icon'     => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
            ],
            [
                'name'     => __('placesaround.p3n'),
                'distance' => __('placesaround.p3dist'),
                'type'     => __('placesaround.t_natural'),
                'desc'     => __('placesaround.p3d'),
                'icon'     => 'M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9',
            ],
            [
                'name'     => __('placesaround.p4n'),
                'distance' => __('placesaround.p4dist'),
                'type'     => __('placesaround.t_religious'),
                'desc'     => __('placesaround.p4d'),
                'icon'     => 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707',
            ],
            [
                'name'     => __('placesaround.p5n'),
                'distance' => __('placesaround.p5dist'),
                'type'     => __('placesaround.t_city'),
                'desc'     => __('placesaround.p5d'),
                'icon'     => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
            ],
            [
                'name'     => __('placesaround.p6n'),
                'distance' => __('placesaround.p6dist'),
                'type'     => __('placesaround.t_natural'),
                'desc'     => __('placesaround.p6d'),
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
        <p><strong class="text-amber-100/70">{{ __('darshan.note') }}:</strong> {{ __('placesaround.note') }}</p>
    </div>

</div>

@endsection
