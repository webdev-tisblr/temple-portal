@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('home.seva_puja')]]"
    title="{{ __('home.seva_puja') }}"
    subtitle="{{ __('seva.index_subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple" x-data="{ activeCategory: 'all' }">

    {{-- Category Filter Tabs --}}
    <div class="flex flex-wrap justify-center gap-2 mb-8">
        <button @click="activeCategory = 'all'"
            :class="activeCategory === 'all' ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 font-bold border-amber-500' : 'bg-transparent text-amber-100/50 border-amber-800/30 hover:border-amber-600'"
            class="px-4 py-2 rounded-full text-sm font-medium border transition">
            {{ __('seva.cat_all') }}
        </button>
        @php
            $categories = [
                'shringar' => __('seva.cat_shringar'),
                'vastra' => __('seva.cat_vastra'),
                'annadan' => __('seva.cat_annadan'),
                'puja' => __('seva.cat_puja'),
                'special' => __('seva.cat_special'),
                'other' => __('seva.cat_other'),
            ];
        @endphp
        @foreach($categories as $key => $label)
            @if(isset($grouped[$key]))
                <button @click="activeCategory = '{{ $key }}'"
                    :class="activeCategory === '{{ $key }}' ? 'bg-gradient-to-r from-amber-600 to-amber-500 text-stone-900 font-bold border-amber-500' : 'bg-transparent text-amber-100/50 border-amber-800/30 hover:border-amber-600'"
                    class="px-4 py-2 rounded-full text-sm font-medium border transition">
                    {{ $label }}
                </button>
            @endif
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
