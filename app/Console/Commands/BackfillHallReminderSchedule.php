<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HallBooking;
use App\Services\HallReminderScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Materialises reminder rows for hall bookings that were already confirmed
 * before a rule existed — the bookings the observer never saw.
 *
 * Safe to repeat: generation is a firstOrCreate on the schedule table's
 * unique key, so re-running never duplicates a reminder. Runs on every
 * deploy alongside the seva backfill, which is also how a newly added rule
 * reaches bookings taken before it.
 */
class BackfillHallReminderSchedule extends Command
{
    protected $signature = 'hall:backfill-reminder-schedule';

    protected $description = 'Create reminder schedule rows for already-confirmed upcoming hall bookings';

    public function handle(HallReminderScheduler $scheduler): int
    {
        $today = Carbon::now()->toDateString();
        $bookingsProcessed = 0;
        $rowsCreated = 0;

        HallBooking::query()
            ->where('status', 'confirmed')
            ->where('booking_date', '>=', $today)
            ->with('hall')
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
