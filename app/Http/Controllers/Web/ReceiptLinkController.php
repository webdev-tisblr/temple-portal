<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\SevaBooking;
use App\Services\HallInvoiceService;
use App\Services\InvoiceService;
use App\Services\SevaReceiptService;
use Illuminate\Support\Facades\Log;

/**
 * Permanent signed receipt/invoice links embedded in confirmation
 * messages (WhatsApp document headers, email bodies).
 *
 * The route signature IS the authorization — no devotee session needed,
 * so the link works from a forwarded WhatsApp message months later.
 * PDFs on r2_private are a regenerable cache (swept after 7 days), so
 * every method regenerates before redirecting through
 * private_file_redirect() (10-minute presigned R2 URL).
 *
 * Regeneration is gated on the service's own needsRegeneration(), NOT on
 * a bare null check. Paths are locale-suffixed ("-gu.pdf"), so a devotee
 * who switches language has a stored path that no longer matches the one
 * pathFor() would produce — a null check alone kept serving the old
 * language until the 7-day sweep, which is exactly what these links are
 * long-lived enough to hit (fixed 2026-08-12).
 *
 * Routes live behind ['signed', 'throttle:30,1'] — do NOT add them to
 * the CacheGuestResponse whitelist or the Cloudflare cache rule.
 */
class ReceiptLinkController extends Controller
{
    public function sevaReceipt(SevaBooking $booking)
    {
        if (! in_array($booking->status->value, ['confirmed', 'completed'], true)) {
            abort(404);
        }

        if (app(SevaReceiptService::class)->needsRegeneration($booking)) {
            try {
                app(SevaReceiptService::class)->generateReceipt($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('Signed-link seva receipt regen failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            abort_unless((bool) $booking->receipt_path, 404);
        }

        $filename = 'Seva_Receipt_'.str_replace('/', '-', (string) ($booking->receipt_number ?? $booking->id)).'.pdf';

        return private_file_redirect($booking->receipt_path, $filename);
    }

    public function storeInvoice(Order $order)
    {
        if (in_array($order->status, [OrderStatus::PENDING, OrderStatus::CANCELLED, OrderStatus::REFUNDED], true)) {
            abort(404);
        }

        if (app(InvoiceService::class)->needsRegeneration($order)) {
            try {
                app(InvoiceService::class)->generateInvoice($order);
                $order->refresh();
            } catch (\Throwable $e) {
                Log::error('Signed-link store invoice regen failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
            abort_unless((bool) $order->invoice_path, 404);
        }

        return private_file_redirect($order->invoice_path, "Invoice_{$order->order_number}.pdf");
    }

    public function hallInvoice(HallBooking $booking)
    {
        if (! in_array($booking->status, ['confirmed', 'completed'], true)) {
            abort(404);
        }

        if (app(HallInvoiceService::class)->needsRegeneration($booking)) {
            try {
                app(HallInvoiceService::class)->generateInvoice($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('Signed-link hall invoice regen failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            abort_unless((bool) $booking->invoice_path, 404);
        }

        return private_file_redirect($booking->invoice_path, "Hall_Booking_{$booking->id}.pdf");
    }
}
