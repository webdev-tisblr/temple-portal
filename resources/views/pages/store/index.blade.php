@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('footer.temple_store')]]"
    title="{{ __('footer.temple_store') }}"
    subtitle="{{ __('store.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Categories Section --}}
    @if($categories->isNotEmpty())
        <div class="mb-12">
            <h2 class="text-xl font-bold text-gold mb-6">{{ __('store.categories') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($categories as $category)
                    <a href="{{ route('store.category', $category->slug) }}"
                       class="card-sacred group block overflow-hidden">
                        <div class="aspect-[16/9] flex items-center justify-center relative overflow-hidden"
                             style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
                            @if($category->image_path)
                                <img src="{{ image_url($category->image_path) }}"
                                     alt="{{ $category->name }}"
                                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
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
                                <span class="text-sm text-amber-100/40">{{ $category->products_count }} {{ __('store.products_count') }}</span>
                                <span class="text-amber-600 text-sm font-semibold group-hover:translate-x-1 transition-transform flex items-center gap-1">
                                    {{ __('store.view') }} <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
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
            <p class="text-lg">{{ __('store.no_categories') }}</p>
        </div>
    @endif

    {{-- Featured Products Section --}}
    @if($featured->isNotEmpty())
        <div class="mt-4">
            <h2 class="text-xl font-bold text-gold mb-6">{{ __('store.featured_products') }}</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach($featured as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
