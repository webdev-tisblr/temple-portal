<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SevaBooking;
use App\Services\ReceiptService;
use App\Services\SevaReceiptService;
use Illuminate\Support\Facades\Log;

/**
 * "Give me the receipt PDF for this seva booking, whichever kind it is."
 *
 * FOUR surfaces hand a seva receipt to somebody — the permanent signed
 * link in WhatsApp/email (ReceiptLinkController), the app
 * (Api\V1\SevaController), the devotee web dashboard (DashboardController)
 * and the admin panel (EditSevaBooking). Before the 80G work they each
 * carried their own copy of "regenerate if needed, then redirect"; adding
 * a second kind of document to four copies is how three of them end up
 * serving the wrong PDF. So the choice lives here, once.
 *
 * ⚠ ALLOCATION-FREE BY CONSTRUCTION. This never calls
 * generateForSevaBooking() — only ensurePdf(), which takes an
 * already-issued Receipt80G and therefore cannot burn a statutory receipt
 * number. Downloading a receipt must never mint one; that happens exactly
 * once, in GenerateSevaReceipt, at payment capture.
 */
final class SevaReceiptDelivery
{
    /**
     * Resolve the booking's receipt to an r2_private path + filename,
     * regenerating the cached PDF when the nightly sweep has removed it.
     *
     * @return array{0: string, 1: string}|null null when no PDF could be
     *                                          produced (caller should 404)
     */
    public static function resolve(SevaBooking $booking): ?array
    {
        $receipt = $booking->receipt80G;

        // ── Statutory 80G receipt ───────────────────────────────────
        if ($receipt !== null) {
            try {
                app(ReceiptService::class)->ensurePdf($receipt);
                $receipt->refresh();
            } catch (\Throwable $e) {
                Log::error('Seva 80G receipt regeneration failed', [
                    'booking_id' => $booking->id,
                    'receipt_number' => $receipt->receipt_number,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! $receipt->pdf_path) {
                return null;
            }

            return [
                $receipt->pdf_path,
                '80G_Receipt_'.str_replace('/', '-', (string) $receipt->receipt_number).'.pdf',
            ];
        }

        // ── Ordinary, localized seva receipt ────────────────────────
        // Gated on needsRegeneration(), not a bare null check: the path is
        // locale-suffixed, so a devotee who switched language has a stored
        // path that no longer matches (fixed 2026-08-12).
        if (app(SevaReceiptService::class)->needsRegeneration($booking)) {
            try {
                app(SevaReceiptService::class)->generateReceipt($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('Seva receipt regeneration failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! $booking->receipt_path) {
            return null;
        }

        return [
            $booking->receipt_path,
            'Seva_Receipt_'.str_replace('/', '-', (string) ($booking->receipt_number ?? $booking->id)).'.pdf',
        ];
    }
}
