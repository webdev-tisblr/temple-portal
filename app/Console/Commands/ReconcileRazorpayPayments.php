<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Razorpay what actually happened to payments we still think are
 * unpaid, and captures the ones the money arrived for.
 *
 * Why this exists (found 2026-09-18, a ₹1,100 donation):
 *   A capture normally reaches us two ways — the client calls
 *   /payments/verify (or the web success callback) when Razorpay hands
 *   control back, and Razorpay POSTs a `payment.captured` webhook. A UPI
 *   donor who approves in their UPI app and never returns to the browser
 *   skips the first; a rejected / undelivered webhook skips the second.
 *   When both miss, the money is in the bank but the Payment row sits at
 *   'created', bookings:clean-stale flips it to 'failed' after 30 minutes,
 *   the admin lists (captured-only) never show it, no receipt or
 *   notification goes out, and bookings:prune-abandoned deletes the row a
 *   week later. Nothing errors, so Sentry stays silent.
 *
 * This is the third, pull-based path: it never depends on anyone calling
 * us. Everything still funnels through PaymentCaptureService::markCaptured,
 * so receipts, stock, confirmations and notifications fire exactly as they
 * would have from the webhook, and a payment already captured is a no-op.
 */
class ReconcileRazorpayPayments extends Command
{
    protected $signature = 'payments:reconcile
        {--order= : Reconcile this one razorpay_order_id, whatever its age}
        {--minutes=10 : Ignore payments younger than this (checkout may still be open)}
        {--hours=24 : Ignore payments older than this}
        {--limit=200 : Most payments to ask Razorpay about in one run}
        {--dry-run : Report what would be captured without changing anything}';

    protected $description = 'Capture payments Razorpay took but neither /payments/verify nor the webhook told us about';

    public function handle(RazorpayService $razorpay, PaymentCaptureService $captureService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Payment::query()
            ->whereIn('status', ['created', 'failed'])
            ->where('razorpay_order_id', 'like', 'order\_%');

        if ($order = $this->option('order')) {
            $query->where('razorpay_order_id', $order);
        } else {
            $query->whereBetween('created_at', [
                now()->subHours((int) $this->option('hours')),
                now()->subMinutes((int) $this->option('minutes')),
            ])->orderByDesc('created_at')->limit((int) $this->option('limit'));
        }

        $payments = $query->get();

        if ($payments->isEmpty()) {
            $this->info('No unpaid payments to reconcile.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $errors = 0;

        foreach ($payments as $payment) {
            try {
                $attempts = $razorpay->fetchOrderPayments($payment->razorpay_order_id);
            } catch (Throwable $e) {
                // One bad order (or a Razorpay blip) must not abort the
                // sweep — the next run asks again.
                $errors++;
                Log::warning('payments:reconcile: Razorpay fetch failed', [
                    'payment_id' => $payment->id,
                    'razorpay_order_id' => $payment->razorpay_order_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $paid = collect($attempts)->firstWhere('status', 'captured');
            if ($paid === null) {
                continue;
            }

            // Same tamper rule as the webhook and /payments/verify: never
            // issue a receipt / confirm a booking for a different amount.
            $paidPaise = (int) ($paid['amount'] ?? 0);
            $expectedPaise = (int) round(((float) $payment->amount) * 100);
            if ($paidPaise !== $expectedPaise) {
                $errors++;
                Log::critical('payments:reconcile: amount mismatch — capture withheld for review', [
                    'payment_id' => $payment->id,
                    'razorpay_order_id' => $payment->razorpay_order_id,
                    'expected_paise' => $expectedPaise,
                    'paid_paise' => $paidPaise,
                ]);
                $this->error("  ✗ {$payment->razorpay_order_id}: amount mismatch, left alone");

                continue;
            }

            $this->line("  • {$payment->razorpay_order_id} → {$paid['id']} ₹{$payment->amount}".($dryRun ? ' (dry run)' : ''));

            if ($dryRun) {
                $recovered++;

                continue;
            }

            try {
                $captureService->markCaptured(
                    $payment,
                    $paid['id'],
                    $paid['method'] ?? null,
                    null,
                    // The moment the donor actually paid, not the moment we
                    // noticed — it decides the receipt date and financial year.
                    // App timezone explicitly: createFromTimestamp() is UTC,
                    // which Eloquent would store as a wall clock 5h30 early.
                    isset($paid['created_at'])
                        ? Carbon::createFromTimestamp((int) $paid['created_at'], config('app.timezone'))
                        : null,
                );
                $recovered++;

                // error level on purpose: a recovery means BOTH primary paths
                // missed, which someone should look at (webhook secret,
                // Cloudflare rule, Razorpay dashboard webhook disabled).
                Log::error('payments:reconcile: recovered a payment verify + webhook both missed', [
                    'payment_id' => $payment->id,
                    'razorpay_order_id' => $payment->razorpay_order_id,
                    'razorpay_payment_id' => $paid['id'],
                    'amount' => (string) $payment->amount,
                ]);
            } catch (Throwable $e) {
                $errors++;
                Log::error('payments:reconcile: capture failed', [
                    'payment_id' => $payment->id,
                    'razorpay_order_id' => $payment->razorpay_order_id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ {$payment->razorpay_order_id}: {$e->getMessage()}");
            }
        }

        $verb = $dryRun ? 'Would recover' : 'Recovered';
        $this->info("{$verb} {$recovered} of {$payments->count()} unpaid payment(s) checked; {$errors} error(s).");

        return self::SUCCESS;
    }
}
