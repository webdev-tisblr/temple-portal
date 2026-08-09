@extends('layouts.app')

@section('content')

{{-- Was the odd one out: a hand-rolled breadcrumb + bare <h1>. Now the same
     chrome as every other dashboard page. --}}
<x-page-header
    :breadcrumb="[
        ['label' => __('nav.dashboard'), 'url' => route('dashboard.index')],
        ['label' => __('dashboard.my_orders')],
    ]"
    title="{{ __('dashboard.my_orders') }}"
    subtitle="{{ __('dashboard.my_orders_sub') }}" />

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 bg-temple">

    <x-dashboard.nav active="orders" />

    <x-dashboard.panel
        :title="__('dashboard.my_orders')"
        icon="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z">

        @if($orders->isNotEmpty())
            <table class="dash-table">
                <thead class="dash-thead">
                    <tr>
                        <th class="dash-th">{{ __('dashboard.col_order_no') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_items') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_total') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_status') }}</th>
                        <th class="dash-th">{{ __('dashboard.col_action') }}</th>
                    </tr>
                </thead>
                <tbody class="dash-tbody">
                    @foreach($orders as $order)
                        @php
                            $orderStatus = $order->status instanceof \BackedEnum
                                ? (string) $order->status->value
                                : (string) $order->status;
                        @endphp
                        <tr class="dash-tr">
                            <x-dashboard.cell :label="__('dashboard.col_order_no')">
                                <span class="font-mono text-sm font-semibold" style="color: #2A1810;">{{ $order->order_number }}</span>
                                <span class="block text-xs" style="color: #8A7860;">{{ $order->created_at->format('d/m/Y, h:i A') }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_items')">
                                <span class="flex flex-wrap justify-end gap-1.5 md:justify-start">
                                    @foreach($order->items as $item)
                                        <span class="dash-chip dash-chip-info">{{ $item->product_name }} &times;{{ $item->quantity }}</span>
                                    @endforeach
                                </span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_total')">
                                <span class="font-semibold" style="color: #C45F12;">₹{{ number_format((float) $order->total_amount, 2) }}</span>
                            </x-dashboard.cell>

                            <x-dashboard.cell :label="__('dashboard.col_status')">
                                <x-dashboard.status-chip :status="$orderStatus" />
                            </x-dashboard.cell>

                            {{-- Gate on STATUS, not invoice_path: the retention sweep
                                 NULLs invoice_path (file is regenerated on demand), so
                                 a path-based guard made the button vanish for older
                                 orders even though the download endpoint self-heals. --}}
                            <x-dashboard.cell :label="__('dashboard.col_action')">
                                @if(in_array($orderStatus, ['confirmed', 'processing', 'shipped', 'delivered'], true))
                                    <a href="{{ route('store.order.invoice', $order) }}" class="dash-link">
                                        <x-dashboard.icon path="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" class="w-3.5 h-3.5" />
                                        {{ __('dashboard.invoice') }}
                                    </a>
                                @else
                                    <span style="color: #8A7860;">&mdash;</span>
                                @endif
                            </x-dashboard.cell>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($orders->hasPages())
                <div class="dash-panel-foot">{{ $orders->links() }}</div>
            @endif
        @else
            <x-dashboard.empty
                icon="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"
                :message="__('dashboard.no_orders')"
                :hint="__('dashboard.no_orders_hint')"
                :ctaHref="route('store.index')"
                :ctaLabel="__('dashboard.view_store')" />
        @endif
    </x-dashboard.panel>

</div>

@endsection
