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

        /* Booking details table */
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .details-table thead th { background: #881337; color: #fff; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; text-align: left; }
        .details-table thead th.right { text-align: right; }
        .details-table tbody td { padding: 7px 10px; border-bottom: 1px solid #E5D3B3; font-size: 11px; color: #333; }
        .details-table tbody td.right { text-align: right; }

        /* Amount box */
        .amount-box { background: #FDF6EE; border: 1px solid #C87533; padding: 10px; text-align: center; margin-bottom: 14px; }
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
        <div class="watermark">HALL BOOKING</div>

        {{-- Header with Logo --}}
        <div class="header">
            <div class="logo">
                <img src="{{ public_path('images/shree-pataliya-hanumanji-logo.png') }}" alt="Logo">
            </div>
            <div class="trust-name">{{ $trust_name }}</div>
            <div class="trust-address">{{ $trust_address }}</div>
            <div class="trust-reg" style="font-size: 8px; color: #888; margin-top: 3px;">Trust Reg. No: A/1497 Dated 28-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">Hall Booking Receipt</span>
            </div>
        </div>

        {{-- Booking Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">Booking Date</span>
                    <span class="meta-value">{{ $booking->booking_date->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="meta-label">Booking Type</span>
                    <span class="meta-value">
                        @switch($booking->booking_type)
                            @case('full_day') Full Day @break
                            @case('half_day_morning') Half Day (AM) @break
                            @case('half_day_evening') Half Day (PM) @break
                            @default {{ ucfirst(str_replace('_', ' ', $booking->booking_type)) }}
                        @endswitch
                    </span>
                </td>
                <td>
                    <span class="meta-label">Status</span>
                    <span class="meta-value">{{ ucfirst($booking->status) }}</span>
                </td>
            </tr>
        </table>

        {{-- Hall Details --}}
        <div class="section">
            <div class="section-title">Hall Details</div>
            <table class="data-table">
                <tr>
                    <td class="label">Hall Name</td>
                    <td class="value">{{ $booking->hall->name ?? '-' }}</td>
                </tr>
                <tr>
                    <td class="label">Capacity</td>
                    <td class="value">{{ $booking->hall->capacity ?? '-' }} persons</td>
                </tr>
            </table>
        </div>

        {{-- Booking Details --}}
        <div class="section">
            <div class="section-title">Booking Details</div>
            <table class="data-table">
                <tr>
                    <td class="label">Contact Name</td>
                    <td class="value">{{ $booking->contact_name }}</td>
                </tr>
                <tr>
                    <td class="label">Phone</td>
                    <td class="value">{{ $booking->contact_phone }}</td>
                </tr>
                @if($booking->contact_email)
                <tr>
                    <td class="label">Email</td>
                    <td class="value">{{ $booking->contact_email }}</td>
                </tr>
                @endif
                @if($booking->aadhaar_number)
                <tr>
                    <td class="label">Aadhaar Number</td>
                    <td class="value">{{ $booking->aadhaar_number }}</td>
                </tr>
                @endif
                @if($booking->contact_address)
                <tr>
                    <td class="label">Address</td>
                    <td class="value">{{ $booking->contact_address }}</td>
                </tr>
                @endif
                <tr>
                    <td class="label">Purpose</td>
                    <td class="value">{{ $booking->purpose }}</td>
                </tr>
                @if($booking->expected_guests)
                <tr>
                    <td class="label">Expected Guests</td>
                    <td class="value">{{ $booking->expected_guests }}</td>
                </tr>
                @endif
            </table>
        </div>

        {{-- Amount --}}
        <div class="amount-box">
            <div class="amount-total">&#8377; {{ number_format((float) $booking->total_amount, 2) }}</div>
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
            <div class="computer-gen">This is a computer-generated receipt and does not require a physical signature.</div>
        </div>
    </div>
</body>
</html>
