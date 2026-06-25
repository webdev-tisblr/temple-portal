<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SevaReminderSchedule;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Services\SevaReminderScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fires reminder notifications for confirmed seva bookings.
 *
 * Reminders are now PRE-COMPUTED: when a booking is confirmed,
 * SevaReminderScheduler (via SevaBookingObserver) writes one
 * SevaReminderSchedule row per offset, stamped with the exact moment it
 * should fire. This command just drains the due ones — it asks "which
 * pending rows have a fire_at in the past?" and dispatches them.
 *
 * Why this replaced the old scan-every-booking-every-tick approach:
 *   • RELIABILITY — the old version only sent a reminder if a cron tick
 *     happened to run during the 5-minute window the fire moment fell in.
 *     A missed tick (deploy / server blip / over-long previous run) lost
 *     the reminder forever. Now a row whose fire_at passed while the cron
 *     was down is still `pending`, so it goes out on the next run — late,
 *     but delivered. The --max-late-minutes guard drops reminders so
 *     stale they'd only confuse.
 *   • SCALABILITY — the query hits only due rows via the
 *     (status, fire_at) index instead of loading every upcoming booking.
 *
 * The trigger + audience side is unchanged: one dispatch of
 * `seva.booking.reminder` per due row; NotificationService fans out to
 * every enabled template (devotee + admin-role audiences) and the
 * admin_role strategy expands per role-holder inside the service. The
 * idempotency key `seva-reminder:{booking}:{offset}` remains the
 * belt-and-braces dedup at the NotificationLog layer.
 */
class DispatchSevaReminders extends Command
{
    protected $signature = 'seva:dispatch-reminders
        {--max-late-minutes=720 : Skip (don\'t send) reminders whose fire_at is older than this — too stale to be useful. Default 12h.}
        {--limit=500 : Maximum reminders to dispatch in a single run.}';

    protected $description = 'Dispatch seva.booking.reminder for due rows in the pre-computed reminder schedule';

    public function handle(NotificationService $notifier, SevaReminderScheduler $scheduler): int
    {
        $now = Carbon::now();
        $maxLate = max(1, (int) $this->option('max-late-minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $staleCutoff = $now->copy()->subMinutes($maxLate);

        $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');

        $due = SevaReminderSchedule::query()
            ->where('status', SevaReminderSchedule::STATUS_PENDING)
            ->where('fire_at', '<=', $now)
            ->with(['booking.devotee', 'booking.seva'])
            ->orderBy('fire_at')
            ->limit($limit)
            ->get();

        $stats = ['due' => $due->count(), 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($due as $row) {
            try {
                $booking = $row->booking;

                // Booking gone or no longer going ahead → don't remind.
                $statusValue = $booking?->status instanceof \BackedEnum
                    ? $booking->status->value
                    : (string) ($booking?->status ?? '');
                if ($booking === null || $statusValue !== 'confirmed') {
                    $row->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
                    $stats['skipped']++;
                    continue;
                }

                // Too stale to be useful, or the seva moment has already
                // passed — skip rather than send a pointless late reminder.
                $moment = $scheduler->bookingMoment($booking);
                if ($row->fire_at->lessThan($staleCutoff) || $moment->lessThanOrEqualTo($now)) {
                    $row->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
                    $stats['skipped']++;
                    continue;
                }

                $offsetMinutes = SevaReminderScheduler::parseOffset($row->offset) ?? 0;

                $notifier->dispatch(
                    'seva.booking.reminder',
                    [
                        'booking' => $booking,
                        'devotee' => $booking->devotee,
                        'hours_remaining' => max(0, (int) round($offsetMinutes / 60)),
                        'time_remaining_label' => SevaReminderScheduler::humanLabel($offsetMinutes),
                        'trust_name' => $trustName,
                    ],
                    idempotencyKey: "seva-reminder:{$booking->getKey()}:{$row->offset}",
                );

                $row->update([
                    'status' => SevaReminderSchedule::STATUS_SENT,
                    'sent_at' => $now,
                ]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $row->update(['status' => SevaReminderSchedule::STATUS_FAILED]);
                $stats['failed']++;
                Log::error('seva:dispatch-reminders row failed', [
                    'schedule_id' => $row->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info(sprintf(
            'Seva reminders — due=%d sent=%d skipped=%d failed=%d',
            $stats['due'], $stats['sent'], $stats['skipped'], $stats['failed'],
        ));
        Log::info('seva:dispatch-reminders', $stats);

        return self::SUCCESS;
    }
}
