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

    {{-- Trustee Cards Grid --}}
    @if($trustees->isNotEmpty())
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
        @foreach($trustees as $trustee)
        <div class="card-sacred p-6 text-center">
            <div class="w-20 h-20 rounded-full mx-auto mb-4 overflow-hidden bg-amber-900/30 flex items-center justify-center">
                @if($trustee->photo_path)
                    <img src="{{ image_url($trustee->photo_path) }}" alt="{{ $trustee->name }}" class="w-full h-full object-cover" loading="lazy">
                @else
                    <svg class="w-10 h-10 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                @endif
            </div>
            <h3 class="text-lg font-bold text-gold">{{ $trustee->name }}</h3>
            @if($trustee->role)
                <p class="text-amber-500 font-medium text-sm mt-1">{{ $trustee->role }}</p>
            @endif
            @if($trustee->location)
                <p class="text-amber-100/40 text-sm mt-2">{{ $trustee->location }}</p>
            @endif
        </div>
        @endforeach
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
