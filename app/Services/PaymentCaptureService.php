<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\Generate80GReceipt;
use App\Jobs\GenerateGreetingCard;
use App\Jobs\GenerateHallInvoice;
use App\Jobs\GenerateSevaGreetingCard;
use App\Jobs\GenerateSevaReceipt;
use App\Jobs\GenerateStoreInvoice;
use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonInterface;
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
 *   • Admin counter entry (item 6.1): CounterEntryService drives a
 *     SYNTHETIC offline Payment (razorpay_order_id 'cash_<ulid>',
 *     method cash|upi_offline|cheque|bank_transfer) through this exact
 *     method, so cash taken at the temple produces byte-for-byte the
 *     same side effects as an online payment. It is the only caller that
 *     passes $paidAt.
 *
 * All these paths converge here; the service is the only place that
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
    /**
     * @param  CarbonInterface|null  $paidAt  When the money actually
     *                                        changed hands. Item 6.1 (manual cash entry): the trust receives cash
     *                                        at the counter and may enter it a day or two later, and a receipt
     *                                        dated "today" for money taken on Saturday is wrong on a statutory
     *                                        document.
     *
     *   PURELY ADDITIVE — last parameter, nullable, defaulting to null which
     *   resolves to now(). Every pre-existing caller (Razorpay webhook,
     *   POST /api/v1/payments/verify, and the four web success callbacks)
     *   omits it and therefore stamps now() exactly as before; none of them
     *   passes arguments positionally past $method, so no call site shifts.
     *   Asserted directly in ManualCashEntryTest::
     *   test_omitting_paid_at_still_stamps_now.
     */
    public function markCaptured(
        Payment $payment,
        ?string $razorpayPaymentId = null,
        ?string $method = null,
        ?array $rawWebhookPayload = null,
        ?CarbonInterface $paidAt = null,
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

        DB::transaction(function () use ($payment, $razorpayPaymentId, $method, $rawWebhookPayload, $paidAt, &$captured) {
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
                // $paidAt === null is the ONLY behaviour every pre-6.1
                // caller ever produced, and it still resolves to now().
                'paid_at' => $paidAt ?? now(),
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
                // The seva.booking.confirmed notification is NOT sent
                // here — it fires from GenerateSevaReceipt (post-commit
                // block below) so the single confirmation message can
                // carry the receipt PDF + signed receipt_pdf_url.
                // Merged flow, 2026-08-04; the old separate seva.receipt
                // trigger is retired.
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
                        $ok = $product->decrementVariantStock(
                            $item->variant_label,
                            (int) $item->quantity,
                        );
                        if (! $ok) {
                            // Variant label vanished between checkout and
                            // capture — payment already taken, so log loudly
                            // for manual reconciliation rather than fail.
                            Log::critical('PaymentCapture: variant stock decrement failed (oversell/missing)', [
                                'order_id' => $order->id,
                                'product_id' => $product->id,
                                'variant_label' => $item->variant_label,
                                'qty' => (int) $item->quantity,
                            ]);
                        }
                    } else {
                        $shortfall = $product->decrementStock((int) $item->quantity);
                        if ($shortfall > 0) {
                            // Two captures raced for the last unit(s). Stock
                            // is floored at 0 (never negative); surface the
                            // oversell so an admin can restock or refund.
                            Log::critical('PaymentCapture: store oversell — insufficient stock at capture', [
                                'order_id' => $order->id,
                                'product_id' => $product->id,
                                'qty_ordered' => (int) $item->quantity,
                                'qty_short' => $shortfall,
                            ]);
                        }
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
                $donation->loadMissing('devotee', 'campaign', 'subCause', 'donationType');

                [$key, $context] = $this->donationConfirmationDispatch($donation);

                app(NotificationService::class)->dispatch(
                    $key,
                    $context,
                    // Scoped per key so the two trigger variants can never
                    // alias each other's dedup row. Exactly one of them is
                    // dispatched per capture, and the fast-path early exit
                    // above means a replayed capture never reaches here at
                    // all — belt (early exit) and braces (idempotency key).
                    idempotencyKey: "payment:{$payment->id}:{$key}",
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
        if ($captured['booking'] !== null) {
            try {
                GenerateSevaReceipt::dispatchSync($captured['booking']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: seva receipt generation failed', [
                    'booking_id' => $captured['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Greeting card is a fully separate deliverable — its own job,
            // its own `seva.greeting_card` trigger. Isolated so a card
            // failure never affects the receipt (and vice-versa).
            //
            // Sent HERE only when the seva is today or already past. Everything
            // in the future is left to `seva:send-day-of-cards`, which sweeps
            // each morning at 07:30 — the trust wants the card to arrive on the
            // day of the seva, not weeks earlier when the booking was paid for.
            // Same-day bookings still card immediately (the sweep has already
            // run by then), as do backdated counter entries.
            try {
                if ($this->sevaCardIsDueNow($captured['booking'])) {
                    GenerateSevaGreetingCard::dispatchSync($captured['booking']);
                }
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: seva greeting card generation failed', [
                    'booking_id' => $captured['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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
            // Live display board FIRST — before the two dispatchSync PDF jobs
            // below, which each render a document and upload it to R2 and
            // realistically cost seconds. The donor is standing in front of
            // the screen at this exact moment; a single indexed INSERT should
            // not queue behind a receipt render.
            //
            // Its own try/catch, like every sibling block: a decorative screen
            // must never be able to fail a payment capture. The service
            // swallows its own errors too — this is belt and braces.
            try {
                app(DisplayBoardService::class)->announce($captured['donation']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: display board announce failed', [
                    'donation_id' => $captured['donation']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                Generate80GReceipt::dispatchSync($captured['donation']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: 80G receipt generation failed', [
                    'donation_id' => $captured['donation']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Greeting card is a fully separate deliverable — its own job,
            // its own `donation.greeting_card` trigger. Isolated so a card
            // failure never affects the 80G receipt (and vice-versa).
            try {
                GenerateGreetingCard::dispatchSync($captured['donation']);
            } catch (\Throwable $e) {
                Log::error('PaymentCapture: greeting card generation failed', [
                    'donation_id' => $captured['donation']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Decide WHICH confirmation trigger a captured donation fires, and
     * build its context. Returns [$key, $context].
     *
     * Routing rule (2026-08-09 split, item 5.1):
     *   • donation attached to a campaign → `donation.campaign.confirmed`
     *   • donation with no campaign       → `donation.confirmed`
     *
     * The two are MUTUALLY EXCLUSIVE — exactly one key is ever dispatched
     * per capture, so a donor whose trust has both templates enabled can
     * never receive two confirmation messages for one payment. (Same
     * failure mode the 2026-08-04 seva.receipt merge migration guarded
     * against; do NOT "helpfully" fire both.)
     *
     * Safety valve: the campaign key only takes over once at least one
     * NotificationTemplate row exists for it. Until an admin creates one,
     * campaign donors keep getting the existing generic message rather
     * than silently getting nothing — deploying the split on its own
     * changes zero outbound behaviour. The seeded campaign template ships
     * `is_enabled = false`, so the trust still has to switch it on before
     * anything is actually delivered (platform rule: nothing sends unless
     * an admin created AND enabled the template for that trigger×channel).
     *
     * ⚠️ Once ANY campaign template exists, campaign donations route
     * wholly to the campaign trigger — including channels the admin has
     * not configured there. Configure every channel the trust cares about
     * on the campaign trigger before creating the first row.
     *
     * An orphaned campaign_id (campaign row deleted) falls back to the
     * generic trigger, since a campaign message with a blank campaign
     * name is worse than the generic one.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function donationConfirmationDispatch(Donation $donation): array
    {
        $context = [
            'donation' => $donation,
            'devotee' => $donation->devotee,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
        ];

        $campaign = $donation->campaign;
        if ($campaign === null) {
            return ['donation.confirmed', $context];
        }

        $campaignTemplateExists = NotificationTemplate::query()
            ->where('key', 'donation.campaign.confirmed')
            ->exists();

        if (! $campaignTemplateExists) {
            return ['donation.confirmed', $context];
        }

        $campaignUrl = '';
        try {
            if (filled($campaign->slug)) {
                $campaignUrl = route('projects.show', $campaign->slug);
            }
        } catch (\Throwable $e) {
            // Route missing / URL generation failed — a blank link is
            // never a reason to drop a confirmation message.
            Log::warning('PaymentCapture: campaign URL generation failed', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['donation.campaign.confirmed', $context + [
            'amount_formatted' => inr_money($donation->amount),
            'campaign_url' => $campaignUrl,
            'campaign_raised' => inr_money($campaign->raised_amount),
            'campaign_goal' => inr_money($campaign->goal_amount),
        ]];
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

    /**
     * Is this booking's greeting card due at capture time?
     *
     * True only when the seva happens today or has already happened. A future
     * seva is carded by `seva:send-day-of-cards` on the morning of the day
     * instead. Kept as one method so both capture paths (Razorpay and the free
     * / test-mode web confirm) apply the identical rule.
     *
     * A missing booking_date is treated as due now rather than never — losing
     * the card entirely would be worse than sending it early.
     */
    public function sevaCardIsDueNow(SevaBooking $booking): bool
    {
        if ($booking->booking_date === null) {
            return true;
        }

        return $booking->booking_date->startOfDay()->lte(now()->startOfDay());
    }
}
