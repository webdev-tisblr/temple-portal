@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">કાર્યક્રમો</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">કાર્યક્રમો</h1>
        <p class="mt-2 divine-subtext">મંદિરના આગામી અને તાજેતરના ઉત્સવો</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Upcoming Events --}}
    <h2 class="text-2xl font-bold text-gold mb-6">આગામી કાર્યક્રમો</h2>

    @if($upcoming->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            @foreach($upcoming as $event)
                <div class="card-sacred overflow-hidden flex flex-col">

                    @if($event->image)
                        <img src="{{ Storage::url($event->image) }}" alt="{{ $event->title }}" class="w-full h-44 object-cover">
                    @else
                        <div class="w-full h-44 bg-amber-900/20 flex items-center justify-center">
                            <svg class="w-12 h-12 text-amber-800/50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                    @endif

                    <div class="p-5 flex flex-col flex-1">
                        <div class="flex items-start gap-3 mb-3">
                            {{-- Date Box --}}
                            <div class="flex-shrink-0 w-14 h-14 bg-amber-900/30 border border-amber-800/30 rounded-xl flex flex-col items-center justify-center">
                                <span class="text-gold text-xl font-bold leading-none">{{ $event->start_date->format('d') }}</span>
                                <span class="text-amber-500 text-xs uppercase">{{ $event->start_date->format('M') }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="font-semibold text-amber-100/70 leading-tight">{{ $event->title }}</h3>
                                @if($event->location)
                                    <p class="text-xs text-amber-100/40 mt-0.5 flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        {{ $event->location }}
                                    </p>
                                @endif
                            </div>
                        </div>

                        @if($event->event_type)
                            <span class="inline-block self-start px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-900/30 text-amber-400 mb-3">
                                {{ $event->event_type }}
                            </span>
                        @endif

                        @if($event->description)
                            <p class="text-sm text-amber-100/40 line-clamp-2 flex-1">{!! strip_tags($event->description) !!}</p>
                        @endif

                        <div class="mt-4 pt-3 border-t border-amber-900/15">
                            <a href="{{ route('events.show', $event) }}" class="text-amber-500 hover:text-gold text-sm font-semibold flex items-center gap-1 transition">
                                વધુ વાંચો
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-6">
            {{ $upcoming->links() }}
        </div>

    @else
        <div class="text-center py-16 card-sacred">
            <svg class="w-12 h-12 text-amber-800/40 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-amber-100/40">હ.yyy.l ma kooi upcoming events nathi.</p>
        </div>
    @endif

    {{-- Recent Events --}}
    @if(isset($recent) && $recent->isNotEmpty())
        <div class="mt-16">
            <h2 class="text-2xl font-bold text-gold mb-6">તાજેતરના કાર્યક્રમો</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($recent as $event)
                    <div class="card-sacred opacity-70">
                        <div class="p-5">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 w-14 h-14 bg-amber-900/20 border border-amber-900/20 rounded-xl flex flex-col items-center justify-center">
                                    <span class="text-amber-100/40 text-xl font-bold leading-none">{{ $event->start_date->format('d') }}</span>
                                    <span class="text-amber-100/30 text-xs uppercase">{{ $event->start_date->format('M') }}</span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-semibold text-amber-100/50 leading-tight">{{ $event->title }}</h3>
                                    @if($event->location)
                                        <p class="text-xs text-amber-100/30 mt-0.5">{{ $event->location }}</p>
                                    @endif
                                    @if($event->event_type)
                                        <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-900/20 text-amber-100/30">
                                            {{ $event->event_type }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

</div>

@endsection
