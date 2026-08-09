@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('nav.dashboard')]]"
    :title="__('home.hero_jai') . ', ' . ($devotee->name ?? __('common.devotee'))"
    subtitle="{{ __('dashboard.my_dashboard') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="index" />

    {{-- Stat cards. Every figure here comes from the controller's
         captured-payments-only queries — an uncaptured donation must never
         be counted as money given. --}}
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
        @php
            $stats_cards = [
                [
                    'label' => __('dashboard.total_donation'),
                    'value' => '₹'.number_format((float) ($stats['total_donations'] ?? 0)),
                    'sub' => __('dashboard.total_donation_sub'),
                    'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                    'href' => route('dashboard.donations'),
                ],
                [
                    'label' => __('dashboard.total_bookings'),
                    'value' => (string) ($stats['total_bookings'] ?? 0),
                    'sub' => __('dashboard.seva_bookings'),
                    'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
                    'href' => route('dashboard.bookings'),
                ],
                [
                    'label' => __('dashboard.pending'),
                    'value' => (string) ($stats['pending_bookings'] ?? 0),
                    'sub' => __('dashboard.pending_bookings'),
                    'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                    'href' => route('dashboard.bookings'),
                ],
            ];
        @endphp

        @foreach($stats_cards as $card)
            <a href="{{ $card['href'] }}" class="card-sacred block p-5">
                <div class="mb-3 flex items-start justify-between gap-3">
                    <p class="text-sm font-semibold" style="color: #7A1E1E;">{{ $card['label'] }}</p>
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                          style="background: rgba(232,117,26,0.12); color: #C45F12;">
                        <x-dashboard.icon :path="$card['icon']" />
                    </span>
                </div>
                <p class="font-serif text-3xl font-semibold" style="color: #2A1810;">{{ $card['value'] }}</p>
                <p class="mt-1 text-xs" style="color: #8A7860;">{{ $card['sub'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Recent donations --}}
        <x-dashboard.panel
            :title="__('dashboard.recent_donations')"
            icon="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"
            :href="route('dashboard.donations')"
            :linkLabel="__('dashboard.view_all')">

            @if($recentDonations->isNotEmpty())
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
                        @foreach($recentDonations as $donation)
                            <tr class="dash-tr">
                                <x-dashboard.cell :label="__('dashboard.col_date')">
                                    {{ $donation->created_at->format('d/m/Y') }}
                                </x-dashboard.cell>

                                <x-dashboard.cell :label="__('dashboard.col_amount')">
                                    <span class="font-semibold" style="color: #C45F12;">₹{{ number_format((float) $donation->amount) }}</span>
                                </x-dashboard.cell>

                                <x-dashboard.cell :label="__('dashboard.col_type')">
                                    <span class="dash-chip dash-chip-info">{{ ucfirst((string) $donation->getRawOriginal('donation_type')) }}</span>
                                </x-dashboard.cell>

                                <x-dashboard.cell :label="__('dashboard.col_receipt')">
                                    @if($donation->receipt && $donation->receipt->pdf_path)
                                        <a href="{{ route('dashboard.receipts.download', $donation->receipt) }}" class="dash-link">{{ __('dashboard.download') }}</a>
                                    @else
                                        <span style="color: #8A7860;">&mdash;</span>
                                    @endif
                                </x-dashboard.cell>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <x-dashboard.empty
                    icon="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                    :message="__('dashboard.no_donations')"
                    :ctaHref="route('donate')"
                    :ctaLabel="__('nav.donate')" />
            @endif
        </x-dashboard.panel>

        {{-- Recent seva bookings --}}
        <x-dashboard.panel
            :title="__('dashboard.recent_bookings')"
            icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
            :href="route('dashboard.bookings')"
            :linkLabel="__('dashboard.view_all')">

            @if($recentBookings->isNotEmpty())
                <table class="dash-table">
                    <thead class="dash-thead">
                        <tr>
                            <th class="dash-th">{{ __('dashboard.col_seva') }}</th>
                            <th class="dash-th">{{ __('dashboard.col_date') }}</th>
                            <th class="dash-th">{{ __('dashboard.col_status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="dash-tbody">
                        @foreach($recentBookings as $booking)
                            <tr class="dash-tr">
                                <x-dashboard.cell :label="__('dashboard.col_seva')">
                                    <span class="font-semibold" style="color: #2A1810;">{{ $booking->seva?->name ?? '—' }}</span>
                                </x-dashboard.cell>

                                <x-dashboard.cell :label="__('dashboard.col_date')">
                                    {{ optional($booking->booking_date)->format('d/m/Y') ?? '—' }}
                                </x-dashboard.cell>

                                <x-dashboard.cell :label="__('dashboard.col_status')">
                                    <x-dashboard.status-chip :status="$booking->status" />
                                </x-dashboard.cell>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <x-dashboard.empty
                    icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                    :message="__('dashboard.no_bookings')"
                    :ctaHref="route('seva.index')"
                    :ctaLabel="__('dashboard.book_seva_cta')" />
            @endif
        </x-dashboard.panel>

    </div>

    {{-- Quick actions --}}
    @php
        $quick_actions = [
            ['href' => route('donate'), 'label' => __('nav.donate'), 'icon' => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'],
            ['href' => route('seva.index'), 'label' => __('dashboard.book_seva'), 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['href' => route('hall.booking'), 'label' => __('dashboard.book_hall'), 'icon' => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'],
            ['href' => route('store.index'), 'label' => __('nav.store'), 'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
            ['href' => route('dashboard.receipts'), 'label' => __('donation.receipt_80g'), 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['href' => route('dashboard.profile'), 'label' => __('dashboard.profile'), 'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
        ];
    @endphp

    <h2 class="mt-10 mb-4 text-lg font-semibold" style="font-family: 'Marcellus', 'Noto Serif Gujarati', 'Noto Serif Devanagari', serif; color: #7A1E1E;">
        {{ __('dashboard.quick_actions') }}
    </h2>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        @foreach($quick_actions as $action)
            <a href="{{ $action['href'] }}" class="card-sacred flex flex-col items-center gap-2.5 p-4 text-center">
                <span class="flex h-10 w-10 items-center justify-center rounded-full"
                      style="background: rgba(232,117,26,0.12); color: #C45F12;">
                    <x-dashboard.icon :path="$action['icon']" />
                </span>
                <span class="text-xs font-semibold" style="color: #2A1810;">{{ $action['label'] }}</span>
            </a>
        @endforeach
    </div>

</div>

@endsection
