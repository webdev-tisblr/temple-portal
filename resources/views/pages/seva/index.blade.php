@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('home.seva_puja')]]"
    title="{{ __('home.seva_puja') }}"
    subtitle="{{ __('seva.index_subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple" x-data="{ activeCategory: '{{ $initialCategory ?? 'all' }}' }">

    {{-- Category Filter Tabs (admin-managed: Admin → Sevas → Categories) --}}
    <div class="flex flex-wrap justify-center gap-2 mb-8">
        <button @click="activeCategory = 'all'"
            :class="activeCategory === 'all' ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 font-bold border-amber-500' : 'bg-transparent text-amber-100/50 border-amber-800/30 hover:border-amber-600'"
            class="px-4 py-2 rounded-full text-sm font-medium border transition">
            {{ __('seva.cat_all') }}
        </button>
        @foreach($categories as $key)
            <button @click="activeCategory = '{{ $key }}'"
                :class="activeCategory === '{{ $key }}' ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 font-bold border-amber-500' : 'bg-transparent text-amber-100/50 border-amber-800/30 hover:border-amber-600'"
                class="px-4 py-2 rounded-full text-sm font-medium border transition">
                {{ $categoryNames[$key] ?? ucfirst($key) }}
            </button>
        @endforeach
    </div>

    {{-- Seva Grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach($sevas as $seva)
            <div x-show="activeCategory === 'all' || activeCategory === '{{ $seva->getRawOriginal('category') }}'"
                 x-transition>
                @include('components.seva-card', ['seva' => $seva])
            </div>
        @endforeach
    </div>

    @if($sevas->isEmpty())
        <div class="text-center py-16 text-amber-100/30">
            <p class="text-lg">{{ __('seva.none_available') }}</p>
        </div>
    @endif
</div>
@endsection
