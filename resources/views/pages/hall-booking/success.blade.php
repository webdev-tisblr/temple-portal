@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    @if($verified)
        <div class="w-20 h-20 bg-emerald-950/50 border border-emerald-800/40 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-emerald-300 mb-2">હોલ બુકિંગ સફળ!</h1>
        <p class="text-amber-100/50 mb-4">તમારું હોલ બુકિંગ સફળતાપૂર્વક નોંધાઈ ગયું છે. ઇનવૉઇસ ઇમેઇલ/WhatsApp પર મોકલવામાં આવશે.</p>

        @if($booking)
            {{-- Booking Details --}}
            <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-5 text-left mt-6 text-sm space-y-0">
                <p class="text-xs text-amber-500 uppercase tracking-wider font-semibold mb-3">બુકિંગ વિગતો</p>

                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">હોલ</span>
                    <span class="font-semibold text-amber-100/80">{{ $booking->hall->name }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">તારીખ</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->booking_date->format('d M Y') }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">પ્રકાર</span>
                    <span class="font-medium text-amber-100/70">
                        @if($booking->booking_type === 'full_day')
                            આખો દિવસ (Full Day)
                        @elseif($booking->booking_type === 'half_day_morning')
                            અડધો દિવસ - સવાર
                        @elseif($booking->booking_type === 'half_day_evening')
                            અડધો દિવસ - સાંજ
                        @else
                            {{ $booking->booking_type }}
                        @endif
                    </span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">હેતુ</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->purpose }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">સંપર્ક</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->contact_name }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">ફોન</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->contact_phone }}</span>
                </div>
                <div class="flex justify-between py-2.5">
                    <span class="text-amber-100/40">કુલ રકમ</span>
                    <span class="font-bold text-gold text-base">₹{{ number_format((float) $booking->total_amount, 2) }}</span>
                </div>
            </div>

            {{-- Invoice Download --}}
            @if($booking->invoice_path)
                <div class="mt-4">
                    <a href="{{ route('hall.booking.invoice', $booking) }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 text-sm bg-amber-900/20 border border-amber-800/30 rounded-lg text-amber-400 hover:text-gold hover:border-amber-600 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        ઇનવૉઇસ ડાઉનલોડ કરો
                    </a>
                </div>
            @endif
        @endif

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('home') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">મુખ્ય પૃષ્ઠ</a>
            <a href="{{ route('dashboard.index') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">ડેશબોર્ડ</a>
        </div>
    @else
        <div class="w-20 h-20 bg-amber-900/30 border border-amber-700/30 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"/>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-amber-300 mb-2">ચકાસણી બાકી</h1>
        <p class="text-amber-100/50">પેમેન્ટ ચકાસણી થઈ શકી નથી. જો પેમેન્ટ સફળ થયું હોય, તો થોડીવારમાં તમારા ડેશબોર્ડમાં દેખાશે.</p>
        <a href="{{ route('dashboard.index') }}" class="mt-6 inline-flex items-center px-6 py-2.5 btn-divine">ડેશબોર્ડ જુઓ</a>
    @endif
</div>
@endsection
