@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('projects.title')]]"
    title="{{ __('projects.title') }}"
    subtitle="{{ __('projects.subtitle') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Projects Grid — adaptive: 1 → full-width horizontal card,
         2 → 50/50, 3+ → standard grid (shared with the home page). --}}
    @if($projects->isNotEmpty())
        @include('partials.campaign-grid', ['campaignItems' => $projects->getCollection()])

        {{-- Pagination --}}
        <div class="mt-10">
            {{ $projects->links() }}
        </div>
    @else
        {{-- Empty State --}}
        <div class="text-center py-16 text-amber-100/30">
            <svg class="w-16 h-16 mx-auto mb-4 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <p class="text-lg">{{ __('projects.none_available') }}</p>
        </div>
    @endif
</div>
@endsection
