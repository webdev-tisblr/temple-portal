@extends('layouts.app')

@section('content')

<x-page-header
    :breadcrumb="[['label' => __('nav.dashboard')]]"
    :title="__('home.hero_jai') . ', ' . ($devotee->name ?? __('common.devotee'))"
    subtitle="{{ __('dashboard.my_dashboard') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    {{-- Stat Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-10">
        <div class="card-sacred p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-medium text-amber-600">{{ __('dashboard.total_donation') }}</p>
                <div class="w-9 h-9 bg-emerald-950/40 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gold">₹{{ number_format((float) ($stats['total_donations'] ?? 0)) }}</p>
            <p class="text-xs text-amber-100/30 mt-1">{{ __('dashboard.total_donation_sub') }}</p>
        </div>

        <div class="card-sacred p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-medium text-amber-600">{{ __('dashboard.total_bookings') }}</p>
                <div class="w-9 h-9 bg-blue-950/40 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gold">{{ $stats['total_bookings'] ?? 0 }}</p>
            <p class="text-xs text-amber-100/30 mt-1">{{ __('dashboard.seva_bookings') }}</p>
        </div>

        <div class="card-sacred p-5">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-medium text-amber-600">{{ __('dashboard.pending') }}</p>
                <div class="w-9 h-9 bg-amber-950/40 rounded-lg flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gold">{{ $stats['pending_bookings'] ?? 0 }}</p>
            <p class="text-xs text-amber-100/30 mt-1">{{ __('dashboard.pending_bookings') }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

        {{-- Recent Donations --}}
        <div class="card-sacred overflow-hidden">
            <div class="px-5 py-4 border-b border-amber-900/20 flex items-center justify-between">
                <h2 class="font-bold text-amber-100/70">{{ __('dashboard.recent_donations') }}</h2>
                <a href="{{ route('dashboard.donations') }}" class="text-xs text-amber-500 font-medium hover:text-gold transition">{{ __('dashboard.view_all') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('common.date') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('common.amount') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('donation.type_label') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('dashboard.receipt') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @forelse($recentDonations as $donation)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-4 py-3 text-amber-100/60">{{ $donation->created_at->format('d M Y') }}</td>
                            <td class="px-4 py-3 font-semibold text-gold">₹{{ number_format((float) $donation->amount) }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-900/30 text-amber-400">
                                    {{ ucfirst($donation->getRawOriginal('donation_type')) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($donation->receipt && $donation->receipt->pdf_path)
                                    <a href="{{ route('dashboard.receipts.download', $donation->receipt) }}" class="text-amber-500 hover:text-gold text-xs font-medium transition">{{ __('dashboard.download') }}</a>
                                @else
                                    <span class="text-amber-100/20 text-xs">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-amber-100/30 text-sm">{{ __('dashboard.no_donations') }}</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Recent Bookings --}}
        <div class="card-sacred overflow-hidden">
            <div class="px-5 py-4 border-b border-amber-900/20 flex items-center justify-between">
                <h2 class="font-bold text-amber-100/70">{{ __('dashboard.recent_bookings') }}</h2>
                <a href="{{ route('dashboard.bookings') }}" class="text-xs text-amber-500 font-medium hover:text-gold transition">{{ __('dashboard.view_all') }}</a>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('nav.seva') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('common.date') }}</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-amber-600 uppercase tracking-wider">{{ __('common.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @forelse($recentBookings as $booking)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-4 py-3 text-amber-100/60 font-medium">{{ $booking->seva?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-amber-100/50">{{ $booking->booking_date->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                @php $s = $booking->status instanceof \App\Enums\BookingStatus ? $booking->status->value : (string)$booking->status; @endphp
                                <span class="px-2 py-0.5 rounded-full text-xs font-semibold
                                    {{ $s === 'confirmed' ? 'bg-emerald-950/40 text-emerald-400' : ($s === 'cancelled' ? 'bg-red-950/40 text-red-400' : 'bg-amber-900/30 text-amber-400') }}">
                                    {{ ucfirst($s) }}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="3" class="px-4 py-8 text-center text-amber-100/30 text-sm">{{ __('dashboard.no_bookings') }}</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    {{-- Quick Actions --}}
    <div class="mt-8 grid grid-cols-2 sm:grid-cols-5 gap-4">
        <a href="{{ route('donate') }}" class="flex flex-col items-center gap-2 p-4 bg-amber-900/20 hover:bg-amber-900/30 border border-amber-800/30 rounded-xl transition text-center">
            <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
            <span class="text-xs font-semibold text-amber-400">{{ __('nav.donate') }}</span>
        </a>
        <a href="{{ route('seva.index') }}" class="flex flex-col items-center gap-2 p-4 bg-amber-900/20 hover:bg-amber-900/30 border border-amber-800/30 rounded-xl transition text-center">
            <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span class="text-xs font-semibold text-amber-400">{{ __('dashboard.book_seva') }}</span>
        </a>
        <a href="{{ route('store.index') }}" class="flex flex-col items-center gap-2 p-4 bg-amber-900/20 hover:bg-amber-900/30 border border-amber-800/30 rounded-xl transition text-center">
            <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            <span class="text-xs font-semibold text-amber-400">{{ __('nav.store') }}</span>
        </a>
        <a href="{{ route('dashboard.receipts') }}" class="flex flex-col items-center gap-2 p-4 bg-amber-900/20 hover:bg-amber-900/30 border border-amber-800/30 rounded-xl transition text-center">
            <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span class="text-xs font-semibold text-amber-400">{{ __('donation.receipt_80g') }}</span>
        </a>
        <a href="{{ route('dashboard.profile') }}" class="flex flex-col items-center gap-2 p-4 bg-amber-900/20 hover:bg-amber-900/30 border border-amber-800/30 rounded-xl transition text-center">
            <svg class="w-6 h-6 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <span class="text-xs font-semibold text-amber-400">{{ __('dashboard.profile') }}</span>
        </a>
    </div>

</div>

@endsection
