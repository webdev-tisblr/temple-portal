<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\SevaBooking;
use Illuminate\Console\Command;

class CleanStalePendingBookings extends Command
{
    protected $signature = 'bookings:clean-stale {--minutes=30 : Minutes after which pending bookings are cancelled}';

    protected $description = 'Cancel pending seva bookings and payments older than the specified time';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = now()->subMinutes($minutes);

        $staleBookings = SevaBooking::where('status', 'pending')
            ->where('created_at', '<', $cutoff)
            ->get();

        if ($staleBookings->isEmpty()) {
            $this->info('No stale pending bookings found.');
            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($staleBookings as $booking) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Payment not completed within ' . $minutes . ' minutes',
            ]);

            // Mark the associated payment as failed
            if ($booking->payment_id) {
                Payment::where('id', $booking->payment_id)
                    ->where('status', 'created')
                    ->update(['status' => 'failed']);
            }

            $cancelled++;
        }

        $this->info("Cancelled {$cancelled} stale pending booking(s).");

        return self::SUCCESS;
    }
}
