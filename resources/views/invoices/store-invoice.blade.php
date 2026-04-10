<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; padding: 30px; }
        .container { border: 2px solid #881337; padding: 25px 30px; position: relative; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.04; font-size: 60px; font-weight: bold; color: #881337; white-space: nowrap; pointer-events: none; }

        /* Header */
        .header { text-align: center; border-bottom: 2px solid #C87533; padding-bottom: 15px; margin-bottom: 18px; }
        .logo { width: 70px; height: 70px; margin: 0 auto 8px; }
        .logo img { width: 70px; height: 70px; border-radius: 50%; }
        .trust-name { font-size: 18px; font-weight: bold; color: #881337; margin-bottom: 3px; }
        .trust-address { font-size: 9px; color: #666; margin-bottom: 2px; }
        .receipt-title { font-size: 13px; font-weight: bold; color: #C87533; margin-top: 10px; text-transform: uppercase; letter-spacing: 2px; border: 1px solid #C87533; display: inline-block; padding: 4px 18px; }

        /* Sections */
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11px; font-weight: bold; color: #881337; border-bottom: 1px solid #E5D3B3; padding-bottom: 4px; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }

        /* Two-column layout */
        .two-col { width: 100%; }
        .two-col td { vertical-align: top; width: 50%; }
        .two-col td:first-child { padding-right: 15px; }
        .two-col td:last-child { padding-left: 15px; }

        /* Data table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table td { padding: 4px 0; border: none; font-size: 11px; }
        .data-table .label { font-weight: bold; color: #555; width: 42%; }
        .data-table .value { color: #222; }

        /* Receipt meta bar */
        .meta-bar { width: 100%; border-collapse: collapse; background: #FDF6EE; border: 1px solid #E5D3B3; margin-bottom: 16px; }
        .meta-bar td { padding: 8px 12px; text-align: center; border-right: 1px solid #E5D3B3; }
        .meta-bar td:last-child { border-right: none; }
        .meta-bar .meta-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; display: block; }
        .meta-bar .meta-value { font-size: 12px; font-weight: bold; color: #881337; display: block; margin-top: 2px; }

        /* Items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .items-table thead th { background: #881337; color: #fff; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; text-align: left; }
        .items-table thead th.right { text-align: right; }
        .items-table thead th.center { text-align: center; }
        .items-table tbody td { padding: 7px 10px; border-bottom: 1px solid #E5D3B3; font-size: 11px; color: #333; }
        .items-table tbody td.right { text-align: right; }
        .items-table tbody td.center { text-align: center; }
        .items-table tbody tr:last-child td { border-bottom: 2px solid #C87533; }

        /* Totals table */
        .totals-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .totals-table td { padding: 5px 10px; font-size: 11px; }
        .totals-table .totals-label { text-align: right; color: #555; font-weight: bold; width: 80%; }
        .totals-table .totals-value { text-align: right; color: #222; width: 20%; }
        .totals-table .grand-total td { border-top: 2px solid #881337; font-size: 13px; font-weight: bold; color: #881337; padding-top: 8px; }

        /* Amount box */
        .amount-box { background: #FDF6EE; border: 1px solid #C87533; padding: 10px; text-align: center; margin-bottom: 14px; }
        .amount-words { font-size: 10px; color: #666; font-style: italic; }

        /* Footer */
        .footer { margin-top: 16px; border-top: 1px solid #ddd; padding-top: 10px; }
        .footer-row { width: 100%; }
        .footer-row td { width: 50%; vertical-align: bottom; }
        .signature-block { text-align: right; }
        .signature-line { border-top: 1px solid #999; width: 180px; margin-left: auto; margin-bottom: 3px; }
        .signature-label { font-size: 9px; color: #888; }
        .computer-gen { font-size: 8px; color: #aaa; text-align: center; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="watermark">TAX INVOICE</div>

        {{-- Header with Logo --}}
        <div class="header">
            <div class="logo">
                <img src="{{ public_path('images/shree-pataliya-hanumanji-logo.png') }}" alt="Logo">
            </div>
            <div class="trust-name">{{ $trust_name }}</div>
            <div class="trust-address">{{ $trust_address }}</div>
            <div class="trust-reg">Trust Reg. No: A/1497 Dated 28-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">Tax Invoice</span>
            </div>
        </div>

        {{-- Invoice Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">Order Number</span>
                    <span class="meta-value">{{ $order->order_number }}</span>
                </td>
                <td>
                    <span class="meta-label">Date</span>
                    <span class="meta-value">{{ $order->created_at->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="meta-label">Payment Mode</span>
                    <span class="meta-value">{{ ucfirst($order->payment->method ?? 'Online') }}</span>
                </td>
            </tr>
        </table>

        {{-- Customer Details --}}
        <div class="section">
            <div class="section-title">Customer Details</div>
            <table class="data-table">
                <tr>
                    <td class="label">Name</td>
                    <td class="value">{{ $order->shipping_name }}</td>
                </tr>
                @if($order->shipping_phone)
                <tr>
                    <td class="label">Phone</td>
                    <td class="value">{{ $order->shipping_phone }}</td>
                </tr>
                @endif
                @php
                    $address = collect([
                        $order->shipping_address,
                        $order->shipping_city,
                        $order->shipping_state,
                        $order->shipping_pincode,
                    ])->filter()->implode(', ');
                @endphp
                @if($address)
                <tr>
                    <td class="label">Address</td>
                    <td class="value">{{ $address }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Items Table --}}
        <div class="section">
            <div class="section-title">Order Items</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">S.No.</th>
                        <th style="width: 44%;">Product</th>
                        <th class="center" style="width: 12%;">Qty</th>
                        <th class="right" style="width: 18%;">Unit Price</th>
                        <th class="right" style="width: 18%;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->product_name }}</td>
                        <td class="center">{{ $item->quantity }}</td>
                        <td class="right">&#8377; {{ number_format((float) $item->unit_price, 2) }}</td>
                        <td class="right">&#8377; {{ number_format((float) $item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Totals --}}
        <table class="totals-table">
            <tr>
                <td class="totals-label">Subtotal</td>
                <td class="totals-value">&#8377; {{ number_format((float) $order->subtotal, 2) }}</td>
            </tr>
            @if((float) $order->shipping_charge > 0)
            <tr>
                <td class="totals-label">Shipping</td>
                <td class="totals-value">&#8377; {{ number_format((float) $order->shipping_charge, 2) }}</td>
            </tr>
            @endif
            <tr class="grand-total">
                <td class="totals-label">Grand Total</td>
                <td class="totals-value">&#8377; {{ number_format((float) $order->total_amount, 2) }}</td>
            </tr>
        </table>

        {{-- Amount in Words --}}
        <div class="amount-box">
            <div class="amount-words">{{ $amount_in_words }}</div>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <table class="footer-row">
                <tr>
                    <td>
                        <div style="font-size: 9px; color: #888;">
                            {{ $trust_name }}<br>
                            {{ $trust_address }}
                        </div>
                    </td>
                    <td class="signature-block">
                        <div class="signature-line"></div>
                        <div class="signature-label">Authorised Signatory</div>
                    </td>
                </tr>
            </table>
            <div class="computer-gen">This is a computer-generated invoice and does not require a physical signature.</div>
        </div>
    </div>
</body>
</html>
