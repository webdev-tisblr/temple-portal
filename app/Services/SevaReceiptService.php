<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\DevoteeLocale;
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
 *
 * Localized since 2026-08-09: the whole render runs under the devotee's
 * own language (temple_devotees.language) so labels come from
 * resources/lang/{gu,hi,en}/receipt.php and the Seva/Product `name`
 * accessors resolve to the right column. The locale is resolved HERE, not
 * at the call site — most generations happen in queue jobs, which have no
 * request locale and would always render `gu`.
 */
class SevaReceiptService
{
    public function generateReceipt(SevaBooking $booking): string
    {
        $booking->loadMissing('devotee', 'seva', 'selectedProduct', 'payment');

        $receiptNumber = $this->receiptNumberFor($booking);
        $locale = DevoteeLocale::for($booking->devotee);
        $path = $this->pathFor($receiptNumber, $locale);

        $output = DevoteeLocale::withLocale($locale, function () use ($booking, $receiptNumber, $locale): string {
            // mPDF via GujaratiPdf, NOT DomPDF — seva names and devotee
            // names carry Gujarati/Devanagari, which DomPDF cannot shape.
            return GujaratiPdf::render('receipts.seva-receipt', [
                'booking' => $booking,
                'receipt_number' => $receiptNumber,
                'trust_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
                'trust_address' => SystemSetting::getLocalized('trust_address', $locale, 'Antarjal, Gandhidham, Kutch - 370205'),
                'trust_reg_no' => SystemSetting::getValue('trust_registration_no') ?: 'A/1497 Dated 26-04-1994',
                'trust_pan' => SystemSetting::getValue('trust_pan') ?: 'AAKTS1478C',
                // Amount-in-words deliberately stays English in every
                // language — there is no verified gu/hi numeral-to-words
                // implementation and hand-rolling one on a money document
                // is not acceptable. Only its label is translated.
                'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
                'status_label' => $this->statusLabel($booking),
                'payment_mode_label' => $this->paymentModeLabel($booking),
            ], ['format' => 'A4', 'watermark' => 'SEVA RECEIPT']);
        });

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

    /**
     * Storage key for a receipt in a given language.
     *
     * The `-{locale}` suffix is load-bearing: r2_private is a regenerable
     * cache, so without it a devotee who switches language would keep
     * being served the previously-rendered PDF from the stored path until
     * the nightly sweep happened to delete it.
     */
    public function pathFor(string $receiptNumber, string $locale): string
    {
        return "seva-receipts/{$receiptNumber}-{$locale}.pdf";
    }

    /**
     * True when the stored receipt_path is missing OR was rendered in a
     * language the devotee no longer uses. Callers that self-heal on a
     * NULL path can use this to also self-heal after a language change.
     */
    public function needsRegeneration(SevaBooking $booking): bool
    {
        if (! $booking->receipt_path) {
            return true;
        }

        return $booking->receipt_path !== $this->pathFor(
            $this->receiptNumberFor($booking),
            DevoteeLocale::for($booking->devotee),
        );
    }

    /** Translated status, falling back to the raw enum value for unknown states. */
    private function statusLabel(SevaBooking $booking): string
    {
        $status = $booking->status->value;
        $key = "receipt.status_{$status}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : ucfirst($status);
    }

    private function paymentModeLabel(SevaBooking $booking): string
    {
        $method = trim((string) ($booking->payment?->method ?? ''));

        return $method === '' ? __('receipt.mode_online') : ucfirst($method);
    }
}
