<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\Generate80GReceipt;
use App\Jobs\GenerateHallInvoice;
use App\Jobs\GenerateStoreInvoice;
use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SevaBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for "this payment captured" side-effects.
 *
 * Entry points:
 *   • Razorpay webhook (payment.captured event)
 *   • POST /api/v1/payments/verify (Sanctum, after Razorpay JS handler)
 *   • Web success callbacks: DonationWebController::thankYou,
 *     SevaWebController::bookingSuccess, StoreWebController::orderSuccess,
 *     HallBookingController::bookingSuccess
 *
 * All five paths converge here; the service is the only place that
 * flips Payment.status to 'captured' and reconciles downstream rows.
 *
 * Concurrency model:
 *   • Fast-path early exit when Payment.status is already 'captured'
 *     (avoids opening a transaction for replays).
 *   • All state mutation happens inside DB::transaction with
 *     Payment::lockForUpdate. A second concurrent caller (webhook
 *     racing /payments/verify) blocks on the row lock until the first
 *     commits, then re-reads status='captured' and bails. This is the
 *     race-safe equivalent of an SQL-level mutex on the Payment id.
 *   • Each downstream row (SevaBooking, Order, HallBooking, Donation)
 *     is also locked while its status flips, so admin Filament edits
 *     happening at the same instant don't lose the capture write.
 *   • Stock decrements lock Product rows in id-ASC order to avoid
 *     deadlocks between concurrently-capturing orders.
 *
 * Slow side effects (PDF generation, notification dispatch) run
 * AFTER the transaction commits via DB::afterCommit so a slow PDF
 * render can't hold the payment row's lock. Notifications themselves
 * also use afterCommit internally — double safety.
 *
 * If anything inside the transaction throws, every state update rolls
 * back as a unit. Payment stays at its pre-capture status; the caller
 * sees the exception and can retry (idempotency guarantees re-running
 * is safe).
 */
