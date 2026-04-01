@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('dashboard.index') }}" class="hover:text-gold transition">Dashboard</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">મારી બુકિંગ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">મારી બુકિંગ</h1>
        <p class="mt-2 divine-subtext">Tamari seva bookings ni list</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <div class="card-sacred overflow-hidden">

        @if($bookings->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-amber-900/15 border-b border-amber-900/20">
                        <tr>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Seva</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Tarikh</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Samay (Slot)</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">Rakam</th>
                            <th class="text-left px-5 py-3.5 text-xs font-semibold text-amber-600 uppercase tracking-wider">સ્થિતિ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-900/15">
                        @foreach($bookings as $booking)
                        <tr class="hover:bg-amber-900/10 transition">
                            <td class="px-5 py-4">
                                <span class="font-semibold text-amber-100/70">{{ optional($booking->seva)->name ?? 'N/A' }}</span>
                            </td>
                            <td class="px-5 py-4 text-amber-100/60">
                                {{ optional($booking->booking_date)->format('d M Y') ?? optional($booking->created_at)->format('d M Y') }}
                            </td>
                            <td class="px-5 py-4 text-amber-100/60">
                                {{ $booking->slot_time ?? '—' }}
                            </td>
                            <td class="px-5 py-4 font-semibold text-gold">
                                ₹{{ number_format($booking->amount ?? 0) }}
                            </td>
                            <td class="px-5 py-4">
                                @php $status = $booking->status instanceof \App\Enums\BookingStatus ? $booking->status->value : (string)$booking->status; @endphp
                                @if($status === 'confirmed')
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-950/40 text-emerald-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        પુષ્ટિ થઈ
                                    </span>
                                @elseif($status === 'cancelled')
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-950/40 text-red-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500"></span>
                                        રદ કરી
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-900/30 text-amber-400">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        પ્રતીક્ષા
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t border-amber-900/20">
                {{ $bookings->links() }}
            </div>

        @else
            <div class="text-center py-16">
                <svg class="w-12 h-12 text-amber-800/30 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p class="text-amber-100/30 mb-4">Tamari koi seva bookings nathi.</p>
                <a href="{{ route('seva.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 btn-divine text-sm font-semibold">
                    Seva Book karo
                </a>
            </div>
        @endif

    </div>

</div>

@endsection
