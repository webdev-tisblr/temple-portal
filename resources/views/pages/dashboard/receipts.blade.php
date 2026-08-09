@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.receipts_80g')],
    ]"
    title="{{ __('dashboard.receipts_80g') }}"
    subtitle="{{ __('dashboard.receipts_80g_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="receipts" />

    <div class="dash-notice dash-notice-info mb-6">
        <x-dashboard.icon path="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" class="w-5 h-5 shrink-0 mt-0.5" />
        <p>
            {{ __('dashboard.receipts_note') }}
            <a href="{{ route('dashboard.profile') }}" class="font-semibold underline">{{ __('dashboard.update_profile_link') }}</a>
        </p>
    </div>

    <x-dashboard.panel
        :title="__('dashboard.receipts_80g')"
        icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">

        @if($receipts->isNotEmpty())
            <table class="dash-table">
                <thead class="dash-thead">
                    <tr>
                        <th class="dash-th">{{ __('dashboard.col_receipt_no') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_donation_date') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_amount') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_financial_year') }}</th>
                        <th class="dash-th">{{ __('dashboard.download') }}</th>
                    </tr>
                </thead>
                <tbody class="dash-tbody">
                    @foreach($receipts as $receipt)
                        <tr class="dash-tr">
                            <x-dashboard.cell :label="__('dashboard.col_receipt_no')">
                                <span class="font-mono text-sm font-semibold" style="color: #2A1810;">{{ $receipt->receipt_number }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_donation_date')">
                                {{ optional($receipt->donation_date)->format('d/m/Y') ?? optional($receipt->created_at)->format('d/m/Y') }}
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_amount')">
                                <span class="font-semibold" style="color: #C45F12;">₹{{ number_format((float) $receipt->amount) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_financial_year')">
                                <span class="dash-chip dash-chip-info">{{ $receipt->financial_year ?? '—' }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.download')">
                                <a href="{{ route('dashboard.receipts.download', $receipt) }}"
                                   class="btn-divine !px-4 !py-2 !text-xs">
                                    <x-dashboard.icon path="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" class="w-3.5 h-3.5" />
                                    {{ __('dashboard.pdf_download') }}
                                </a>
                            </x-dashboard.cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($receipts->hasPages())
                <div class="dash-panel-foot">{{ $receipts->links() }}</div>
            @endif
        @else
            <x-dashboard.empty
                icon="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                :message="__('dashboard.no_receipts')"
                :hint="__('dashboard.receipts_after_donation')" />
        @endif
    </x-dashboard.panel>

</div>

@endsection
