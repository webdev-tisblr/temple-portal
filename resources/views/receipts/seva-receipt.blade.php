<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @include('pdf._fonts')
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Gujarati', 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; padding: 30px; }
        .container { border: 2px solid #881337; padding: 25px 30px; position: relative; }

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

        /* Amount box */
        .amount-box { border: 1px solid #C87533; margin-bottom: 14px; } .amount-box td { background: #FDF6EE; padding: 10px; text-align: center; }
        .amount-words { font-size: 10px; color: #666; font-style: italic; }
        .amount-total { font-size: 16px; font-weight: bold; color: #881337; margin-bottom: 4px; }

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
            <div class="trust-reg" style="font-size: 8px; color: #888; margin-top: 3px;">Trust Reg. No: A/1497 Dated 26-04-1994 &nbsp;|&nbsp; PAN: AAKTS1478C</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">Seva Booking Receipt</span>
            </div>
        </div>

        {{-- Receipt Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">Receipt No.</span>
                    <span class="meta-value">{{ $receipt_number }}</span>
                </td>
                <td>
                    <span class="meta-label">Seva Date</span>
                    <span class="meta-value">{{ $booking->booking_date->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="meta-label">Status</span>
                    <span class="meta-value">{{ ucfirst($booking->status->value) }}</span>
                </td>
            </tr>
        </table>

        {{-- Seva Details --}}
        <div class="section">
            <div class="section-title">Seva Details</div>
            <table class="data-table">
                <tr>
                    <td class="label">Seva</td>
                    <td class="value">
                        {{ $booking->seva?->name_gu ?? $booking->seva?->name_en ?? '-' }}
                        @if($booking->seva?->name_gu && $booking->seva?->name_en)
                            ({{ $booking->seva->name_en }})
                        @endif
                    </td>
                </tr>
                @if($booking->slot_time_label)
                <tr>
                    <td class="label">Slot</td>
                    <td class="value">{{ $booking->slot_time_label }}</td>
                </tr>
                @endif
                <tr>
                    <td class="label">Quantity</td>
                    <td class="value">{{ $booking->quantity }}</td>
                </tr>
                @if($booking->selectedProduct)
                <tr>
                    <td class="label">Selected Item</td>
                    <td class="value">
                        {{ $booking->selectedProduct->name_gu ?? $booking->selectedProduct->name }}
                        @if($booking->selected_variant_label)
                            — {{ $booking->selected_variant_label }}
                        @endif
                    </td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Devotee Details --}}
        <div class="section">
            <div class="section-title">Devotee Details</div>
            <table class="data-table">
                <tr>
                    <td class="label">Name</td>
                    <td class="value">{{ $booking->devotee?->name ?: 'Devotee' }}</td>
                </tr>
                @if($booking->devotee?->phone)
                <tr>
                    <td class="label">Phone</td>
                    <td class="value">{{ $booking->devotee->phone }}</td>
                </tr>
                @endif
                @if($booking->devotee_name_for_seva)
                <tr>
                    <td class="label">Seva in the name of</td>
                    <td class="value">{{ $booking->devotee_name_for_seva }}</td>
                </tr>
                @endif
                @if($booking->sankalp)
                <tr>
                    <td class="label">Sankalp</td>
                    <td class="value">{{ $booking->sankalp }}</td>
                </tr>
                @endif
                <tr>
                    <td class="label">Payment Mode</td>
                    <td class="value">{{ $booking->payment?->method ?? 'Online' }}</td>
                </tr>
            </table>
        </div>

        {{-- Amount --}}
        <table class="amount-box" width="100%" cellpadding="0" cellspacing="0"><tr><td>
            <div class="amount-total">&#8377; {{ number_format((float) $booking->total_amount, 2) }}</div>
            <div class="amount-words">{{ $amount_in_words }}</div>
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
                        <div class="signature-label">Authorised Signatory</div>
                    </td>
                </tr>
            </table>
            <div class="computer-gen">This is a computer-generated receipt and does not require a physical signature.</div>
        </div>
    </div>
</body>
</html>
