@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('footer.pujari')]]"
    title="{{ __('footer.pujari') }}"
    subtitle="{{ __('pujari.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Intro --}}
    <div class="max-w-3xl mx-auto text-center mb-12">
        <p class="text-amber-100/60 leading-relaxed text-lg">
            {{ __('pujari.intro') }}
        </p>
    </div>

    {{-- Priest Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">

        {{-- Priest 1 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-700/40 to-amber-600/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">{{ __('pujari.n1') }}</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">{{ __('pujari.r1') }}</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>{{ __('pujari.e1') }}</p>
                    <p>{{ __('pujari.t1') }}</p>
                </div>
            </div>
        </div>

        {{-- Priest 2 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-500/40 to-amber-400/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">{{ __('pujari.n2') }}</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">{{ __('pujari.r2') }}</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>{{ __('pujari.e2') }}</p>
                    <p>{{ __('pujari.t2') }}</p>
                </div>
            </div>
        </div>

        {{-- Priest 3 --}}
        <div class="card-sacred overflow-hidden">
            <div class="bg-gradient-to-r from-amber-600/40 to-amber-500/20 h-2"></div>
            <div class="p-6 text-center">
                <div class="w-24 h-24 bg-amber-900/30 rounded-full mx-auto mb-4 flex items-center justify-center">
                    <svg class="w-12 h-12 text-amber-600/60" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gold">{{ __('pujari.n3') }}</h3>
                <p class="text-amber-500 font-semibold text-sm mt-1">{{ __('pujari.r3') }}</p>
                <div class="mt-4 pt-4 border-t border-amber-900/20 text-sm text-amber-100/40 space-y-1">
                    <p>{{ __('pujari.e3') }}</p>
                    <p>{{ __('pujari.t3') }}</p>
                </div>
            </div>
        </div>

    </div>

    {{-- Daily Duties Info --}}
    <div class="card-sacred p-6 sm:p-8">
        <h2 class="text-xl font-bold text-gold mb-4">{{ __('pujari.daily') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm text-amber-100/60">
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">1</span>
                <span>{{ __('pujari.s1') }}</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">2</span>
                <span>{{ __('pujari.s2') }}</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">3</span>
                <span>{{ __('pujari.s3') }}</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">4</span>
                <span>{{ __('pujari.s4') }}</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">5</span>
                <span>{{ __('pujari.s5') }}</span>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex-shrink-0 w-7 h-7 bg-amber-900/40 text-amber-400 rounded-full flex items-center justify-center text-xs font-bold">6</span>
                <span>{{ __('pujari.s6') }}</span>
            </div>
        </div>
    </div>

</div>

@endsection
