<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #333; padding: 20px; }
        .header { text-align: center; margin-bottom: 15px; border-bottom: 2px solid #881337; padding-bottom: 10px; }
        .header h1 { font-size: 16px; color: #881337; margin-bottom: 3px; }
        .header p { font-size: 10px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #881337; color: #fff; padding: 6px 8px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 9px; }
        tr:nth-child(even) td { background: #fdf6ee; }
        .total-row td { border-top: 2px solid #881337; font-weight: bold; font-size: 10px; background: #FFF7ED; }
        .footer { margin-top: 15px; text-align: center; font-size: 8px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }
        .summary { margin-top: 10px; font-size: 10px; }
        .summary span { font-weight: bold; color: #881337; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Shree Pataliya Hanumanji Seva Trust</h1>
        <p style="font-size:8px;color:#888;margin-bottom:4px;">Trust Reg. No: A/1497 Dated 28-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</p>
        <h1 style="font-size:14px;margin-top:6px;">Donation Report</h1>
        <p>{{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }} &mdash; {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</p>
    </div>

    <div class="summary">
        Total Donations: <span>{{ $donations->count() }}</span> &nbsp;|&nbsp;
        Total Amount: <span>&#8377; {{ number_format((float) $total, 2) }}</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Receipt No.</th>
                <th>Devotee</th>
                <th>Phone</th>
                <th>Amount (&#8377;)</th>
                <th>Type</th>
                <th>Purpose</th>
                <th>FY</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($donations as $i => $d)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $d->created_at->format('d/m/Y') }}</td>
                <td>{{ $d->receipt?->receipt_number ?? '-' }}</td>
                <td>{{ $d->devotee?->name ?? 'Anonymous' }}</td>
                <td>{{ $d->devotee?->phone ?? '-' }}</td>
                <td style="text-align: right;">{{ number_format((float) $d->amount, 2) }}</td>
                <td>{{ ucfirst($d->getRawOriginal('donation_type')) }}</td>
                <td>{{ $d->purpose ?? '-' }}</td>
                <td>{{ $d->financial_year }}</td>
                <td>{{ $d->payment?->status?->value ?? '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="10" style="text-align: center; padding: 20px; color: #999;">No donations found for this period.</td></tr>
            @endforelse

            @if($donations->isNotEmpty())
            <tr class="total-row">
                <td colspan="5" style="text-align: right;">Total:</td>
                <td style="text-align: right;">{{ number_format((float) $total, 2) }}</td>
                <td colspan="4"></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ now()->format('d M Y, h:i A') }} &mdash; Shree Pataliya Hanumanji Seva Trust
    </div>
</body>
</html>
