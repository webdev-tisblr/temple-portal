<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SevaBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delete checkouts that were abandoned at the payment step.
 *
 * `bookings:clean-stale` cancels a pending booking/order 30 minutes after it
 * was started, and flips its never-captured payment to `failed`. That stops
 * the row counting toward anything, but it stays in the table — so the admin
 * lists fill up with ghost rows for money that was never taken. This prunes
 * them for real (2026-08-17).
 *
 * WHY THERE IS A RETENTION WINDOW, and why it must not be zero:
 *
 *   Razorpay can capture a payment AFTER our 30-minute cancel — a webhook
 *   retry, a delayed UPI collect, a bank that settles late. PaymentCaptureService
 *   handles that: it finds the row and confirms it. If the row has already
 *   been deleted, that late capture has nothing to attach to and the trust has
 *   taken money with no record of what for. The window has to outlast every
 *   realistic late capture, which is why the default is days and not minutes.
 *
 * WHAT IS NEVER TOUCHED — each is a hard guard, not a filter:
 *   • anything whose payment ever reached `captured`
 *   • a booking someone cancelled themselves after paying (its payment IS
 *     captured, so the rule above already excludes it)
 *   • a donation carrying an 80G receipt (statutory; also an FK)
 *   • a seva booking a donation points at (FK, and it means money moved)
 *   • anything still `pending` — clean-stale owns that transition
 */
class PruneAbandonedCheckouts extends Command
{
    protected $signature = 'bookings:prune-abandoned
        {--days=7 : Delete abandoned checkouts older than this many days}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete never-paid bookings, orders and donations abandoned at the payment step';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $stats = [];

        DB::transaction(function () use ($cutoff, $dry, &$stats): void {
            $stats['seva_bookings'] = $this->pruneSevaBookings($cutoff, $dry);
            $stats['hall_bookings'] = $this->pruneHallBookings($cutoff, $dry);
            $stats['orders'] = $this->pruneOrders($cutoff, $dry);
            $stats['donations'] = $this->pruneDonations($cutoff, $dry);
            // Payments last: the children above reference them with ON DELETE
            // NO ACTION, so a payment can only go once nothing points at it.
            $stats['payments'] = $this->pruneOrphanPayments($cutoff, $dry);
        });

        $total = array_sum($stats);

        foreach ($stats as $what => $count) {
            if ($count > 0) {
                $this->info(sprintf('  • %s %s: %d', $dry ? 'would delete' : 'deleted', $what, $count));
            }
        }

        if ($total === 0) {
            $this->info("No abandoned checkouts older than {$days} day(s).");

            return self::SUCCESS;
        }

        $this->info(($dry ? 'Would delete ' : 'Deleted ')."{$total} abandoned record(s).");

        if (! $dry) {
            Log::info('Abandoned-checkout prune ran', ['days' => $days] + $stats);
        }

        return self::SUCCESS;
    }

    private function pruneSevaBookings(Carbon $cutoff, bool $dry): int
    {
        $rows = SevaBooking::query()
            ->where('status', 'cancelled')
            ->where('created_at', '<', $cutoff)
            ->whereNull('receipt_number')
            ->where(fn ($q) => $q->whereNull('payment_id')
                ->orWhereHas('payment', fn ($p) => $p->where('status', '!=', 'captured')))
            // A donation pointing here means money moved through another path.
            // Raw NOT EXISTS rather than a relation: SevaBooking has no
            // donations() relation, and the FK is ON DELETE NO ACTION, so this
            // is also what stops the delete failing.
            ->whereNotExists(fn ($q) => $q->selectRaw(1)
                ->from('temple_donations')
                ->whereColumn('temple_donations.seva_booking_id', 'temple_seva_bookings.id'))
            ->get();

        if (! $dry) {
            // Individually, so the reminder-schedule CASCADE fires per row.
            $rows->each(fn (SevaBooking $b) => $b->delete());
        }

        return $rows->count();
    }

    private function pruneHallBookings(Carbon $cutoff, bool $dry): int
    {
        $rows = HallBooking::query()
            ->where('status', 'cancelled')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('payment_id')
                ->orWhereHas('payment', fn ($p) => $p->where('status', '!=', 'captured')))
            ->get();

        if (! $dry) {
            $rows->each(fn (HallBooking $b) => $b->delete());
        }

        return $rows->count();
    }

    private function pruneOrders(Carbon $cutoff, bool $dry): int
    {
        $rows = Order::query()
            ->where('status', 'cancelled')
            ->where('created_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('payment_id')
                ->orWhereHas('payment', fn ($p) => $p->where('status', '!=', 'captured')))
            ->get();

        if (! $dry) {
            // order_items cascade.
            $rows->each(fn (Order $o) => $o->delete());
        }

        return $rows->count();
    }

    /**
     * Donations have no status of their own — the payment is the lifecycle.
     * An abandoned one is a donation whose payment never captured.
     */
    private function pruneDonations(Carbon $cutoff, bool $dry): int
    {
        $rows = Donation::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('payment', fn ($p) => $p->where('status', '!=', 'captured'))
            // Statutory document; also an FK that would block the delete.
            ->whereDoesntHave('receipt')
            ->get();

        if (! $dry) {
            $rows->each(fn (Donation $d) => $d->delete());
        }

        return $rows->count();
    }

    /**
     * Payment rows left pointing at nothing once the above are gone. Captured
     * payments are never pruned, whatever they are attached to.
     */
    private function pruneOrphanPayments(Carbon $cutoff, bool $dry): int
    {
        $query = Payment::query()
            ->where('created_at', '<', $cutoff)
            ->where('status', '!=', 'captured')
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('temple_donations')->whereColumn('temple_donations.payment_id', 'temple_payments.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('temple_seva_bookings')->whereColumn('temple_seva_bookings.payment_id', 'temple_payments.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('temple_hall_bookings')->whereColumn('temple_hall_bookings.payment_id', 'temple_payments.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw(1)->from('temple_orders')->whereColumn('temple_orders.payment_id', 'temple_payments.id'));

        if ($dry) {
            return $query->count();
        }

        return $query->delete();
    }
}
