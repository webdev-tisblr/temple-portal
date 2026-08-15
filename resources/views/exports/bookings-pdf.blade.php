{{-- Bookings report. Deliberately mirrors exports/donations-pdf so the two
     reports read as one family when they land on the same desk.

     Landscape A4: eight columns of booking detail do not fit portrait
     without the Gujarati seva names wrapping to three lines each. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @include('pdf._fonts')
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Noto Sans Gujarati', 'DejaVu Sans', sans-serif; font-size: 10px; color: #333; padding: 16px; }

        .header { text-align: center; margin-bottom: 14px; border-bottom: 2px solid #881337; padding-bottom: 10px; }
        .header h1 { font-size: 16px; color: #881337; margin-bottom: 3px; }
        .header h2 { font-size: 13px; margin-top: 6px; color: #333; }
        .header p { font-size: 9px; color: #666; }
        .regline { font-size: 8px; color: #888; margin-bottom: 4px; }

        .summary { margin-bottom: 10px; }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td { padding: 6px 8px; background: #FFF7ED; border: 1px solid #F0E0CC; font-size: 10px; text-align: center; }
        .summary .label { color: #777; font-size: 8px; text-transform: uppercase; display: block; margin-bottom: 2px; }
        .summary .value { font-weight: bold; color: #881337; font-size: 12px; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows th { background: #881337; color: #fff; padding: 6px 7px; text-align: left; font-size: 8px; text-transform: uppercase; }
        table.rows td { padding: 5px 7px; border-bottom: 1px solid #eee; font-size: 9px; }
        table.rows tr:nth-child(even) td { background: #fdf6ee; }
        .num { text-align: right; }
        .kind { font-size: 8px; padding: 1px 5px; border-radius: 3px; }
        .kind-seva { background: #FDECEC; color: #881337; }
        .kind-hall { background: #FEF3C7; color: #92400E; }
        .total-row td { border-top: 2px solid #881337; font-weight: bold; font-size: 10px; background: #FFF7ED; }

        .empty { padding: 24px; text-align: center; color: #999; font-size: 11px; }
        .footer { margin-top: 14px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $trustName }}</h1>
        <p class="regline">Trust Reg. No: A/1497 Dated 26-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</p>
        <h2>Bookings Report</h2>
        <p>{{ $from->format('d M Y') }} &mdash; {{ $to->format('d M Y') }}</p>
    </div>

    <div class="summary">
        <table>
            <tr>
                <td><span class="label">Total bookings</span><span class="value">{{ number_format($rows->count()) }}</span></td>
                <td><span class="label">Seva</span><span class="value">{{ number_format($sevaCount) }}</span></td>
                <td><span class="label">Hall</span><span class="value">{{ number_format($hallCount) }}</span></td>
                <td><span class="label">Value</span><span class="value">{{ inr_money($total) }}</span></td>
            </tr>
        </table>
    </div>

    @if ($rows->isEmpty())
        <div class="empty">No bookings in this period.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th style="width:14%">Date</th>
                    <th style="width:7%">Type</th>
                    <th style="width:26%">Seva / Hall</th>
                    <th style="width:13%">Slot</th>
                    <th style="width:20%">Booked by</th>
                    <th style="width:12%">Phone</th>
                    <th style="width:8%" class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td><span class="kind {{ $row['kind'] === 'Seva' ? 'kind-seva' : 'kind-hall' }}">{{ $row['kind'] }}</span></td>
                        <td>{{ $row['what'] }}</td>
                        <td>{{ $row['detail'] }}</td>
                        <td>{{ $row['who'] }}</td>
                        <td>{{ $row['phone'] }}</td>
                        <td class="num">{{ inr_money($row['amount']) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="6">Total &mdash; {{ $rows->count() }} booking(s)</td>
                    <td class="num">{{ inr_money($total) }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    <div class="footer">
        Generated {{ \Illuminate\Support\Carbon::now()->format('d M Y, h:i A') }} &nbsp;|&nbsp; {{ $trustName }}
    </div>
</body>
</html>
