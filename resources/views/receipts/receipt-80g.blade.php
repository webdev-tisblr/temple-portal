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
        .trust-reg { font-size: 9px; color: #888; }
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

        /* Amount box */
        .amount-box { background: #FDF6EE; border: 1px solid #C87533; padding: 12px; text-align: center; margin: 12px 0; }
        .amount-figure { font-size: 22px; font-weight: bold; color: #881337; }
        .amount-words { font-size: 10px; color: #666; margin-top: 3px; font-style: italic; }

        /* Thank you */
        .thank-you { text-align: center; margin: 16px 0 12px; padding: 10px; background: linear-gradient(135deg, #FDF6EE, #FFF9F0); border: 1px solid #E5D3B3; border-radius: 4px; }
        .thank-you p { font-size: 13px; font-weight: bold; color: #881337; }
        .thank-you .sub { font-size: 9px; color: #888; margin-top: 3px; font-weight: normal; }

        /* Legal notes */
        .legal-notes { margin-top: 14px; padding: 10px 12px; background: #F9F9F9; border-left: 3px solid #C87533; }
        .legal-notes p { font-size: 8.5px; color: #777; margin-bottom: 5px; line-height: 1.4; }
        .legal-notes p:last-child { margin-bottom: 0; }
        .legal-notes .note-num { font-weight: bold; color: #881337; }

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
        <div class="watermark">80G RECEIPT</div>

        {{-- Header with Logo --}}
        <div class="header">
            <div class="logo">
                <img src="{{ public_path('images/shree-pataliya-hanumanji-logo.png') }}" alt="Logo">
            </div>
            <div class="trust-name">{{ $receipt->trust_name }}</div>
            <div class="trust-address">{{ $receipt->trust_address }}</div>
            <div class="trust-reg">Trust Reg. No: A/1497 Dated 28-04-1994 &nbsp;|&nbsp; 80G Reg. No: A.A/RG./80G/12/G.R./2011-12/3958 &nbsp;|&nbsp; PAN: AAKTS1478C</div>
            <div style="margin-top: 10px;">
                <span class="receipt-title">Donation Receipt u/s 80G</span>
            </div>
        </div>

        {{-- Receipt Meta Bar --}}
        <table class="meta-bar">
            <tr>
                <td>
                    <span class="meta-label">Receipt No.</span>
                    <span class="meta-value">{{ $receipt->receipt_number }}</span>
                </td>
                <td>
                    <span class="meta-label">Date of Donation</span>
                    <span class="meta-value">{{ \Carbon\Carbon::parse($receipt->donation_date)->format('d/m/Y') }}</span>
                </td>
                <td>
                    <span class="meta-label">Financial Year</span>
                    <span class="meta-value">{{ $receipt->financial_year }}</span>
                </td>
                <td>
                    <span class="meta-label">Payment Mode</span>
                    <span class="meta-value">{{ ucfirst($receipt->payment_mode) }}</span>
                </td>
            </tr>
        </table>

        {{-- Donor & Donation Details --}}
        <table class="two-col">
            <tr>
                <td>
                    <div class="section">
                        <div class="section-title">Donor Details</div>
                        <table class="data-table">
                            <tr>
                                <td class="label">Name</td>
                                <td class="value">{{ $receipt->devotee_name }}</td>
                            </tr>
                            @if($receipt->devotee_phone)
                            <tr>
                                <td class="label">Phone</td>
                                <td class="value">{{ $receipt->devotee_phone }}</td>
                            </tr>
                            @endif
                            @if($receipt->devotee_email)
                            <tr>
                                <td class="label">Email</td>
                                <td class="value">{{ $receipt->devotee_email }}</td>
                            </tr>
                            @endif
                            @if($receipt->devotee_address)
                            <tr>
                                <td class="label">Address</td>
                                <td class="value">{{ $receipt->devotee_address }}</td>
                            </tr>
                            @endif
                            <tr>
                                <td class="label">PAN</td>
                                <td class="value">{{ $receipt->pan_number }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
                <td>
                    <div class="section">
                        <div class="section-title">Amount</div>
                        <div class="amount-box">
                            <div class="amount-figure">&#8377; {{ number_format((float) $receipt->amount, 2) }}</div>
                            <div class="amount-words">{{ $receipt->amount_in_words }}</div>
                        </div>
                    </div>
                </td>
            </tr>
        </table>

        {{-- Thank You --}}
        <div class="thank-you">
            <p>Thank you for your generous donation!</p>
            <p class="sub">May Shree Hanumanji bless you and your family with health, happiness and prosperity.</p>
        </div>

        {{-- Legal Notes --}}
        <div class="legal-notes">
            <p><span class="note-num">1.</span> Under Schedule 1, Article 53, Exemption (b) of the Indian Stamps Act, Charitable institutions are not required to issue any (revenue) stamped receipts for donations.</p>
            <p><span class="note-num">2.</span> The donation is exempt under Section 80G(5) of the Income Tax Act, 1961, in view of the exemption certificate granted by the Director of Income Tax (Exemption).</p>
        </div>

        {{-- Footer --}}
        <div class="footer">
            <table class="footer-row">
                <tr>
                    <td>
                        <div style="font-size: 9px; color: #888;">
                            {{ $receipt->trust_name }}<br>
                            {{ $receipt->trust_address }}
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
