<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @include('pdf._fonts')
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Gujarati', 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; padding: 30px; }
        .container { border: 2px solid #881337; padding: 25px 30px; position: relative; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); opacity: 0.04; font-size: 60px; font-weight: bold; color: #881337; white-space: nowrap; pointer-events: none; }

        /* Header */
        .header { text-align: center; border-bottom: 2px solid #C87533; padding-bottom: 15px; margin-bottom: 18px; }
        .logo { width: 70px; height: 70px; margin: 0 auto 8px; }
        .logo img { width: 70px; height: 70px; border-radius: 50%; }
        .trust-name { font-size: 18px; font-weight: bold; color: #881337; margin-bottom: 3px; }
        .trust-address { font-size: 9px; color: #666; margin-bottom: 2px; }
        .receipt-title { font-size: 13px; font-weight: bold; color: #C87533; margin-top: 10px; text-transform: uppercase; border: 1px solid #C87533; display: inline-block; padding: 4px 18px; }

        /* Sections */
        .section { margin-bottom: 14px; }
        .section-title { font-size: 11px; font-weight: bold; color: #881337; border-bottom: 1px solid #E5D3B3; padding-bottom: 4px; margin-bottom: 8px; text-transform: uppercase; }

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
        .meta-bar .meta-label { font-size: 8px; color: #888; text-transform: uppercase; display: block; }
        .meta-bar .meta-value { font-size: 12px; font-weight: bold; color: #881337; display: block; margin-top: 2px; }

        /* Items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .items-table thead th { background: #881337; color: #fff; font-size: 10px; font-weight: bold; text-transform: uppercase; padding: 8px 10px; text-align: left; }
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
        .amount-box { border: 1px solid #C87533; margin-bottom: 14px; } .amount-box td { background: #FDF6EE; padding: 10px; text-align: center; }
        /* NOT italic: mPDF resolves Devanagari to FreeSerif, and
           FreeSerifItalic carries no Devanagari glyphs — a Hindi label here
           rendered as tofu boxes (caught 2026-08-09). Only the English
           amount-in-words value keeps the italic. */
        .amount-words { font-size: 10px; color: #666; }
        .amount-words .words-value { font-style: italic; }

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
        {{-- watermark drawn natively by mPDF via GujaratiPdf watermark option --}}

        {{-- Header with Logo --}}
        <div class="header">
            <div class="logo">
                <img src="{{ public_path('images/shree-pataliya-hanumanji-logo.png') }}" alt="Logo">
            </div>
            <div class="trust-name">{{ $trust_name }}</div>
            <div class="trust-address">{{ $trust_address }}</div>
            <div class="trust-reg">{{ __('receipt.label_trust_reg') }}: {{ $trust_reg_no }} &nbsp;|&nbsp; {{ __('receipt.label_80g_reg') }}: {{ $trust_80g_reg_no }} &nbsp;|&nbsp; {{ __('receipt.label_trust_pan') }}: {{ $trust_pan }}</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">{{ __('receipt.title_store') }}</span>
            </div>
        </div>

        {{-- Invoice Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">{{ __('receipt.order_no') }}</span>
                    <span class="meta-value">{{ $order->order_number }}</span>
                </td>
                <td>
                    <span class="meta-label">{{ __('receipt.date') }}</span>
                    <span class="meta-value">{{ $order->created_at->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="meta-label">{{ __('receipt.payment_mode') }}</span>
                    <span class="meta-value">{{ $payment_mode_label }}</span>
                </td>
            </tr>
        </table>

        {{-- Customer Details --}}
        <div class="section">
            <div class="section-title">{{ __('receipt.section_customer') }}</div>
            <table class="data-table">
                <tr>
                    <td class="label">{{ __('receipt.label_name') }}</td>
                    <td class="value">{{ $order->shipping_name }}</td>
                </tr>
                @if($order->shipping_phone)
                <tr>
                    <td class="label">{{ __('receipt.label_phone') }}</td>
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
                    <td class="label">{{ __('receipt.label_address') }}</td>
                    <td class="value">{{ $address }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Items Table --}}
        <div class="section">
            <div class="section-title">{{ __('receipt.section_items') }}</div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">{{ __('receipt.col_sno') }}</th>
                        <th style="width: 44%;">{{ __('receipt.col_product') }}</th>
                        <th class="center" style="width: 12%;">{{ __('receipt.col_qty') }}</th>
                        <th class="right" style="width: 18%;">{{ __('receipt.col_unit_price') }}</th>
                        <th class="right" style="width: 18%;">{{ __('receipt.col_subtotal') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $index => $item)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->product_name }}</td>
                        <td class="center">{{ $item->quantity }}</td>
                        <td class="right">&#8377; {{ inr((float) $item->unit_price, 2) }}</td>
                        <td class="right">&#8377; {{ inr((float) $item->subtotal, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Totals --}}
        <table class="totals-table">
            @php
                // GST is INCLUSIVE, so `subtotal` is already the gross the
                // devotee paid. The taxable value is what is left after
                // carving the tax out of the TAXED lines only — an order can
                // mix taxed and untaxed products, so the untaxed part of
                // subtotal belongs to neither figure and simply never
                // appears here.
                //
                // CGST is derived and SGST is the REMAINDER, never a second
                // rounded halving, or an odd paisa goes missing against
                // gst_amount and the invoice fails to add up.
                $gstAmount = (float) ($order->gst_amount ?? 0);
                $taxable = (float) ($order->taxable_amount ?? 0);
                $cgst = round($gstAmount / 2, 2);
                $sgst = round($gstAmount - $cgst, 2);
                // Rates vary per line, so the header shows the effective one
                // rather than claiming a single configured rate.
                $effRate = $taxable > 0 ? round($gstAmount / $taxable * 100, 2) : 0;
                $halfRate = $effRate > 0 ? rtrim(rtrim(inr($effRate / 2, 2), '0'), '.') : null;
            @endphp
            @if($gstAmount > 0)
            <tr>
                <td class="totals-label">{{ __('receipt.label_taxable_value') }}</td>
                <td class="totals-value">&#8377; {{ inr($taxable, 2) }}</td>
            </tr>
            <tr>
                <td class="totals-label">{{ __('receipt.label_cgst') }} @ {{ $halfRate }}%</td>
                <td class="totals-value">&#8377; {{ inr($cgst, 2) }}</td>
            </tr>
            <tr>
                <td class="totals-label">{{ __('receipt.label_sgst') }} @ {{ $halfRate }}%</td>
                <td class="totals-value">&#8377; {{ inr($sgst, 2) }}</td>
            </tr>
            @else
            <tr>
                <td class="totals-label">{{ __('receipt.label_subtotal') }}</td>
                <td class="totals-value">&#8377; {{ inr((float) $order->subtotal, 2) }}</td>
            </tr>
            @endif
            @if((float) $order->shipping_charge > 0)
            <tr>
                <td class="totals-label">{{ __('receipt.label_shipping') }}</td>
                <td class="totals-value">&#8377; {{ inr((float) $order->shipping_charge, 2) }}</td>
            </tr>
            @endif
            <tr class="grand-total">
                <td class="totals-label">{{ __('receipt.label_grand_total') }}</td>
                <td class="totals-value">&#8377; {{ inr((float) $order->total_amount, 2) }}</td>
            </tr>
        </table>

        {{-- Amount in Words — words stay English in every language, see the services --}}
        <table class="amount-box" width="100%" cellpadding="0" cellspacing="0"><tr><td>
            <div class="amount-words">{{ __('receipt.label_amount_in_words') }}: <span class="words-value">{{ $amount_in_words }}</span></div>
            @if($gstAmount > 0 && ($trust_gstin ?? '') !== '')
                <div class="amount-words" style="margin-top: 4px;">GSTIN: <span class="words-value">{{ $trust_gstin }}</span></div>
            @endif
        </td></tr></table>

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
                        <div class="signature-label">{{ __('receipt.authorised_signatory') }}</div>
                    </td>
                </tr>
            </table>
            <div class="computer-gen">{{ __('receipt.computer_generated_invoice') }}</div>
        </div>
    </div>
</body>
</html>
