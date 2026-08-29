@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.my_donations')],
    ]"
    title="{{ __('dashboard.my_donations') }}"
    subtitle="{{ __('dashboard.my_donations_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="donations" />

    {{-- The controller lists captured payments only. A donation whose
         Razorpay handoff was abandoned must never appear here as real. --}}
    <x-dashboard.panel
        :title="__('dashboard.my_donations')"
        icon="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z">

        @if($donations->isNotEmpty())
            <table class="dash-table">
                <thead class="dash-thead">
                    <tr>
                        <th class="dash-th">{{ __('dashboard.col_date') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_amount') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_type') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_receipt') }}</th>
                    </tr>
                </thead>
                <tbody class="dash-tbody">
                    @foreach($donations as $donation)
                        <tr class="dash-tr">
                            <x-dashboard.cell :label="__('dashboard.col_date')">
                                {{ $donation->created_at->format('d/m/Y') }}
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_amount')">
                                <span class="font-semibold" style="color: #C45F12;">₹{{ inr((float) $donation->amount) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_type')">
                                <span class="dash-chip dash-chip-info">{{ ucfirst((string) $donation->getRawOriginal('donation_type')) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_receipt')">
                                @if($donation->receipt && $donation->receipt->pdf_path)
                                    <a href="{{ route('dashboard.receipts.download', $donation->receipt) }}" class="dash-link">
                                        <x-dashboard.icon path="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" class="w-3.5 h-3.5" />
                                        {{ __('dashboard.download') }}
                                    </a>
                                @else
                                    <span style="color: #8A7860;">&mdash;</span>
                                @endif
                            </x-dashboard.cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($donations->hasPages())
                <div class="dash-panel-foot">{{ $donations->links() }}</div>
            @endif
        @else
            <x-dashboard.empty
                icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                :message="__('dashboard.no_donation_records')"
                :ctaHref="route('donate')"
                :ctaLabel="__('nav.donate')" />
        @endif
    </x-dashboard.panel>

</div>

@endsection
