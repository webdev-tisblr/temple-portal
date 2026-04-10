@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <span class="text-gold">મંદિર સ્ટોર</span>
    </nav>

    {{-- Page Header --}}
    <div class="text-center mb-10">
        <h1 class="divine-heading text-3xl">મંદિર સ્ટોર</h1>
        <p class="divine-subtext mt-2">મંદિરની પવિત્ર વસ્તુઓ અને પૂજા સામગ્રી</p>
    </div>

    {{-- Categories Section --}}
    @if($categories->isNotEmpty())
        <div class="mb-12">
            <h2 class="text-xl font-bold text-gold mb-6">શ્રેણીઓ</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($categories as $category)
                    <a href="{{ route('store.category', $category->slug) }}"
                       class="card-sacred group block overflow-hidden">
                        <div class="aspect-[16/9] flex items-center justify-center relative overflow-hidden"
                             style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
                            @if($category->image_path)
                                <img src="{{ asset('storage/' . $category->image_path) }}"
                                     alt="{{ $category->name }}"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 opacity-80 group-hover:opacity-100">
                            @else
                                <div class="text-center">
                                    <svg class="w-14 h-14 text-amber-800/40 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                              d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                    </svg>
                                </div>
                            @endif
                        </div>
                        <div class="p-5">
                            <h3 class="text-lg font-bold text-gold group-hover:text-amber-300 transition-colors">{{ $category->name }}</h3>
                            @if($category->description)
                                <p class="text-sm text-amber-100/30 mt-1 line-clamp-2">{{ $category->description }}</p>
                            @endif
                            <div class="flex items-center justify-between mt-3 pt-3 border-t border-amber-900/20">
                                <span class="text-sm text-amber-100/40">{{ $category->products_count }} ઉત્પાદનો</span>
                                <span class="text-amber-600 text-sm font-semibold group-hover:translate-x-1 transition-transform flex items-center gap-1">
                                    જુઓ <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <div class="text-center py-16 text-amber-100/30">
            <svg class="w-16 h-16 mx-auto mb-4 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
            <p class="text-lg">હાલમાં સ્ટોરમાં કોઈ શ્રેણી ઉપલબ્ધ નથી.</p>
        </div>
    @endif

    {{-- Featured Products Section --}}
    @if($featured->isNotEmpty())
        <div class="mt-4">
            <h2 class="text-xl font-bold text-gold mb-6">વિશેષ ઉત્પાદનો</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($featured as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
