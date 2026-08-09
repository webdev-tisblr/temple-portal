<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Support\DevoteeLocale;
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
 *
 * Localized since 2026-08-09 — see SevaReceiptService for why the locale
 * is resolved inside the service rather than at the call site.
 */
class HallInvoiceService
{
    public function generateInvoice(HallBooking $booking): string
    {
        $booking->loadMissing('hall', 'devotee');

        $bookingNumber = $this->bookingNumberFor($booking);
        $locale = DevoteeLocale::for($booking->devotee);
        $path = $this->pathFor($bookingNumber, $locale);

        $output = DevoteeLocale::withLocale($locale, function () use ($booking, $bookingNumber, $locale): string {
            // mPDF via GujaratiPdf, NOT DomPDF — hirer names/addresses carry
            // Gujarati/Devanagari (matra/conjunct garbling under DomPDF).
            return GujaratiPdf::render('invoices.hall-booking-invoice', [
                'booking' => $booking,
                'trust_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
                'trust_address' => SystemSetting::getLocalized('trust_address', $locale, 'Antarjal, Gandhidham, Kutch - 370205'),
                'trust_reg_no' => SystemSetting::getValue('trust_registration_no') ?: 'A/1497 Dated 26-04-1994',
                'trust_80g_reg_no' => SystemSetting::getValue('trust_80g_reg_no') ?: 'A.A/RG./80G/12/G.R./2011-12/3958',
                'trust_pan' => SystemSetting::getValue('trust_pan') ?: 'AAKTS1478C',
                'booking_number' => $bookingNumber,
                // English in every language on purpose — see SevaReceiptService.
                'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
                'status_label' => $this->statusLabel($booking),
                'booking_type_label' => $this->bookingTypeLabel($booking),
            ], ['format' => 'A4', 'watermark' => 'HALL BOOKING']);
        });

        Storage::disk('r2_private')->put($path, $output);

        $booking->update(['invoice_path' => $path]);

        return $path;
    }

    /** Deterministic per-booking number: stable across regenerations. */
    public function bookingNumberFor(HallBooking $booking): string
    {
        return 'HALL-'.$booking->id.'-'.$booking->created_at->format('Ymd');
    }

    /**
     * Storage key for an invoice in a given language. The `-{locale}`
     * suffix keeps the regenerable r2_private cache from serving a
     * previously-rendered language after the devotee switches.
     */
    public function pathFor(string $bookingNumber, string $locale): string
    {
        return "hall-invoices/{$bookingNumber}-{$locale}.pdf";
    }

    /** Missing file, or a file rendered in a language the hirer no longer uses. */
    public function needsRegeneration(HallBooking $booking): bool
    {
        if (! $booking->invoice_path) {
            return true;
        }

        return $booking->invoice_path !== $this->pathFor(
            $this->bookingNumberFor($booking),
            DevoteeLocale::for($booking->devotee),
        );
    }

    private function statusLabel(HallBooking $booking): string
    {
        $status = (string) $booking->status;
        $key = "receipt.status_{$status}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : ucfirst($status);
    }

    private function bookingTypeLabel(HallBooking $booking): string
    {
        $type = (string) $booking->booking_type;
        $key = "receipt.type_{$type}";
        $label = __($key);

        return is_string($label) && $label !== $key
            ? $label
            : ucfirst(str_replace('_', ' ', $type));
    }
}