class PaymentCaptureService
{
    public function markCaptured(
        Payment $payment,
        ?string $razorpayPaymentId = null,
        ?string $method = null,
        ?array $rawWebhookPayload = null,
    ): void {
        // Fast path — no lock, no transaction. Replays (webhook arriving
        // after /payments/verify already captured) exit here.
        if ($payment->status->value === 'captured') {
            return;
        }

        // Resources captured during the transaction; slow side effects
        // for each run AFTER commit so the row lock isn't held during
        // PDF rendering / notification fan-out.
        $captured = [
            'booking' => null,       // SevaBooking
            'order' => null,         // Store Order
            'hallBooking' => null,   // HallBooking
            'donation' => null,      // Donation
        ];

        DB::transaction(function () use ($payment, $razorpayPaymentId, $method, $rawWebhookPayload, &$captured) {
            // Re-fetch under FOR UPDATE — the authoritative read that
            // serialises concurrent captures.
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked === null) {
                Log::warning('PaymentCapture: row vanished between read and lock', [
                    'payment_id' => $payment->id,
                ]);
                return;
            }
            if ($locked->status->value === 'captured') {
                // Another concurrent caller won the race. Don't re-fire side
                // effects; their transaction will dispatch them after commit.
                return;
            }

            $locked->update([
                'status' => 'captured',
                'razorpay_payment_id' => $razorpayPaymentId ?? $locked->razorpay_payment_id,
                'paid_at' => now(),
                'method' => $method ?? $locked->method,
                'webhook_payload' => $rawWebhookPayload ?? $locked->webhook_payload,
            ]);

            // ---- Seva booking --------------------------------------
            $booking = SevaBooking::where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if ($booking !== null) {
                $booking->update(['status' => 'confirmed']);
                $booking->loadMissing('devotee', 'seva');
                $trustName = \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
                // dispatch() uses DB::afterCommit internally → safe to
                // call inside the transaction; the actual driver work
                // fires once the transaction commits.
                app(\App\Services\Notifications\NotificationService::class)->dispatch(
                    'seva.booking.confirmed',
                    [
                        'booking' => $booking,
                        'devotee' => $booking->devotee,
                        'trust_name' => $trustName,
                    ],
                    idempotencyKey: "payment:{$payment->id}:seva.booking.confirmed",
                );

                // Fan-out staff alert — one dispatch per admin user
                // holding the 'pujari' role. Each gets its own per-admin
                // context (so the template's recipient_strategy=context_path
                // with recipient_value=admin.email / admin.phone resolves
                // correctly). If no pujaris are configured, this loop is
                // a no-op — the template existing+enabled isn't enough,
                // there has to be at least one role-holder.
                foreach (\App\Models\AdminUser::role('pujari')->where('is_active', true)->get() as $admin) {
                    app(\App\Services\Notifications\NotificationService::class)->dispatch(
                        'seva.booking.staff_alert',
                        [
                            'booking' => $booking,
                            'devotee' => $booking->devotee,
                            'admin' => $admin,
                            'trust_name' => $trustName,
                        ],
                        idempotencyKey: "payment:{$payment->id}:seva.booking.staff_alert:{$admin->getKey()}",
                    );
                }
                $captured['booking'] = $booking;
            }

            // ---- Store order + stock decrement ---------------------
            $order = Order::where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if ($order !== null) {
                $order->update(['status' => 'confirmed']);
                $order->loadMissing('items');

                // Lock product rows in deterministic order to avoid
                // cross-order deadlocks when two captures target
                // overlapping products simultaneously.
                $productIds = $order->items
                    ->pluck('product_id')
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $products = empty($productIds)
                    ? collect()
                    : Product::whereIn('id', $productIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                foreach ($order->items as $item) {
                    $product = $products->get($item->product_id);
                    if ($product === null) {
                        Log::warning('PaymentCapture: product missing during stock decrement', [
                            'order_id' => $order->id,
                            'product_id' => $item->product_id,
                        ]);
                        continue;
                    }
                    if ($product->has_variants && $item->variant_label) {
                        $product->decrementVariantStock(
                            $item->variant_label,
                            (int) $item->quantity,
                        );
                    } else {
                        $product->decrementStock((int) $item->quantity);
                    }
                }

                $captured['order'] = $order;
                Log::info("Store order {$order->id} confirmed via payment capture", [
                    'items' => $order->items->count(),
                ]);
            }

            // ---- Hall booking --------------------------------------
            $hallBooking = HallBooking::where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if ($hallBooking !== null) {
                $hallBooking->update(['status' => 'confirmed']);
                $captured['hallBooking'] = $hallBooking;
                Log::info("Hall booking {$hallBooking->id} confirmed via payment capture");
            }

            // ---- Donation + PAN sync -------------------------------
            // 80G receipts are STRICTLY for direct donations (daan), not
            // for seva / hall / store payments. Earlier this block also
            // synthesised a Donation from a SevaBooking with
            // is_80g_eligible=true and fired Generate80GReceipt, which is
            // why test seva bookings were emailing 80G PDFs. Removed —
            // seva payments now fire only seva.booking.confirmed above.
            $donation = Donation::where('payment_id', $payment->id)
                ->lockForUpdate()
                ->first();
            if ($donation !== null) {
                // PAN is intentionally NOT snapshotted onto the donation
                // row — its canonical home is temple_devotees.pan_encrypted
                // and the receipt-generation path reads from there.
                app(\App\Services\Notifications\NotificationService::class)->dispatch(
                    'donation.confirmed',
                    [
                        'donation' => $donation->loadMissing('devotee', 'campaign', 'donationType'),
                        'devotee' => $donation->devotee,
                        'trust_name' => \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                    ],
                    idempotencyKey: "payment:{$payment->id}:donation.confirmed",
                );

                $captured['donation'] = $donation;
            }

            Log::info("Payment {$payment->razorpay_order_id} captured", [
                'payment_id' => $payment->id,
                'razorpay_payment_id' => $razorpayPaymentId,
                'amount' => $locked->amount,
            ]);
        });

        // -- Post-commit slow side effects ---------------------------
        // Run AFTER the transaction so PDF rendering doesn't hold the
        // Payment row lock. Each side effect is independently wrapped
        // in try/catch so a failed invoice can't block the 80G receipt
        // (and vice versa).
        if ($captured['order'] !== null) {
            try {
                GenerateStoreInvoice::dispatchSync($captured['order']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: store invoice generation failed', [
                    'order_id' => $captured['order']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($captured['hallBooking'] !== null) {
            try {
                GenerateHallInvoice::dispatchSync($captured['hallBooking']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: hall invoice generation failed', [
                    'booking_id' => $captured['hallBooking']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($captured['donation'] !== null) {
            try {
                Generate80GReceipt::dispatchSync($captured['donation']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: 80G receipt generation failed', [
                    'donation_id' => $captured['donation']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Mark a payment as failed and cancel any associated booking/order.
     * Mirrors markCaptured's transaction + locking model.
     */
    public function markFailed(Payment $payment, ?array $rawWebhookPayload = null): void
    {
        // Fast path — already failed, nothing to do. Don't bail on
        // 'captured' status here: a payment that captured can also
        // later refund/fail, but that's a separate code path.
        if ($payment->status->value === 'failed') {
            return;
        }

        DB::transaction(function () use ($payment, $rawWebhookPayload) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status->value === 'failed') {
                return;
            }

            $locked->update([
                'status' => 'failed',
                'webhook_payload' => $rawWebhookPayload ?? $locked->webhook_payload,
            ]);

            $booking = SevaBooking::where('payment_id', $payment->id)->lockForUpdate()->first();
            if ($booking !== null) {
                $booking->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => 'Payment failed',
                ]);
            }

            $order = Order::where('payment_id', $payment->id)->lockForUpdate()->first();
            if ($order !== null) {
                $order->update(['status' => 'cancelled']);
            }

            $hallBooking = HallBooking::where('payment_id', $payment->id)->lockForUpdate()->first();
            if ($hallBooking !== null) {
                $hallBooking->update(['status' => 'cancelled']);
            }
        });
    }
}
