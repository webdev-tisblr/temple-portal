@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('nav.halls')]]"
    title="{{ __('nav.halls') }}"
    subtitle="{{ __('halls.subtitle') }}" />

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    @if($halls->isEmpty())
        <div class="text-center py-16 text-amber-100/30">
            <svg class="w-16 h-16 mx-auto mb-4 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
            <p class="text-lg">{{ __('halls.none_available') }}</p>
        </div>
    @elseif($halls->count() === 1)
        {{-- Adaptive layout (mirrors the campaign grid): a single hall gets
             one full-width horizontal card — image left, details right. --}}
        @php $hall = $halls->first(); @endphp
        <a href="{{ route('halls.show', $hall) }}"
           class="card-sacred group block lg:flex overflow-hidden">

            {{-- Image (left on desktop) --}}
            <div class="w-full aspect-[4/3] lg:w-2/5 lg:aspect-auto lg:min-h-[340px] relative overflow-hidden shrink-0"
                 style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
                @if($hall->image_path)
                    <img src="{{ image_url($hall->image_path) }}"
                         alt="{{ $hall->name }}"
                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                @else
                    <div class="w-full h-full flex items-center justify-center">
                        <svg class="w-20 h-20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C89434;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M3 21h18M3 7v14M21 7v14M9 11h.01M9 15h.01M15 11h.01M15 15h.01M5 7l7-4 7 4M5 7v0a2 2 0 002 2h10a2 2 0 002-2"/>
                        </svg>
                    </div>
                @endif
                <span class="absolute top-3 left-3 px-2.5 py-1 text-[10px] font-bold rounded-full shadow-sm"
                      style="background: rgba(255,252,245,0.92); color: #7A1E1E; border: 1px solid rgba(200,148,52,0.55); backdrop-filter: blur(4px);">
                    {{ __('halls.capacity') }} {{ $hall->capacity }}
                </span>
            </div>

            {{-- Details (right on desktop) --}}
            <div class="p-6 sm:p-8 lg:p-10 lg:w-3/5 flex flex-col justify-center">
                <h3 class="text-2xl sm:text-3xl font-bold text-gold mb-3">{{ $hall->name }}</h3>

                @if($hall->description)
                    <p class="text-sm sm:text-[15px] text-amber-100/50 leading-relaxed mb-5 line-clamp-5">
                        {{ text_preview($hall->description, 320) }}
                    </p>
                @endif

                @if($hall->amenities && count($hall->amenities) > 0)
                    <div class="flex flex-wrap gap-1.5 mb-5">
                        @foreach(array_slice($hall->amenities, 0, 8) as $amenity)
                            <span class="inline-block px-2.5 py-1 text-[11px] rounded-full bg-amber-900/30 text-amber-400 border border-amber-800/20">{{ $amenity }}</span>
                        @endforeach
                        @if(count($hall->amenities) > 8)
                            <span class="inline-block px-2 py-1 text-[11px] text-amber-100/40">+{{ count($hall->amenities) - 8 }}</span>
                        @endif
                    </div>
                @endif

                <div class="pt-5 flex items-center justify-between border-t border-amber-900/15">
                    <div>
                        <p class="text-[11px] text-amber-600">{{ __('halls.full_day') }}</p>
                        <p class="text-2xl font-black text-gold leading-tight">₹{{ number_format((float) $hall->price_per_day) }}</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 font-extrabold text-sm px-8 py-3 rounded-full group-hover:translate-x-0.5 transition-transform"
                          style="background:#7A1E1E; color:#FFF7EC;">
                        {{ __('halls.book') }}
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </span>
                </div>
            </div>
        </a>
    @else
        {{-- 2 halls → 50/50; 3+ → three-column grid on desktop. --}}
        <div class="grid grid-cols-1 md:grid-cols-2 @if($halls->count() >= 3) lg:grid-cols-3 @endif gap-6">
            @foreach($halls as $hall)
                <a href="{{ route('halls.show', $hall) }}"
                   class="card-sacred group block overflow-hidden flex flex-col">

                    {{-- Image --}}
                    <div class="aspect-[4/3] relative overflow-hidden"
                         style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
                        @if($hall->image_path)
                            <img src="{{ image_url($hall->image_path) }}"
                                 alt="{{ $hall->name }}"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <svg class="w-20 h-20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C89434;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                          d="M3 21h18M3 7v14M21 7v14M9 11h.01M9 15h.01M15 11h.01M15 15h.01M5 7l7-4 7 4M5 7v0a2 2 0 002 2h10a2 2 0 002-2"/>
                                </svg>
                            </div>
                        @endif
                        {{-- Capacity chip --}}
                        <span class="absolute top-3 left-3 px-2.5 py-1 text-[10px] font-bold rounded-full shadow-sm"
                              style="background: rgba(255,252,245,0.92); color: #7A1E1E; border: 1px solid rgba(200,148,52,0.55); backdrop-filter: blur(4px);">
                            {{ __('halls.capacity') }} {{ $hall->capacity }}
                        </span>
                    </div>

                    {{-- Body --}}
                    <div class="p-6 flex-1 flex flex-col">
                        <h3 class="text-xl font-bold text-gold mb-2">{{ $hall->name }}</h3>

                        @if($hall->description)
                            <p class="text-sm text-amber-100/50 leading-relaxed mb-4 line-clamp-3">
                                {{ text_preview($hall->description, 160) }}
                            </p>
                        @endif

                        @if($hall->amenities && count($hall->amenities) > 0)
                            <div class="flex flex-wrap gap-1.5 mb-4">
                                @foreach(array_slice($hall->amenities, 0, 4) as $amenity)
                                    <span class="inline-block px-2 py-0.5 text-[11px] rounded-full bg-amber-900/30 text-amber-400 border border-amber-800/20">{{ $amenity }}</span>
                                @endforeach
                                @if(count($hall->amenities) > 4)
                                    <span class="inline-block px-2 py-0.5 text-[11px] text-amber-100/40">+{{ count($hall->amenities) - 4 }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Pricing + CTA --}}
                        <div class="mt-auto pt-4 flex items-center justify-between border-t border-amber-900/15">
                            <div>
                                <p class="text-[11px] text-amber-600">{{ __('halls.full_day') }}</p>
                                <p class="text-lg font-black text-gold leading-tight">₹{{ number_format((float) $hall->price_per_day) }}</p>
                            </div>
                            <span class="text-amber-600 text-sm font-semibold group-hover:translate-x-1 transition-transform flex items-center gap-1">
                                {{ __('halls.book') }}
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </span>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

@endsection
