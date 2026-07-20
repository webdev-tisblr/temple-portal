<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SevaReminderSchedule;
use App\Services\SevaReminderScheduler;
use App\Services\SevaSlotService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-shot (safe-to-repeat) migration helper for the full-day reminder
 * anchor change. Reminder rows generated BEFORE that change stamped their
 * fire_at counting back from midnight (00:00) of the booking day; the new
 * logic anchors full-day / full-week sevas to a real time (default 09:00).
 *
 * This walks every still-pending reminder for an upcoming full-day /
 * full-week booking and rewrites fire_at in place using the current
 * bookingMoment() anchor. Rows for time-slotted sevas are untouched (their
 * anchor never changed). Idempotent — re-running just recomputes the same
 * values. Use --dry-run to preview.
 */
class RestampFullDayReminders extends Command
{
    protected $signature = 'seva:restamp-fullday-reminders {--dry-run : Show what would change without writing}';

    protected $description = 'Recompute fire_at for pending full-day/full-week seva reminders using the new anchor time';

    public function handle(SevaReminderScheduler $scheduler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::now()->toDateString();
        $sentinels = [SevaSlotService::SLOT_TYPE_FULL_DAY, SevaSlotService::SLOT_TYPE_FULL_WEEK];

        $examined = 0;
        $changed = 0;

        SevaReminderSchedule::query()
            ->where('status', SevaReminderSchedule::STATUS_PENDING)
            ->whereHas('booking', function ($q) use ($sentinels, $today) {
                $q->where('status', 'confirmed')
                    ->whereIn('slot_time', $sentinels)
                    ->where('booking_date', '>=', $today);
            })
            ->with(['booking.seva'])
            ->chunkById(200, function ($rows) use ($scheduler, $dryRun, &$examined, &$changed) {
                foreach ($rows as $row) {
                    $examined++;

                    $booking = $row->booking;
                    if ($booking === null) {
                        continue;
                    }

                    $offsetMinutes = SevaReminderScheduler::parseOffset($row->offset);
                    if ($offsetMinutes === null) {
                        continue; // malformed offset — leave untouched
                    }

                    $newFireAt = $scheduler->bookingMoment($booking)->subMinutes($offsetMinutes);
                    $oldFireAt = $row->fire_at;

                    if ($oldFireAt !== null && $oldFireAt->equalTo($newFireAt)) {
                        continue; // already correct
                    }

                    $this->line(sprintf(
                        '  booking %s [%s]  %s → %s',
                        $booking->getKey(),
                        $row->offset,
                        $oldFireAt?->toDateTimeString() ?? 'null',
                        $newFireAt->toDateTimeString(),
                    ));

                    if (! $dryRun) {
                        $row->update(['fire_at' => $newFireAt]);
                    }
                    $changed++;
                }
            });

        $verb = $dryRun ? 'would be re-stamped' : 're-stamped';
        $this->info("Examined {$examined} pending full-day/full-week reminder(s); {$changed} {$verb}.");

        if ($dryRun && $changed > 0) {
            $this->comment('Dry run — re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
