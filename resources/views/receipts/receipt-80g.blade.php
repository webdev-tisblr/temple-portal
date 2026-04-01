<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; padding: 40px; }
        .container { border: 2px solid #881337; padding: 30px; }
        .header { text-align: center; border-bottom: 2px solid #F97316; padding-bottom: 15px; margin-bottom: 20px; }
        .trust-name { font-size: 20px; font-weight: bold; color: #881337; margin-bottom: 5px; }
        .trust-address { font-size: 10px; color: #666; }
        .receipt-title { font-size: 14px; font-weight: bold; color: #F97316; margin-top: 10px; text-transform: uppercase; letter-spacing: 1px; }
        .receipt-meta { display: table; width: 100%; margin-bottom: 20px; }
        .meta-row { display: table-row; }
        .meta-label { display: table-cell; width: 40%; padding: 5px 0; font-weight: bold; color: #555; }
        .meta-value { display: table-cell; padding: 5px 0; }
        .section { margin-bottom: 20px; }
        .section-title { font-size: 13px; font-weight: bold; color: #881337; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        table td { padding: 6px 8px; border-bottom: 1px solid #eee; }
        table td:first-child { font-weight: bold; color: #555; width: 40%; }
        .amount-box { background: #FFF7ED; border: 1px solid #F97316; padding: 12px; text-align: center; margin: 15px 0; }
        .amount-figure { font-size: 22px; font-weight: bold; color: #881337; }
        .amount-words { font-size: 11px; color: #666; margin-top: 4px; }
        .footer { margin-top: 30px; border-top: 1px solid #ddd; padding-top: 15px; }
        .footer p { font-size: 9px; color: #999; text-align: center; margin-bottom: 3px; }
        .note { background: #f9f9f9; padding: 10px; font-size: 10px; color: #666; margin-top: 15px; border-left: 3px solid #F97316; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="trust-name">{{ $receipt->trust_name }}</div>
            <div class="trust-address">{{ $receipt->trust_address }}</div>
            <div class="receipt-title">Donation Receipt under Section 80G of Income Tax Act</div>
        </div>

        <div class="section">
            <table>
                <tr>
                    <td>Receipt Number</td>
                    <td>{{ $receipt->receipt_number }}</td>
                </tr>
                <tr>
                    <td>Date of Receipt</td>
                    <td>{{ $receipt->generated_at?->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Financial Year</td>
                    <td>{{ $receipt->financial_year }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Donor Details</div>
            <table>
                <tr>
                    <td>Name</td>
                    <td>{{ $receipt->devotee_name }}</td>
                </tr>
                @if($receipt->devotee_address)
                <tr>
                    <td>Address</td>
                    <td>{{ $receipt->devotee_address }}</td>
                </tr>
                @endif
                <tr>
                    <td>PAN</td>
                    <td>{{ $receipt->pan_number }}</td>
                </tr>
            </table>
        </div>

        <div class="section">
            <div class="section-title">Donation Details</div>
            <table>
                <tr>
                    <td>Date of Donation</td>
                    <td>{{ \Carbon\Carbon::parse($receipt->donation_date)->format('d/m/Y') }}</td>
                </tr>
                <tr>
                    <td>Payment Mode</td>
                    <td>{{ ucfirst($receipt->payment_mode) }}</td>
                </tr>
            </table>

            <div class="amount-box">
                <div class="amount-figure">&#8377; {{ number_format((float) $receipt->amount, 2) }}</div>
                <div class="amount-words">{{ $receipt->amount_in_words }}</div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Trust Details</div>
            <table>
                @if($receipt->trust_pan)
                <tr>
                    <td>Trust PAN</td>
                    <td>{{ $receipt->trust_pan }}</td>
                </tr>
                @endif
                @if($receipt->trust_80g_registration_no)
                <tr>
                    <td>80G Registration No.</td>
                    <td>{{ $receipt->trust_80g_registration_no }}</td>
                </tr>
                @endif
                @if($receipt->trust_80g_validity_period)
                <tr>
                    <td>80G Validity Period</td>
                    <td>{{ $receipt->trust_80g_validity_period }}</td>
                </tr>
                @endif
            </table>
        </div>

        <div class="note">
            This donation is eligible for deduction under Section 80G of the Income Tax Act, 1961, subject to applicable conditions.
        </div>

        <div class="footer">
            <p>This is a computer-generated receipt and does not require a signature.</p>
            <p>{{ $receipt->trust_name }} | {{ $receipt->trust_address }}</p>
        </div>
    </div>
</body>
</html>
