@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('rules.page_title')]]"
    title="{{ __('rules.page_title') }}"
    subtitle="{{ __('rules.subtitle') }}" />

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">
    <div class="card-sacred p-6 sm:p-10">

        <p class="text-amber-100/60 mb-8 leading-relaxed">
            {{ __('rules.intro') }}
        </p>

        <ol class="space-y-6">

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">1</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r1t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r1d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">2</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r2t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r2d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">3</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r3t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r3d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">4</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r4t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r4d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">5</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r5t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r5d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">6</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r6t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r6d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">7</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r7t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r7d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">8</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r8t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r8d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">9</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r9t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r9d') }}</p>
                </div>
            </li>

            <li class="flex items-start gap-4">
                <span class="flex-shrink-0 w-9 h-9 bg-amber-900/40 text-gold border border-amber-700/40 rounded-full flex items-center justify-center font-bold text-sm">10</span>
                <div>
                    <p class="font-semibold text-amber-100/70">{{ __('rules.r10t') }}</p>
                    <p class="text-sm text-amber-100/40 mt-1">{{ __('rules.r10d') }}</p>
                </div>
            </li>

        </ol>

        <div class="mt-10 bg-amber-900/20 border border-amber-800/30 rounded-xl p-5">
            <p class="text-sm text-amber-100/50">
                {{ __('rules.footer1') }}
                {{ __('rules.footer2_before') }} <a href="{{ route('contact') }}" class="text-amber-500 hover:text-gold underline font-semibold transition">{{ __('nav.contact') }}</a> {{ __('rules.footer2_after') }}
            </p>
        </div>

    </div>
</div>

@endsection
