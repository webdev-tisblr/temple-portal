@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('nav.trustees')]]"
    title="{{ __('nav.trustees') }}"
    subtitle="{{ __('trustees.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="max-w-3xl mx-auto text-center mb-12">
        <p class="text-amber-100/60 leading-relaxed text-lg">
            {{ __('trustees.intro') }}
        </p>
    </div>

    {{-- Trustee Cards Grid — full-width portrait photos, tap to view.
         The lightbox is pure client-side Alpine, so the page stays safe
         for the guest-page caches. --}}
    @if($trustees->isNotEmpty())
    <div x-data="{ photo: null, name: '', role: '' }" class="mb-12">
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
            @foreach($trustees as $trustee)
            <div
                class="card-sacred overflow-hidden {{ $trustee->photo_path ? 'cursor-pointer group' : '' }}"
                @if($trustee->photo_path)
                    @click="photo = '{{ image_url($trustee->photo_path) }}'; name = @js($trustee->name); role = @js($trustee->role ?? '')"
                @endif
            >
                <div class="aspect-[4/5] overflow-hidden bg-amber-900/30 flex items-center justify-center">
                    @if($trustee->photo_path)
                        <img src="{{ image_url($trustee->photo_path) }}" alt="{{ $trustee->name }}"
                             class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105" loading="lazy">
                    @else
                        <svg class="w-16 h-16 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                        </svg>
                    @endif
                </div>
                <div class="p-4 text-center">
                    <h3 class="text-base sm:text-lg font-bold text-gold leading-snug">{{ $trustee->name }}</h3>
                    @if($trustee->role)
                        <p class="text-amber-500 font-medium text-sm mt-1">{{ $trustee->role }}</p>
                    @endif
                    @if($trustee->location)
                        <p class="text-amber-100/40 text-sm mt-1.5">{{ $trustee->location }}</p>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        {{-- Lightbox --}}
        <div x-show="photo" x-cloak
             x-transition.opacity
             @click="photo = null"
             @keydown.escape.window="photo = null"
             class="fixed inset-0 z-[90] bg-black/90 flex flex-col items-center justify-center p-4">
            <button type="button" aria-label="Close"
                    class="absolute top-4 right-4 text-white/70 hover:text-white p-2">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <img :src="photo" alt="" class="max-h-[80vh] max-w-full rounded-xl shadow-2xl" @click.stop>
            <p class="text-white font-bold text-lg mt-4" x-text="name"></p>
            <p class="text-white/60 text-sm" x-text="role" x-show="role"></p>
        </div>
    </div>
    @endif

    {{-- Contact Info --}}
    <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-6 text-center">
        <p class="text-amber-100/60">
            {{ __('trustees.contact_before') }}
            <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold font-semibold underline transition">{{ __('nav.contact') }}</a>
            {{ __('trustees.contact_after') }}
        </p>
    </div>

</div>

@endsection
