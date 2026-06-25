<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SevaBooking;
use App\Services\SevaReminderScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-shot (and safe-to-repeat) backfill for bookings confirmed before the
 * pre-computed reminder schedule existed. Walks every confirmed upcoming
 * booking and materialises its reminder rows via SevaReminderScheduler.
 *
 * Generation is idempotent (firstOrCreate on booking+offset), so running
 * this multiple times never duplicates reminders. Run it once after the
 * migration deploys.
 */
class BackfillSevaReminderSchedule extends Command
{
    protected $signature = 'seva:backfill-reminder-schedule';

    protected $description = 'Create reminder schedule rows for already-confirmed upcoming seva bookings';

    public function handle(SevaReminderScheduler $scheduler): int
    {
        $today = Carbon::now()->toDateString();
        $bookingsProcessed = 0;
        $rowsCreated = 0;

        SevaBooking::query()
            ->where('status', 'confirmed')
            ->where('booking_date', '>=', $today)
            ->with('seva')
            ->chunkById(200, function ($bookings) use ($scheduler, &$bookingsProcessed, &$rowsCreated) {
                foreach ($bookings as $booking) {
                    $rowsCreated += $scheduler->generateForBooking($booking);
                    $bookingsProcessed++;
                }
            });

        $this->info("Backfill complete — {$bookingsProcessed} booking(s) processed, {$rowsCreated} reminder row(s) created.");

        return self::SUCCESS;
    }
}
