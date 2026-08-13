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

        /* Booking details table */
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .details-table thead th { background: #881337; color: #fff; font-size: 10px; font-weight: bold; text-transform: uppercase; padding: 8px 10px; text-align: left; }
        .details-table thead th.right { text-align: right; }
        .details-table tbody td { padding: 7px 10px; border-bottom: 1px solid #E5D3B3; font-size: 11px; color: #333; }
        .details-table tbody td.right { text-align: right; }

        /* Amount box */
        .amount-box { border: 1px solid #C87533; margin-bottom: 14px; } .amount-box td { background: #FDF6EE; padding: 10px; text-align: center; }
        /* NOT italic: mPDF resolves Devanagari to FreeSerif, and
           FreeSerifItalic carries no Devanagari glyphs — a Hindi label here
           rendered as tofu boxes (caught 2026-08-09). Only the English
           amount-in-words value keeps the italic. */
        .totals-table { border-collapse: collapse; margin-bottom: 8px; }
        .totals-table td { padding: 5px 10px; font-size: 11px; border-bottom: 1px solid #EFE2CC; }
        .totals-table .t-label { color: #666; text-align: right; }
        .totals-table .t-value { color: #333; text-align: right; width: 130px; font-weight: bold; }
        .amount-words { font-size: 10px; color: #666; }
        .amount-words .words-value { font-style: italic; }
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
            <div class="trust-reg" style="font-size: 8px; color: #888; margin-top: 3px;">{{ __('receipt.label_trust_reg') }}: {{ $trust_reg_no }} &nbsp;|&nbsp; {{ __('receipt.label_80g_reg') }}: {{ $trust_80g_reg_no }} &nbsp;|&nbsp; {{ __('receipt.label_trust_pan') }}: {{ $trust_pan }}</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">{{ __('receipt.title_hall') }}</span>
            </div>
        </div>

        {{-- Booking Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">{{ __('receipt.booking_date') }}</span>
                    {{-- Range-aware (item 4.2): identical output for a
                         single-day booking, "12/08/2026 – 14/08/2026" for a
                         multi-day one. --}}
                    <span class="meta-value">{{ $booking->booking_date->format('d/m/Y') }}@if($booking->is_multi_day) – {{ $booking->rangeEnd()->format('d/m/Y') }} ({{ (int) ($booking->days_count ?: 1) }}){{ '' }}@endif</span>
                </td>
                <td>
                    <span class="meta-label">{{ __('receipt.booking_type') }}</span>
                    <span class="meta-value">{{ $booking_type_label }}</span>
                </td>
                <td>
                    <span class="meta-label">{{ __('receipt.status') }}</span>
                    <span class="meta-value">{{ $status_label }}</span>
                </td>
            </tr>
        </table>

        {{-- Hall Details --}}
        <div class="section">
            <div class="section-title">{{ __('receipt.section_hall') }}</div>
            <table class="data-table">
                <tr>
                    <td class="label">{{ __('receipt.label_hall_name') }}</td>
                    {{-- Hall::getNameAttribute() resolves name_{locale}; the service
                         has already switched the app locale to the hirer's language. --}}
                    <td class="value">{{ $booking->hall->name ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label">{{ __('receipt.label_capacity') }}</td>
                    <td class="value">{{ $booking->hall->capacity ?? '-' }} {{ __('receipt.persons') }}</td>
                </tr>
            </table>
        </div>

        {{-- Booking Details --}}
        <div class="section">
            <div class="section-title">{{ __('receipt.section_booking') }}</div>
            <table class="data-table">
                <tr>
                    <td class="label">{{ __('receipt.label_contact_name') }}</td>
                    <td class="value">{{ $booking->contact_name }}</td>
                </tr>
                <tr>
                    <td class="label">{{ __('receipt.label_phone') }}</td>
                    <td class="value">{{ $booking->contact_phone }}</td>
                </tr>
                <tr>
                    <td class="label">{{ __('receipt.label_purpose') }}</td>
                    <td class="value">{{ $booking->purpose }}</td>
                </tr>
                @if($booking->expected_guests)
                <tr>
                    <td class="label">{{ __('receipt.label_expected_guests') }}</td>
                    <td class="value">{{ $booking->expected_guests }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Amount.

             When the booking carries a GST snapshot (gst_rate not null) the
             total is broken out into Taxable Value → CGST → SGST → Total, as
             a tax invoice must be. Both halves are the trust's own supply
             within Gujarat, so the rate splits evenly into CGST + SGST; an
             inter-state IGST case does not arise for a physical hall.

             CGST is derived and SGST is the REMAINDER, never a second
             independent round — otherwise an odd-paise rate (e.g. 5% of
             ₹1,001.50) makes the two halves sum to a rupee either side of
             gst_amount and the invoice fails to add up. --}}
        @php
            $gstRate = $booking->gst_rate !== null ? (float) $booking->gst_rate : null;
            $gstAmount = (float) ($booking->gst_amount ?? 0);
            $subtotal = (float) ($booking->subtotal_amount ?? $booking->total_amount);
            $cgst = round($gstAmount / 2, 2);
            $sgst = round($gstAmount - $cgst, 2);
            $halfRate = $gstRate !== null ? rtrim(rtrim(inr($gstRate / 2, 2), '0'), '.') : null;
        @endphp

        @if($gstRate !== null && $gstAmount > 0)
            <table class="totals-table" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="t-label">{{ __('receipt.label_taxable_value') }}</td>
                    <td class="t-value">&#8377; {{ inr($subtotal, 2) }}</td>
                </tr>
                <tr>
                    <td class="t-label">{{ __('receipt.label_cgst') }} @ {{ $halfRate }}%</td>
                    <td class="t-value">&#8377; {{ inr($cgst, 2) }}</td>
                </tr>
                <tr>
                    <td class="t-label">{{ __('receipt.label_sgst') }} @ {{ $halfRate }}%</td>
                    <td class="t-value">&#8377; {{ inr($sgst, 2) }}</td>
                </tr>
            </table>
        @endif

        <table class="amount-box" width="100%" cellpadding="0" cellspacing="0"><tr><td>
            <div class="amount-total">&#8377; {{ inr((float) $booking->total_amount, 2) }}</div>
            {{-- Words stay English in every language — see the services. --}}
            <div class="amount-words">{{ __('receipt.label_amount_in_words') }}: <span class="words-value">{{ $amount_in_words }}</span></div>
            @if($gstRate !== null && $gstAmount > 0 && ($trust_gstin ?? '') !== '')
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
            <div class="computer-gen">{{ __('receipt.computer_generated_receipt') }}</div>
        </div>
    </div>
</body>
</html>
