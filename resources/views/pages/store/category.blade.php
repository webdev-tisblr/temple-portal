@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <a href="{{ route('store.index') }}" class="hover:text-gold transition">મંદિર સ્ટોર</a>
        <span class="mx-2">/</span>
        <span class="text-gold">{{ $category->name }}</span>
    </nav>

    {{-- Category Header --}}
    <div class="card-sacred overflow-hidden mb-8">
        <div class="flex flex-col md:flex-row">
            @if($category->image_path)
                <div class="md:w-1/3 aspect-video md:aspect-auto flex-shrink-0 overflow-hidden"
                     style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
                    <img src="{{ asset('storage/' . $category->image_path) }}"
                         alt="{{ $category->name }}"
                         class="w-full h-full object-cover opacity-80">
                </div>
            @endif
            <div class="p-6 sm:p-8 flex flex-col justify-center">
                <h1 class="divine-heading text-2xl sm:text-3xl">{{ $category->name }}</h1>
                @if($category->description)
                    <p class="text-amber-100/50 mt-2 leading-relaxed">{{ $category->description }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="mb-8" x-data="{
        search: '{{ request('search', '') }}',
        sort: '{{ request('sort', 'newest') }}'
    }">
        <form method="GET" action="{{ route('store.category', $category->slug) }}"
              class="flex flex-col sm:flex-row gap-3 items-stretch sm:items-center">
            {{-- Search Input --}}
            <div class="relative flex-1">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-amber-100/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" x-model="search"
                       placeholder="ઉત્પાદન શોધો..."
                       class="w-full pl-10 pr-4 py-2.5 bg-transparent border border-amber-800/30 rounded-lg text-amber-100 placeholder:text-amber-100/20 focus:border-amber-600 focus:ring-amber-600/20 text-sm">
            </div>

            {{-- Sort Dropdown --}}
            <select name="sort" x-model="sort"
                    class="bg-transparent border border-amber-800/30 rounded-lg text-amber-100 px-4 py-2.5 focus:border-amber-600 focus:ring-amber-600/20 text-sm appearance-none cursor-pointer">
                <option value="newest" class="bg-stone-900 text-amber-100">નવીનતમ</option>
                <option value="price_asc" class="bg-stone-900 text-amber-100">ભાવ: ઓછાથી વધુ</option>
                <option value="price_desc" class="bg-stone-900 text-amber-100">ભાવ: વધુથી ઓછા</option>
            </select>

            {{-- Submit --}}
            <button type="submit" class="px-6 py-2.5 btn-divine text-sm whitespace-nowrap">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                </svg>
                ફિલ્ટર કરો
            </button>
        </form>

        {{-- Active filter indicators --}}
        @if(request('search') || request('sort', 'newest') !== 'newest')
            <div class="flex flex-wrap gap-2 mt-3">
                @if(request('search'))
                    <a href="{{ route('store.category', ['slug' => $category->slug, 'sort' => request('sort')]) }}"
                       class="inline-flex items-center gap-1 px-3 py-1 text-xs rounded-full bg-amber-900/30 text-amber-400 border border-amber-800/30 hover:border-amber-600 transition">
                        "{{ request('search') }}"
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </a>
                @endif
                @if(request('sort', 'newest') !== 'newest')
                    <a href="{{ route('store.category', ['slug' => $category->slug, 'search' => request('search')]) }}"
                       class="inline-flex items-center gap-1 px-3 py-1 text-xs rounded-full bg-amber-900/30 text-amber-400 border border-amber-800/30 hover:border-amber-600 transition">
                        @if(request('sort') === 'price_asc') ભાવ: ઓછાથી વધુ @else ભાવ: વધુથી ઓછા @endif
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </a>
                @endif
            </div>
        @endif
    </div>

    {{-- Products Grid --}}
    @if($products->isNotEmpty())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($products as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-8">
            {{ $products->withQueryString()->links() }}
        </div>
    @else
        <div class="text-center py-16 text-amber-100/30">
            <svg class="w-16 h-16 mx-auto mb-4 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <p class="text-lg">આ શ્રેણીમાં કોઈ ઉત્પાદન મળ્યું નથી.</p>
            @if(request('search'))
                <a href="{{ route('store.category', $category->slug) }}"
                   class="inline-flex items-center mt-4 px-6 py-2.5 btn-divine text-sm">
                    બધા ઉત્પાદનો જુઓ
                </a>
            @endif
        </div>
    @endif
</div>
@endsection
