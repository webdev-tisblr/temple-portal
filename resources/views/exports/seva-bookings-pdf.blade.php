{{-- Seva bookings export — the admin list "as shown", with the filters that
     were in force printed in the header so a forwarded copy cannot be
     mistaken for the full list. Mirrors exports/bookings-pdf so the two
     read as one family. Rendered through GujaratiPdf (mPDF). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @include('pdf._fonts')
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Gujarati', 'DejaVu Sans', sans-serif; font-size: 9px; color: #333; padding: 14px; }

        .header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #881337; padding-bottom: 8px; }
        .header h1 { font-size: 15px; color: #881337; margin-bottom: 3px; }
        .header h2 { font-size: 12px; margin-top: 5px; color: #333; }
        .header p { font-size: 8px; color: #666; }
        .regline { font-size: 7.5px; color: #888; margin-bottom: 4px; }
        .filters { margin-top: 5px; font-size: 8px; color: #555; }
        .filters .chip { display: inline-block; background: #FFF7ED; border: 1px solid #F0E0CC; border-radius: 3px; padding: 1px 5px; margin: 1px 2px; }

        .summary { margin-bottom: 8px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 5px 8px; background: #FFF7ED; border: 1px solid #F0E0CC; font-size: 9px; text-align: center; }
        .summary .label { color: #777; font-size: 7.5px; text-transform: uppercase; display: block; margin-bottom: 2px; }
        .summary .value { font-weight: bold; color: #881337; font-size: 11px; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #881337; color: #fff; padding: 5px 5px; text-align: left; font-size: 7.5px; text-transform: uppercase; }
        table.rows td { padding: 4px 5px; border-bottom: 1px solid #eee; font-size: 8.5px; vertical-align: top; }
        table.rows tr:nth-child(even) td { background: #fdf6ee; }
        .num { text-align: right; }
        .muted { color: #888; font-size: 7.5px; }
        .total-row td { border-top: 2px solid #881337; font-weight: bold; font-size: 9px; background: #FFF7ED; }

        .empty { padding: 24px; text-align: center; color: #999; font-size: 11px; }
        .footer { margin-top: 12px; text-align: center; font-size: 7.5px; color: #999; border-top: 1px solid #ddd; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $trustName }}</h1>
        <p class="regline">Trust Reg. No: A/1497 Dated 26-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</p>
        <h2>Seva Bookings</h2>
        <div class="filters">
            @if (empty($filters))
                <span class="chip">All bookings — no filters applied</span>
            @else
                @foreach ($filters as $label)
                    <span class="chip">{{ $label }}</span>
                @endforeach
            @endif
        </div>
    </div>

    <div class="summary">
        <table>
            <tr>
                <td><span class="label">Bookings</span><span class="value">{{ number_format($bookings->count()) }}</span></td>
                <td><span class="label">Total amount</span><span class="value">{{ inr_money($total) }}</span></td>
                <td><span class="label">Exported</span><span class="value">{{ \Illuminate\Support\Carbon::now()->format('d M Y, h:i A') }}</span></td>
            </tr>
        </table>
    </div>

    @if ($bookings->isEmpty())
        <div class="empty">No bookings match the current filters.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th style="width:8%">Date</th>
                    <th style="width:8%">Slot</th>
                    <th style="width:17%">Seva</th>
                    <th style="width:14%">Devotee</th>
                    <th style="width:9%">Phone</th>
                    <th style="width:13%">Name for Seva</th>
                    <th style="width:11%">Product</th>
                    <th style="width:3%" class="num">Qty</th>
                    <th style="width:7%" class="num">Amount</th>
                    <th style="width:10%">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($bookings as $b)
                    @php
                        $r = \App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings::row($b);
                    @endphp
                    <tr>
                        <td>{{ $r[0] }}</td>
                        <td>{{ $r[1] }}</td>
                        <td>{{ $r[2] }}@if ($r[14] !== '-')<br><span class="muted">{{ $r[14] }}</span>@endif</td>
                        <td>{{ $r[3] }}</td>
                        <td>{{ $r[4] }}</td>
                        <td>{{ $r[5] }}</td>
                        <td>{{ $r[6] }}@if ($r[7] !== '-')<br><span class="muted">{{ $r[7] }}</span>@endif</td>
                        <td class="num">{{ $r[8] }}</td>
                        <td class="num">{{ inr_money($b->total_amount) }}</td>
                        <td>{{ $r[10] }}<br><span class="muted">Pay: {{ $r[11] }}@if ($r[15] === 'Yes') · 80G @endif</span></td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="8">Total &mdash; {{ $bookings->count() }} booking(s)</td>
                    <td class="num">{{ inr_money($total) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="footer">
        {{ $trustName }}
    </div>
</body>
</html>
