@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <span class="text-gold">સેવા પ્રોજેક્ટ્સ</span>
    </nav>

    {{-- Page Header --}}
    <div class="text-center mb-10">
        <h1 class="divine-heading text-3xl">સેવા પ્રોજેક્ટ્સ</h1>
        <p class="divine-subtext mt-2">શ્રી પાતળિયા હનુમાનજી મંદિરના ચાલુ પ્રોજેક્ટ્સ અને અભિયાનો — દાન કરીને સહયોગ આપો</p>
    </div>

    {{-- Projects Grid --}}
    @if($projects->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($projects as $project)
                @php
                    $raised = (float) $project->raised_amount;
                    $goal = (float) $project->goal_amount;
                    $pct = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
                    $isEnded = $project->end_date && $project->end_date->isPast();
                    $isGoalReached = $raised >= $goal && $goal > 0;
                @endphp
                <a href="{{ route('projects.show', $project->slug) }}"
                   class="card-sacred group block overflow-hidden">

                    {{-- Image --}}
                    <div class="aspect-[16/9] relative overflow-hidden"
                         style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
                        @if($project->image_path)
                            <img src="{{ asset('storage/' . $project->image_path) }}"
                                 alt="{{ $project->title }}"
                                 class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-80 group-hover:opacity-100">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <svg class="w-14 h-14 text-amber-800/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                          d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                            </div>
                        @endif

                        {{-- Badges --}}
                        <div class="absolute top-3 left-3 flex flex-wrap gap-2">
                            @if($project->is_featured)
                                <span class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-full bg-amber-500/90 text-stone-900">
                                    <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                    Featured
                                </span>
                            @endif
                            @if($isGoalReached)
                                <span class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-full bg-green-600/90 text-white">
                                    લક્ષ્ય પ્રાપ્ત!
                                </span>
                            @endif
                            @if($isEnded)
                                <span class="inline-flex items-center px-2.5 py-1 text-xs font-bold rounded-full bg-red-800/80 text-red-100">
                                    સમાપ્ત
                                </span>
                            @endif
                        </div>
                    </div>

                    {{-- Content --}}
                    <div class="p-5">
                        <h3 class="text-lg font-bold text-gold group-hover:text-amber-300 transition-colors">{{ $project->title }}</h3>

                        @if($project->description)
                            <p class="text-sm text-amber-100/40 mt-1 line-clamp-2">{{ $project->description }}</p>
                        @endif

                        {{-- Progress Bar --}}
                        <div class="mt-4">
                            <div class="w-full bg-amber-900/30 rounded-full h-3 overflow-hidden">
                                <div class="bg-gradient-to-r from-amber-600 to-amber-400 h-3 rounded-full transition-all duration-700"
                                     style="width: {{ $pct }}%"></div>
                            </div>
                            <div class="flex items-center justify-between mt-2">
                                <span class="text-sm text-amber-100/60">
                                    <span class="font-semibold text-gold">₹{{ number_format($raised) }}</span>
                                    <span class="text-amber-100/30"> / ₹{{ number_format($goal) }}</span>
                                </span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-full bg-amber-900/40 text-amber-400">{{ $pct }}%</span>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-10">
            {{ $projects->links() }}
        </div>
    @else
        {{-- Empty State --}}
        <div class="text-center py-16 text-amber-100/30">
            <svg class="w-16 h-16 mx-auto mb-4 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <p class="text-lg">હાલમાં કોઈ સેવા પ્રોજેક્ટ ઉપલબ્ધ નથી.</p>
        </div>
    @endif
</div>
@endsection
