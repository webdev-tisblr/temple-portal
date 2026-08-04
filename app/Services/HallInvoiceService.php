<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the hall-booking invoice PDF. Mirrors SevaReceiptService /
 * InvoiceService: the PDF on r2_private is a short-lived cache —
 * CleanGeneratedInvoices sweeps `hall-invoices/` and NULLs
 * invoice_path; download controllers regenerate on a NULL path.
 *
 * Consolidates the render that previously lived in both
 * GenerateHallInvoice (job) and HallBookingController — one copy,
 * no notification side effects (the job owns the trigger dispatch).
 */
class HallInvoiceService
{
    public function generateInvoice(HallBooking $booking): string
    {
        $booking->loadMissing('hall', 'devotee');

        $bookingNumber = $this->bookingNumberFor($booking);

        // mPDF via GujaratiPdf, NOT DomPDF — hirer names/addresses carry
        // Gujarati, which DomPDF cannot shape (matra/conjunct garbling).
        $output = GujaratiPdf::render('invoices.hall-booking-invoice', [
            'booking' => $booking,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'booking_number' => $bookingNumber,
            'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
        ], ['format' => 'A4', 'watermark' => 'HALL BOOKING']);

        $path = "hall-invoices/{$bookingNumber}.pdf";

        Storage::disk('r2_private')->put($path, $output);

        $booking->update(['invoice_path' => $path]);

        return $path;
    }

    /** Deterministic per-booking number: stable across regenerations. */
    public function bookingNumberFor(HallBooking $booking): string
    {
        return 'HALL-'.$booking->id.'-'.$booking->created_at->format('Ymd');
    }
}
