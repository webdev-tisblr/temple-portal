<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\NumberToWords;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Support\DevoteeLocale;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the store order (tax) invoice PDF. The file on r2_private is a
 * regenerable cache — CleanGeneratedInvoices sweeps `invoices/` and NULLs
 * invoice_path; every download path regenerates on a NULL path.
 *
 * Localized since 2026-08-09 — see SevaReceiptService for why the locale
 * is resolved inside the service rather than at the call site.
 */
class InvoiceService
{
    public function generateInvoice(Order $order): string
    {
        $order->loadMissing('items', 'devotee', 'payment');

        $locale = DevoteeLocale::for($order->devotee);
        $path = $this->pathFor($order->order_number, $locale);

        $output = DevoteeLocale::withLocale($locale, function () use ($order, $locale): string {
            // mPDF (not DomPDF): product names/addresses carry Gujarati/
            // Devanagari, which DomPDF cannot shape.
            return GujaratiPdf::render('invoices.store-invoice', [
                'order' => $order,
                'trust_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
                'trust_address' => SystemSetting::getLocalized('trust_address', $locale, 'Antarjal, Gandhidham, Kutch - 370205'),
                'trust_reg_no' => SystemSetting::getValue('trust_registration_no') ?: 'A/1497 Dated 26-04-1994',
                'trust_80g_reg_no' => SystemSetting::getValue('trust_80g_reg_no') ?: 'A.A/RG./80G/12/G.R./2011-12/3958',
                'trust_pan' => SystemSetting::getValue('trust_pan') ?: 'AAKTS1478C',
                // English in every language on purpose — see SevaReceiptService.
                'amount_in_words' => NumberToWords::convert((float) $order->total_amount),
                'payment_mode_label' => $this->paymentModeLabel($order),
            ], ['format' => 'A4', 'watermark' => 'TAX INVOICE']);
        });

        Storage::disk('r2_private')->put($path, $output);

        $order->update(['invoice_path' => $path]);

        return $path;
    }

    /**
     * Storage key for an invoice in a given language. The `-{locale}`
     * suffix keeps the regenerable r2_private cache from serving a
     * previously-rendered language after the customer switches.
     */
    public function pathFor(string $orderNumber, string $locale): string
    {
        return "invoices/{$orderNumber}-{$locale}.pdf";
    }

    /** Missing file, or a file rendered in a language the customer no longer uses. */
    public function needsRegeneration(Order $order): bool
    {
        if (! $order->invoice_path) {
            return true;
        }

        return $order->invoice_path !== $this->pathFor(
            (string) $order->order_number,
            DevoteeLocale::for($order->devotee),
        );
    }

    private function paymentModeLabel(Order $order): string
    {
        $method = trim((string) ($order->payment?->method ?? ''));

        return $method === '' ? __('receipt.mode_online') : ucfirst($method);
    }
}
