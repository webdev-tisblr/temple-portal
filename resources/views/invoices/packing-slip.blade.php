<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #333; padding: 10px; }
        .slip { border: 2px solid #333; padding: 10px; height: 100%; }
        .header { text-align: center; border-bottom: 2px dashed #333; padding-bottom: 8px; margin-bottom: 8px; }
        .header h1 { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .header p { font-size: 8px; color: #666; }
        .section { margin-bottom: 8px; }
        .section-title { font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #888; border-bottom: 1px solid #ddd; padding-bottom: 2px; margin-bottom: 4px; }
        .from-to { display: table; width: 100%; }
        .from-to .col { display: table-cell; width: 50%; vertical-align: top; padding: 4px; }
        .from-to .col:first-child { border-right: 1px dashed #ccc; }
        .label { font-size: 7px; color: #999; text-transform: uppercase; }
        .value { font-size: 9px; font-weight: bold; margin-top: 1px; }
        .value-sm { font-size: 8px; margin-top: 1px; }
        .items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        .items th { font-size: 7px; text-transform: uppercase; color: #888; border-bottom: 1px solid #ddd; padding: 3px 4px; text-align: left; }
        .items td { font-size: 8px; padding: 3px 4px; border-bottom: 1px dotted #eee; }
        .items td:last-child { text-align: right; }
        .order-bar { background: #333; color: #fff; text-align: center; padding: 5px; font-size: 11px; font-weight: bold; letter-spacing: 1px; margin-bottom: 8px; }
        .footer { text-align: center; font-size: 7px; color: #999; border-top: 1px dashed #ccc; padding-top: 4px; margin-top: 6px; }
        .total-row td { border-top: 1px solid #333; font-weight: bold; font-size: 9px; }
    </style>
</head>
<body>
    <div class="slip">
        {{-- Order Number Bar --}}
        <div class="order-bar">{{ $order->order_number }}</div>

        {{-- Header --}}
        <div class="header">
            <h1>Packing Slip</h1>
            <p>{{ $order->created_at->format('d M Y') }}</p>
        </div>

        {{-- From / To --}}
        <div class="from-to">
            <div class="col">
                <div class="label">From</div>
                <div class="value">{{ $trustName }}</div>
                <div class="value-sm">{{ $trustAddress }}</div>
                @if($trustPhone)
                    <div class="value-sm">Ph: {{ $trustPhone }}</div>
                @endif
            </div>
            <div class="col" style="padding-left: 8px;">
                <div class="label">Ship To</div>
                <div class="value">{{ $order->shipping_name }}</div>
                <div class="value-sm">{{ $order->shipping_address }}</div>
                <div class="value-sm">{{ $order->shipping_city }}, {{ $order->shipping_state }} - {{ $order->shipping_pincode }}</div>
                <div class="value-sm">Ph: {{ $order->shipping_phone }}</div>
            </div>
        </div>

        {{-- Items --}}
        <div class="section" style="margin-top: 8px;">
            <div class="section-title">Items ({{ $order->items->sum('quantity') }} pcs)</div>
            <table class="items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th style="text-align:right;">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $item)
                    <tr>
                        <td>{{ $item->product_name }}</td>
                        <td>{{ $item->quantity }}</td>
                    </tr>
                    @endforeach
                    <tr class="total-row">
                        <td>Total</td>
                        <td>{{ $order->items->sum('quantity') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Amount --}}
        <div style="text-align:center; margin-top: 6px; padding: 4px; background: #f5f0ea; border: 1px solid #ddd;">
            <span style="font-size: 7px; color: #888;">COD / PREPAID</span><br>
            <span style="font-size: 13px; font-weight: bold;">&#8377; {{ number_format((float) $order->total_amount, 2) }}</span>
            <span style="font-size: 8px; color: #888; display: block;">{{ $order->payment && $order->payment->status->value === 'captured' ? 'PREPAID' : 'COD' }}</span>
        </div>

        {{-- Footer --}}
        <div class="footer">
            {{ $trustName }} | Temple Store
        </div>
    </div>
</body>
</html>
