@extends('layouts.app')

@section('content')

@php
    // Hall bookings have been a live feature with no dashboard presence at
    // all — the app listed them, the website did not. They are fetched here
    // rather than in DashboardController because that controller is owned by
    // another change in this batch; this is ONE query, run once outside every
    // loop, with `hall` eager-loaded so the panel cannot N+1. It repeats the
    // controller's captured-payments-only rule on purpose: an abandoned
    // Razorpay handoff must never read as a real reservation. Move it into
    // DashboardController::bookings() when that file is free.
    $hallBookings = \App\Models\HallBooking::query()
        ->where('devotee_id', auth('devotee')->id())
        ->whereHas('payment', fn (\Illuminate\Database\Eloquent\Builder $query) => $query->where('status', 'captured'))
        ->with('hall')
        ->orderByDesc('booking_date')
        ->limit(20)
        ->get();
@endphp

<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.my_bookings')],
    ]"
    title="{{ __('dashboard.my_bookings') }}"
    subtitle="{{ __('dashboard.my_bookings_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="bookings" />

    {{-- ── Seva bookings ──────────────────────────────────────────── --}}
    <x-dashboard.panel
        :title="__('dashboard.seva_bookings_heading')"
        icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">

        @if($bookings->isNotEmpty())
            <table class="dash-table">
                <thead class="dash-thead">
                    <tr>
                        <th class="dash-th">{{ __('dashboard.col_seva') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_date') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_time_slot') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_amount') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_status') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_action') }}</th>
                    </tr>
                </thead>
                <tbody class="dash-tbody">
                    @foreach($bookings as $booking)
                        @php
                            $status = $booking->status instanceof \App\Enums\BookingStatus
                                ? $booking->status->value
                                : (string) $booking->status;
                        @endphp
                        <tr class="dash-tr">
                            <x-dashboard.cell :label="__('dashboard.col_seva')">
                                <span class="font-semibold" style="color: #2A1810;">{{ optional($booking->seva)->name ?? '—' }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_date')">
                                {{ optional($booking->booking_date)->format('d/m/Y') ?? optional($booking->created_at)->format('d/m/Y') }}
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_time_slot')">
                                {{ $booking->slot_time_label ?? '—' }}
                            </x-dashboard.cell>

                            {{-- `total_amount` is the real column. The old view
                                 read `$booking->amount`, which does not exist
                                 on SevaBooking, so every row silently rendered
                                 ₹0. --}}
                            <x-dashboard.cell :label="__('dashboard.col_amount')">
                                <span class="font-semibold" style="color: #C45F12;">₹{{ inr((float) ($booking->total_amount ?? 0)) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_status')">
                                <x-dashboard.status-chip :status="$status" />
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_action')">
                                @if(in_array($status, ['confirmed', 'completed'], true))
                                    <a href="{{ route('dashboard.bookings.receipt', $booking) }}" class="dash-link">
                                        <x-dashboard.icon path="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" class="w-3.5 h-3.5" />
                                        {{ __('dashboard.receipt') }}
                                    </a>
                                @else
                                    <span style="color: #8A7860;">&mdash;</span>
                                @endif
                            </x-dashboard.cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($bookings->hasPages())
                <div class="dash-panel-foot">{{ $bookings->links() }}</div>
            @endif
        @else
            <x-dashboard.empty
                icon="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                :message="__('dashboard.no_seva_bookings')"
                :ctaHref="route('seva.index')"
                :ctaLabel="__('dashboard.book_seva_cta')" />
        @endif
    </x-dashboard.panel>

    {{-- ── Hall bookings ──────────────────────────────────────────────
         Multi-day ranges landed on temple_hall_bookings in this same batch
         (booking_date is the range START, end_date the last day), so the
         date column prints HallBooking::$date_range_label — never a bare
         start date, which would understate a three-day reservation. --}}
    <x-dashboard.panel
        class="mt-8"
        :title="__('dashboard.hall_bookings')"
        :subtitle="__('dashboard.hall_bookings_sub')"
        icon="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6">

        @if($hallBookings->isNotEmpty())
            <table class="dash-table">
                <thead class="dash-thead">
                    <tr>
                        <th class="dash-th">{{ __('dashboard.col_hall') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_dates') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_duration') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_amount') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_status') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_action') }}</th>
                    </tr>
                </thead>
                <tbody class="dash-tbody">
                    @foreach($hallBookings as $hallBooking)
                        @php
                            $hallStatus = $hallBooking->status instanceof \BackedEnum
                                ? (string) $hallBooking->status->value
                                : (string) $hallBooking->status;
                            $days = max(1, (int) ($hallBooking->days_count ?: 1));
                        @endphp
                        <tr class="dash-tr">
                            <x-dashboard.cell :label="__('dashboard.col_hall')">
                                <span class="font-semibold" style="color: #2A1810;">{{ optional($hallBooking->hall)->name ?? '—' }}</span>
                                @if($hallBooking->purpose)
                                    <span class="block text-xs" style="color: #8A7860;">{{ $hallBooking->purpose }}</span>
                                @endif
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_dates')">
                                {{ $hallBooking->date_range_label ?: '—' }}
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_duration')">
                                <span class="dash-chip dash-chip-info">{{ trans_choice('dashboard.days_count', $days, ['count' => $days]) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_amount')">
                                <span class="font-semibold" style="color: #C45F12;">₹{{ inr((float) ($hallBooking->total_amount ?? 0)) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_status')">
                                <x-dashboard.status-chip :status="$hallStatus" />
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_action')">
                                @if(in_array($hallStatus, ['confirmed', 'completed'], true))
                                    <a href="{{ route('hall.booking.invoice', $hallBooking) }}" class="dash-link">
                                        <x-dashboard.icon path="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" class="w-3.5 h-3.5" />
                                        {{ __('dashboard.invoice') }}
                                    </a>
                                @else
                                    <span style="color: #8A7860;">&mdash;</span>
                                @endif

                                {{-- Cancellation. The devotee may only ASK;
                                     the trust decides, so while a request is
                                     open the booking still reads as confirmed
                                     and only a waiting note is shown.
                                     Eligibility comes from the same service
                                     the POST enforces with. --}}
                                @php $cancelSvc = app(\App\Services\HallCancellationService::class); @endphp
                                @if($hallBooking->cancel_requested_at && ! $hallBooking->cancel_responded_at)
                                    <span class="block mt-1.5 text-xs font-semibold" style="color: #B45309;">
                                        {{ __('halls.cancel_pending') }}
                                    </span>
                                @elseif($cancelSvc->canRequest($hallBooking))
                                    <form method="POST" action="{{ route('hall.booking.cancel-request', $hallBooking) }}"
                                          class="mt-1.5"
                                          onsubmit="return confirm(@js(__('halls.cancel_confirm_body')));">
                                        @csrf
                                        <button type="submit" class="text-xs font-semibold underline underline-offset-2 transition hover:opacity-70"
                                                style="color: #9A3412;">
                                            {{ __('halls.request_cancellation') }}
                                        </button>
                                    </form>
                                @endif
                            </x-dashboard.cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <x-dashboard.empty
                icon="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"
                :message="__('dashboard.no_hall_bookings')"
                :ctaHref="route('hall.booking')"
                :ctaLabel="__('dashboard.book_hall')" />
        @endif
    </x-dashboard.panel>

</div>

@endsection
