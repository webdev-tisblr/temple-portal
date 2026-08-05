@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('footer.darshan_times')]]"
    title="{{ __('footer.darshan_times') }}"
    subtitle="{{ __('darshan.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- 1. Live Darshan + Daily Darshan Photo (TOP) — 70/30 row.
         Live YouTube on the left (70%), daily darshan photo on the
         right (30%). Single column on mobile.
         The daily darshan image renders at its natural aspect ratio —
         we don't force a height, crop, or contain — exactly how the
         admin uploaded it. The card just provides the surface; empty
         space around a portrait image is intentional. --}}
    <div class="mb-14 grid grid-cols-1 lg:grid-cols-10 gap-6">

        {{-- LEFT 70% — Live YouTube --}}
        <div class="lg:col-span-7">
            <h2 class="text-2xl font-bold text-gold mb-6 flex items-center gap-3">
                <span class="relative flex h-3 w-3">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                </span>
                {{ __('home.live_darshan') }}
            </h2>

            @if(!empty($youtubeUrl) && ($isLiveNow ?? true))
                <div class="card-sacred overflow-hidden">
                    <div class="aspect-video relative">
                        @if(youtube_video_id($youtubeUrl))
                            <x-yt-clean :url="$youtubeUrl" live
                                        class="absolute inset-0 w-full h-full"
                                        title="{{ __('common.temple_name') }} — {{ __('home.live_darshan') }}" />
                        @else
                            {{-- Unparseable URL form (e.g. /@channel/live) — plain embed fallback. --}}
                            @php
                                $embedUrl = preg_replace('/watch\?v=/', 'embed/', $youtubeUrl);
                                $embedUrl = preg_replace('/youtu\.be\//', 'www.youtube.com/embed/', $embedUrl);
                                $embedUrl = preg_replace('#youtube\.com/live/#', 'youtube.com/embed/', $embedUrl);
                            @endphp
                            <iframe
                                src="{{ $embedUrl }}"
                                class="absolute inset-0 w-full h-full"
                                frameborder="0"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                allowfullscreen
                                title="{{ __('common.temple_name') }} — {{ __('home.live_darshan') }}">
                            </iframe>
                        @endif
                    </div>
                    <div class="px-5 py-3 bg-amber-900/20 border-t border-amber-800/30">
                        <p class="text-sm text-gold font-medium text-center">{{ __('darshan.live_caption') }}</p>
                    </div>
                </div>
            @else
                {{-- Off-hours / no-stream placeholder: the admin-set offline
                     image (falls back to the daily darshan photo) + when
                     darshan is next available. --}}
                <div class="card-sacred overflow-hidden">
                    @if(!empty($livePlaceholderUrl))
                        <div class="aspect-video relative">
                            <img src="{{ $livePlaceholderUrl }}"
                                 alt="{{ __('darshan.live_offline') }}"
                                 class="absolute inset-0 w-full h-full object-cover">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/20 to-transparent"></div>
                            <div class="absolute bottom-0 left-0 right-0 p-5 text-center">
                                <h3 class="text-lg font-bold text-white">{{ __('darshan.live_offline') }}</h3>
                                @if(!empty($nextDarshanAt))
                                    <p class="text-white text-sm mt-1">
                                        {{ __('darshan.next_darshan') }}:
                                        <span class="font-semibold tabular-nums">{{ $nextDarshanAt->format('h:i A') }}</span>
                                        @if(!$nextDarshanAt->isToday())
                                            <span class="opacity-80">({{ $nextDarshanAt->isTomorrow() ? __('darshan.tomorrow') : $nextDarshanAt->format('D') }})</span>
                                        @endif
                                    </p>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="p-12 text-center">
                            <div class="w-16 h-16 bg-amber-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                                <svg class="w-8 h-8 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <h3 class="text-xl font-bold text-gold mb-2">{{ __('darshan.live_offline') }}</h3>
                            @if(!empty($nextDarshanAt))
                                <p class="divine-subtext max-w-sm mx-auto">
                                    {{ __('darshan.next_darshan') }}: <span class="font-semibold tabular-nums">{{ $nextDarshanAt->format('h:i A') }}</span>
                                    @if(!$nextDarshanAt->isToday())
                                        ({{ $nextDarshanAt->isTomorrow() ? __('darshan.tomorrow') : $nextDarshanAt->format('D') }})
                                    @endif
                                </p>
                            @else
                                <p class="divine-subtext max-w-sm mx-auto">{{ __('darshan.live_coming_sub') }}</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>

        {{-- RIGHT 30% — Daily Darshan Photo --}}
        @if(isset($dailyDarshanPhoto) && $dailyDarshanPhoto)
            <div class="lg:col-span-3">
                <h2 class="text-2xl font-bold text-gold mb-6 flex items-center gap-2">
                    <span class="text-2xl">🪔</span>
                    {{ $dailyDarshanPhoto->captured_on && $dailyDarshanPhoto->captured_on->isToday()
                        ? __('darshan.todays')
                        : __('darshan.latest') }}
                </h2>
                <div class="card-sacred overflow-hidden">
                    {{-- 4:3 frame; deity photos may not be 4:3, so contain
                         (never crop) — the parchment surface shows around
                         portrait crops. --}}
                    <div class="w-full aspect-[4/3] flex items-center justify-center"
                         style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
                        <img src="{{ image_url($dailyDarshanPhoto->image_path) }}"
                             alt="{{ __('darshan.todays') }}"
                             loading="lazy"
                             class="max-w-full max-h-full object-contain">
                    </div>
                    <div class="px-5 py-3 bg-amber-900/20 border-t border-amber-800/30">
                        <p class="text-sm text-gold font-medium text-center">
                            {{ ($dailyDarshanPhoto->captured_on ?? now())->format('d/m/Y') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- 2. Darshan Timings Cards --}}
    <div class="mb-14">
        <h2 class="text-2xl font-bold text-gold mb-6 flex items-center gap-2">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ __('footer.darshan_times') }}
        </h2>

        @if(isset($timings) && $timings->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($timings as $timing)
                    <div class="card-sacred inner-glow overflow-hidden">
                        <div class="bg-gradient-to-r from-amber-900/60 to-amber-800/40 px-5 py-4 border-b border-amber-800/30">
                            <h3 class="text-lg font-bold text-gold">
                                @if($timing->day_type === 'regular')
                                    {{ __('darshan.regular_days') }}
                                @elseif($timing->day_type === 'special')
                                    {{ __('darshan.special_saturday') }}
                                @elseif($timing->day_type === 'sunday')
                                    {{ __('darshan.sunday') }}
                                @elseif($timing->day_type === 'festival')
                                    {{ __('darshan.festival') }}
                                @else
                                    {{ ucfirst($timing->day_type) }}
                                @endif
                            </h3>
                        </div>

                        <div class="p-5 space-y-4">
                            {{-- Morning --}}
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 w-10 h-10 bg-amber-900/30 rounded-lg flex items-center justify-center">
                                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707M17.657 17.657l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                                    </svg>
                                </div>
                                <div>
                                    <p class="text-xs text-amber-600 font-medium">{{ __('footer.morning') }}</p>
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
                                    <p class="text-xs text-amber-600 font-medium">{{ __('footer.evening') }}</p>
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
                                    <p class="text-xs text-amber-600 font-medium">{{ __('darshan.aarti') }}</p>
                                    <p class="text-amber-100/70 font-semibold text-sm">
                                        {{ __('footer.morning') }}: {{ $timing->aarti_morning ? \Carbon\Carbon::parse($timing->aarti_morning)->format('h:i A') : '—' }}
                                    </p>
                                    <p class="text-amber-100/70 font-semibold text-sm">
                                        {{ __('footer.evening') }}: {{ $timing->aarti_evening ? \Carbon\Carbon::parse($timing->aarti_evening)->format('h:i A') : '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-10 text-amber-100/40">
                <p>{{ __('darshan.no_timings') }}</p>
            </div>
        @endif
    </div>

    {{-- 3. Temple Rules & Regulations --}}
    @if(!empty($templeRules))
        <div class="mb-14">
            <h2 class="text-2xl font-bold text-gold mb-6 flex items-center gap-2">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                {{ __('darshan.rules_guidelines') }}
            </h2>
            <div class="card-sacred p-6 sm:p-8">
                <div class="prose prose-invert prose-headings:text-gold prose-a:text-amber-500 prose-strong:text-amber-100/80 prose-li:text-amber-100/60 max-w-none text-amber-100/60 leading-relaxed">
                    {!! $templeRules !!}
                </div>
            </div>
        </div>
    @endif

    {{-- Note --}}
    <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-gold mb-3 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ __('darshan.note') }}
        </h3>
        <ul class="text-sm text-amber-100/60 space-y-1 list-disc list-inside">
            <li>{{ __('darshan.note1') }}</li>
            <li>{{ __('darshan.note2_before') }} <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold underline transition">{{ __('nav.contact') }}</a> {{ __('darshan.note2_after') }}</li>
        </ul>
    </div>

</div>

@endsection
