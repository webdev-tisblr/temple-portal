@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('dashboard.index') }}" class="hover:text-gold transition">ડેશબોર્ડ</a>
        <span class="mx-2">/</span>
        <span class="text-gold">મારા ઓર્ડર</span>
    </nav>

    <h1 class="divine-heading text-2xl mb-6">મારા ઓર્ડર</h1>

    @if($orders->isNotEmpty())
        <div class="space-y-4">
            @foreach($orders as $order)
                <div class="card-sacred p-5">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <p class="text-gold font-bold">{{ $order->order_number }}</p>
                            <p class="text-xs text-amber-100/40 mt-0.5">{{ $order->created_at->format('d M Y, h:i A') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="px-2.5 py-1 rounded-full text-xs font-semibold
                                @switch($order->status->value)
                                    @case('pending') bg-amber-900/30 text-amber-400 @break
                                    @case('confirmed') bg-blue-900/30 text-blue-400 @break
                                    @case('processing') bg-indigo-900/30 text-indigo-400 @break
                                    @case('shipped') bg-emerald-900/30 text-emerald-400 @break
                                    @case('delivered') bg-green-900/30 text-green-400 @break
                                    @case('cancelled') bg-red-900/30 text-red-400 @break
                                    @case('refunded') bg-gray-900/30 text-gray-400 @break
                                    @default bg-amber-900/30 text-amber-400
                                @endswitch
                            ">{{ ucfirst($order->status->value) }}</span>
                            <span class="text-lg font-bold text-gold">₹{{ number_format((float) $order->total_amount, 2) }}</span>
                        </div>
                    </div>

                    <div class="mt-3 pt-3 border-t border-amber-900/15">
                        <div class="flex flex-wrap gap-2 text-xs text-amber-100/50">
                            @foreach($order->items as $item)
                                <span class="bg-amber-900/20 px-2 py-1 rounded">{{ $item->product_name }} x{{ $item->quantity }}</span>
                            @endforeach
                        </div>
                    </div>

                    @if($order->invoice_path)
                        <div class="mt-3 pt-3 border-t border-amber-900/15">
                            <a href="{{ route('store.order.invoice', $order) }}" class="text-amber-500 hover:text-gold text-sm font-semibold flex items-center gap-1 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                Invoice Download
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    @else
        <div class="text-center py-20 card-sacred">
            <svg class="w-12 h-12 text-amber-800/40 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
            <p class="text-amber-100/30">હજુ સુધી કોઈ ઓર્ડર નથી.</p>
            <a href="{{ route('store.index') }}" class="mt-4 inline-flex items-center px-6 py-2.5 btn-divine">સ્ટોર જુઓ</a>
        </div>
    @endif
</div>
@endsection
