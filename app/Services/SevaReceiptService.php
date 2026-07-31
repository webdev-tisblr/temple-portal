<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the seva-booking receipt PDF. Mirrors InvoiceService /
 * ReceiptService: the PDF on r2_private is a short-lived cache —
 * CleanGeneratedInvoices sweeps `seva-receipts/` and NULLs
 * receipt_path; download controllers regenerate on a NULL path.
 *
 * This is a plain booking receipt, NOT an 80G receipt — seva payments
 * are deliberately not 80G-eligible (see PaymentCaptureService).
 */
class SevaReceiptService
{
    public function generateReceipt(SevaBooking $booking): string
    {
        $booking->loadMissing('devotee', 'seva', 'selectedProduct', 'payment');

        $receiptNumber = $this->receiptNumberFor($booking);

        // mPDF via GujaratiPdf, NOT DomPDF — seva names and devotee
        // names carry Gujarati, which DomPDF cannot shape.
        $output = GujaratiPdf::render('receipts.seva-receipt', [
            'booking' => $booking,
            'receipt_number' => $receiptNumber,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
        ], ['format' => 'A4', 'watermark' => 'SEVA RECEIPT']);

        $path = "seva-receipts/{$receiptNumber}.pdf";

        // R2 has no concept of directories — put() writes the key directly.
        Storage::disk('r2_private')->put($path, $output);

        $booking->update([
            'receipt_number' => $receiptNumber,
            'receipt_path' => $path,
        ]);

        return $path;
    }

    /**
     * Deterministic per-booking number (hall-invoice style): stable
     * across regenerations, no counter row to race on. 10 UUID hex
     * chars + the date make same-day collisions practically impossible
     * while staying short enough for a devotee-facing receipt.
     */
    public function receiptNumberFor(SevaBooking $booking): string
    {
        return $booking->receipt_number
            ?? 'SEVA-'.$booking->created_at->format('Ymd').'-'
                .strtoupper(substr(str_replace('-', '', (string) $booking->id), 0, 10));
    }
}
