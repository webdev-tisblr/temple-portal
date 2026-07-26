<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\Donation;
use App\Models\Receipt80G;
use App\Models\SystemSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class ReceiptService
{
    public function generateReceipt(Donation $donation): Receipt80G
    {
        $existing = Receipt80G::where('donation_id', $donation->id)->first();
        if ($existing) {
            // Regenerate the PDF if the file went missing.
            if (! $existing->pdf_path || ! Storage::disk('r2_private')->exists($existing->pdf_path)) {
                $existing->update(['pdf_path' => $this->generatePdf($existing)]);
            }
            return $existing;
        }

        $donation->loadMissing('devotee', 'payment');

        $prefix = SystemSetting::getValue('receipt_prefix', 'SPHST/80G');
        $fy = $donation->financial_year;
        $lastSerial = Receipt80G::where('financial_year', $fy)->count();
        $serial = str_pad((string) ($lastSerial + 1), 5, '0', STR_PAD_LEFT);
        $receiptNumber = "{$prefix}/{$fy}/{$serial}";

        $devotee = $donation->devotee;
        $panNumber = 'N/A';
        if (! empty($devotee->pan_encrypted)) {
            try {
                $panNumber = decrypt($devotee->pan_encrypted);
            } catch (\Exception) {
                $panNumber = $devotee->pan_last_four ? '******' . $devotee->pan_last_four : 'N/A';
            }
        }

        $receipt = Receipt80G::create([
            'donation_id' => $donation->id,
            'receipt_number' => $receiptNumber,
            'financial_year' => $fy,
            'devotee_name' => $devotee->name ?: 'Devotee',
            'devotee_address' => collect([$devotee->address, $devotee->city, $devotee->state, $devotee->pincode])
                ->filter()->implode(', '),
            'devotee_phone' => $devotee->phone,
            'devotee_email' => $devotee->email,
            'pan_number' => $panNumber,
            'amount' => $donation->amount,
            'amount_in_words' => NumberToWords::convert((float) $donation->amount),
            'donation_date' => $donation->created_at->toDateString(),
            'payment_mode' => $donation->payment?->method ?? 'Online',
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'trust_pan' => SystemSetting::getValue('trust_pan', ''),
            'trust_80g_registration_no' => SystemSetting::getValue('trust_80g_reg_no', ''),
            'trust_80g_validity_period' => SystemSetting::getValue('trust_80g_validity', ''),
            'generated_at' => now(),
        ]);

        $pdfPath = $this->generatePdf($receipt);
        $receipt->update(['pdf_path' => $pdfPath]);

        return $receipt;
    }

    public function generatePdf(Receipt80G $receipt): string
    {
        // mPDF via GujaratiPdf, NOT DomPDF: donor names/addresses carry
        // Gujarati, and DomPDF cannot shape Indic text — matras and
        // conjuncts render in the wrong visual order (user-visible on
        // real 80G receipts, 2026-07-26). Same migration the store
        // invoice + packing slip went through.
        $output = \App\Support\Pdf\GujaratiPdf::render('receipts.receipt-80g', [
            'receipt' => $receipt,
        ], ['format' => 'A4']);

        $directory = "receipts/{$receipt->financial_year}";
        $filename = str_replace('/', '-', $receipt->receipt_number) . '.pdf';
        $path = "{$directory}/{$filename}";

        // R2 has no concept of directories — put() writes the key directly.
        Storage::disk('r2_private')->put($path, $output);

        return $path;
    }
}
