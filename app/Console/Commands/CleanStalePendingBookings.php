<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HallBooking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SevaBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily-by-default sweep that cancels pending orders / bookings whose
 * Razorpay payment window has lapsed.
 *
 * Why this exists:
 *   • A user starts a seva booking / hall booking / store order →
 *     server creates the Order + Payment with `pending` / `created`
 *     status and returns the Razorpay key.
 *   • User closes the Razorpay popup, loses signal, navigates away,
 *     etc. Payment never captures → the row sits as a ghost forever.
 *
 * What's NOT here: stock restoration. None of the three flows
 * decrements stock at order-creation anymore — PaymentCaptureService
 * does it only on actual payment capture. So a stale `pending` row
 * has zero stock movement to undo. We just flip statuses.
 *
 * Scheduled every 5 minutes via routes/console.php with
 * withoutOverlapping() so concurrent runs don't double-cancel.
 */
class CleanStalePendingBookings extends Command
{
    protected $signature = 'bookings:clean-stale {--minutes=30 : Minutes after which pending orders / bookings are cancelled}';

    protected $description = 'Cancel pending seva bookings, hall bookings, and store orders whose Razorpay payment window has lapsed';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);
        $totalCancelled = 0;

        $totalCancelled += $this->cleanSevaBookings($cutoff, $minutes);
        $totalCancelled += $this->cleanHallBookings($cutoff, $minutes);
        $totalCancelled += $this->cleanOrders($cutoff, $minutes);

        if ($totalCancelled === 0) {
            $this->info('No stale pending records found.');
            return self::SUCCESS;
        }

        $this->info("Cancelled {$totalCancelled} stale pending record(s) total.");
        Log::info('Stale-cleanup ran', ['minutes' => $minutes, 'cancelled' => $totalCancelled]);
        return self::SUCCESS;
    }

    private function cleanSevaBookings(\Illuminate\Support\Carbon $cutoff, int $minutes): int
    {
        $stale = SevaBooking::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $booking) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => "Payment not completed within {$minutes} minutes",
            ]);
            $this->failPayment($booking->payment_id);
        }

        if ($stale->isNotEmpty()) {
            $this->info("  • Seva bookings cancelled: {$stale->count()}");
        }
        return $stale->count();
    }

    private function cleanHallBookings(\Illuminate\Support\Carbon $cutoff, int $minutes): int
    {
        $stale = HallBooking::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $booking) {
            $booking->update(['status' => 'cancelled']);
            $this->failPayment($booking->payment_id);
        }

        if ($stale->isNotEmpty()) {
            $this->info("  • Hall bookings cancelled: {$stale->count()}");
        }
        return $stale->count();
    }

    private function cleanOrders(\Illuminate\Support\Carbon $cutoff, int $minutes): int
    {
        $stale = Order::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $order) {
            $order->update(['status' => 'cancelled']);
            $this->failPayment($order->payment_id);
        }

        if ($stale->isNotEmpty()) {
            $this->info("  • Store orders cancelled: {$stale->count()}");
        }
        return $stale->count();
    }

    /**
     * Flip the related payment row to 'failed' if it's still sitting
     * in 'created'. Captured / failed payments are left alone — those
     * states are terminal and shouldn't be overwritten by a sweep.
     */
    private function failPayment(?string $paymentId): void
    {
        if ($paymentId === null) return;
        Payment::where('id', $paymentId)
            ->where('status', 'created')
            ->update(['status' => 'failed']);
    }
}
