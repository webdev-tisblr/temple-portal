@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('status.title')]]"
    title="{{ __('status.title') }}"
    subtitle="{{ __('status.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- App CTA — the generator itself lives in the mobile app --}}
    <div class="card-sacred p-6 sm:p-8 mb-10 text-center">
        <p class="text-amber-100/70 leading-relaxed max-w-2xl mx-auto">{{ __('status.app_only') }}</p>
        <p class="text-gold font-bold mt-4">{{ __('status.get_app') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-4 mt-5">
            @if($androidUrl)
                <a href="{{ $androidUrl }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 2.3l10.8 10.8-10.8 10.8c-.4-.2-.6-.6-.6-1.1V3.4c0-.5.2-.9.6-1.1zm12 9.6L5.2 1.5l11.9 6.9-1.5 3.5zm2.9 1.7l2.6 1.5c.8.5.8 1.6 0 2.1l-2.6 1.5-2-2.6 2-2.5zm-2.9 3.4l1.5 3.5-11.9 6.9 10.4-10.4z"/></svg>
                    <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">GET IT ON</span><span class="block text-sm font-bold">Google Play</span></span>
                </a>
            @endif
            @if($iosUrl)
                <a href="{{ $iosUrl }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8.79-.16 2.31-.93 3.9-.79 1.9.15 3.32.9 4.27 2.26-3.9 2.35-3.28 7.5.75 8.94-.61 1.42-1.38 2.83-2.61 3.76h-.39zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                    <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">Download on the</span><span class="block text-sm font-bold">App Store</span></span>
                </a>
            @endif
        </div>
    </div>

    {{-- Template gallery (previews only) --}}
    @if($templates->isNotEmpty())
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
            @foreach($templates as $t)
                <div class="rounded-xl overflow-hidden border border-amber-900/20 bg-amber-900/10">
                    <img src="{{ image_url($t->greeting_card_template) }}"
                         alt="{{ $t->title }}"
                         loading="lazy"
                         class="w-full h-auto object-cover">
                    <p class="px-3 py-2 text-sm text-amber-100/70 text-center">{{ $t->title }}</p>
                </div>
            @endforeach
        </div>
    @endif

</div>

@endsection
