@extends('layouts.app')

@section('content')
<div class="max-w-lg mx-auto px-4 py-16 text-center bg-temple">
    @if($verified)
        <div class="w-20 h-20 bg-emerald-950/50 border border-emerald-800/40 rounded-full flex items-center justify-center mx-auto mb-6">
            <svg class="w-10 h-10 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>

        @if($order)
            <h1 class="text-2xl font-bold text-emerald-300 mb-2">ઓર્ડર સફળ!</h1>
            <p class="text-amber-100/50 mb-4">તમારો ઓર્ડર સફળતાપૂર્વક નોંધાઈ ગયો છે. ઇનવૉઇસ ઇમેઇલ/WhatsApp પર મોકલવામાં આવશે.</p>

            {{-- Order Details --}}
            <div class="bg-amber-900/20 border border-amber-800/30 rounded-xl p-5 text-left mt-6 text-sm space-y-0">
                <p class="text-xs text-amber-500 uppercase tracking-wider font-semibold mb-3">ઓર્ડર વિગતો</p>

                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">ઓર્ડર નંબર</span>
                    <span class="font-semibold text-amber-100/80">{{ $order->order_number }}</span>
                </div>
                <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                    <span class="text-amber-100/40">તારીખ</span>
                    <span class="font-medium text-amber-100/70">{{ $order->created_at->format('d M Y, h:i A') }}</span>
                </div>

                {{-- Items Summary --}}
                @if($order->items && $order->items->isNotEmpty())
                    <div class="py-2.5 border-b border-amber-900/20">
                        <span class="text-amber-100/40 block mb-2">ઉત્પાદનો</span>
                        @foreach($order->items as $item)
                            <div class="flex justify-between py-1">
                                <span class="text-amber-100/70">{{ $item->product_name }} x {{ $item->quantity }}</span>
                                <span class="text-amber-100/60">₹{{ number_format((float) $item->subtotal, 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if((float) $order->shipping_charge > 0)
                    <div class="flex justify-between py-2.5 border-b border-amber-900/20">
                        <span class="text-amber-100/40">શિપિંગ ચાર્જ</span>
                        <span class="font-medium text-amber-100/70">₹{{ number_format((float) $order->shipping_charge, 2) }}</span>
                    </div>
                @endif

                <div class="flex justify-between py-2.5">
                    <span class="text-amber-100/40">કુલ રકમ</span>
                    <span class="font-bold text-gold text-base">₹{{ number_format((float) $order->total_amount, 2) }}</span>
                </div>
            </div>

            {{-- Invoice Download --}}
            @if($order->invoice_path)
                <div class="mt-4">
                    <a href="{{ route('store.order.invoice', $order) }}"
                       class="inline-flex items-center gap-2 px-5 py-2.5 text-sm bg-amber-900/20 border border-amber-800/30 rounded-lg text-amber-400 hover:text-gold hover:border-amber-600 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        ઇનવૉઇસ ડાઉનલોડ કરો
                    </a>
                </div>
            @endif

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('store.index') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">ખરીદી ચાલુ રાખો</a>
                <a href="{{ route('dashboard.index') }}" class="inline-flex items-center px-6 py-2.5 btn-temple">ડેશબોર્ડ</a>
            </div>
        @else
            <h1 class="text-2xl font-bold text-emerald-300 mb-2">પેમેન્ટ સફળ!</h1>
            <p class="text-amber-100/50 mb-4">તમારું પેમેન્ટ સફળતાપૂર્વક પ્રાપ્ત થયું છે. ઇનવૉઇસ ઇમેઇલ/WhatsApp પર મોકલવામાં આવશે.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('store.index') }}" class="inline-flex items-center px-6 py-2.5 btn-divine">ખરીદી ચાલુ રાખો</a>
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
