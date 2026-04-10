@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    @if($verified)
        <div class="w-20 h-20 bg-emerald-950/50 border border-emerald-800/40 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>

        @if($booking)
            <h1 class="text-2xl font-bold text-emerald-300 mb-2">સેવા બુક થઈ ગઈ!</h1>
            <p class="text-amber-100/50 mb-4">તમારી સેવા સફળતાપૂર્વક બુક થઈ ગઈ છે. 80G રસીદ ઇમેઇલ/WhatsApp પર મોકલવામાં આવશે.</p>

            {{-- Booking Details --}}
            <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-5 text-left mt-6 text-sm space-y-0">
                <p class="text-xs text-amber-500 uppercase tracking-wider font-semibold mb-3">બુકિંગ વિગતો</p>

                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">સેવા</span>
                    <span class="font-semibold text-amber-100/80">{{ $booking->seva->name }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">તારીખ</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->booking_date->format('d M Y') }}</span>
                </div>
                @if($booking->slot_time)
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">સમય</span>
                    <span class="font-medium text-amber-100/70">{{ \Carbon\Carbon::parse($booking->slot_time)->format('h:i A') }}</span>
                </div>
                @endif
                @if($booking->devotee_name_for_seva)
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">ભક્તનું નામ</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->devotee_name_for_seva }}</span>
                </div>
                @endif
                @if($booking->gotra)
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">ગોત્ર</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->gotra }}</span>
                </div>
                @endif
                @if($booking->sankalp)
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">સંકલ્પ</span>
                    <span class="font-medium text-amber-100/70">{{ $booking->sankalp }}</span>
                </div>
                @endif
                <div class="flex justify-between py-2.5">
                    <span class="text-amber-100/40">રકમ</span>
                    <span class="font-bold text-gold text-base">₹{{ number_format((float) $booking->total_amount, 2) }}</span>
                </div>
                @if($booking->selected_product_id && $booking->selectedProduct)
                    <div class="flex justify-between py-2.5 border-t border-amber-900/20">
                        <span class="text-amber-100/40">પસંદ કરેલ</span>
                        <div class="flex items-center gap-2">
                            @if($booking->selectedProduct->image_path)
                                <img src="{{ asset('storage/' . $booking->selectedProduct->image_path) }}" alt="" class="w-8 h-8 rounded object-cover">
                            @endif
                            <span class="font-medium text-amber-100/70">{{ $booking->selectedProduct->name }}</span>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Assignee Contact --}}
            @if($booking->seva->assignee)
                @php $assignee = $booking->seva->assignee; @endphp
                <div class="bg-amber-900/15 border border-amber-800/20 rounded-xl p-5 text-left mt-4 text-sm">
                    <p class="text-xs text-amber-500 uppercase tracking-wider font-semibold mb-3">સેવા સંપર્ક</p>
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 bg-amber-900/30 rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="font-semibold text-amber-100/80">{{ $assignee->name }}</p>
                            @if($assignee->phone)
                                <a href="tel:+91{{ $assignee->phone }}" class="text-amber-500 hover:text-gold transition flex items-center gap-1 mt-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                                    +91 {{ $assignee->phone }}
                                </a>
                            @endif
                            @if($assignee->email)
                                <a href="mailto:{{ $assignee->email }}" class="text-amber-500 hover:text-gold transition flex items-center gap-1 mt-1">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                    {{ $assignee->email }}
                                </a>
                            @endif
                            <p class="text-amber-100/30 text-xs mt-1.5">સેવા સંબંધિત કોઈ પ્રશ્ન માટે સંપર્ક કરો.</p>
                        </div>
                    </div>
                </div>
            @endif

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('seva.index') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">વધુ સેવા જુઓ</a>
                <a href="{{ route('dashboard.index') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">ડેશબોર્ડ</a>
            </div>
        @else
            <h1 class="text-2xl font-bold text-emerald-300 mb-2">પેમેન્ટ સફળ!</h1>
            <p class="text-amber-100/50 mb-4">તમારું પેમેન્ટ સફળતાપૂર્વક પ્રાપ્ત થયું છે. 80G રસીદ ઇમેઇલ/WhatsApp પર મોકલવામાં આવશે.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('home') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">હોમ</a>
                <a href="{{ route('dashboard.index') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">ડેશબોર્ડ</a>
            </div>
        @endif
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
