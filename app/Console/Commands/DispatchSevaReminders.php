<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Models\NotificationLog;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fires reminder notifications for confirmed seva bookings based on the
 * per-seva `reminders` array. Designed to run on a schedule (default
 * every 30 minutes — see routes/console.php).
 *
 * Reminder shape (stored as JSON on temple_sevas.reminders):
 *
 *   [
 *     {"offset": "24h", "recipients": ["devotee", "staff"]},
 *     {"offset": "3h",  "recipients": ["devotee"]}
 *   ]
 *
 * Algorithm:
 *   1. Find sevas that have a non-empty reminders array.
 *   2. For each, find confirmed bookings whose booking_date is in the
 *      future. (Cancelled / completed / refunded are ignored.)
 *   3. For each (booking × reminder entry), compute fire_at =
 *      booking_date - offset. If fire_at falls inside the current
 *      dispatch window (now - $window, now], dispatch to each recipient
 *      in the reminder's recipients array.
 *   4. Idempotency key per (booking, offset, recipient) so cron runs
 *      that overlap windows can't double-send. (Cron re-runs are also
 *      no-ops because the temple_notification_logs row with the
 *      key already exists.)
 *
 * Window sizing rule of thumb: the window MUST be >= the cron cadence,
 * or some reminders will fall through the cracks. Default cadence is
 * 30 minutes, so we use a 35-minute window (5 min slack for clock
 * drift / staggered execution).
 */
class DispatchSevaReminders extends Command
{
    protected $signature = 'seva:dispatch-reminders {--window=35 : Window minutes — bookings whose fire_at falls in (now-window, now] get reminded}';

    protected $description = 'Dispatch reminder notifications for confirmed seva bookings based on per-seva reminder offsets';

    public function handle(NotificationService $notifier): int
    {
        $windowMinutes = max(1, (int) $this->option('window'));
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes($windowMinutes);

        $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');

        // Cache pujari-role admins once per run — query is the same for every booking.
        $pujariAdmins = AdminUser::role('pujari')->where('is_active', true)->get();

        $stats = ['considered' => 0, 'dispatched' => 0, 'skipped_duplicate' => 0];

        $sevas = Seva::query()
            ->whereNotNull('reminders')
            ->get()
            ->filter(fn (Seva $s) => is_array($s->reminders) && count($s->reminders) > 0);

        foreach ($sevas as $seva) {
            $bookings = SevaBooking::query()
                ->where('seva_id', $seva->id)
                ->where('status', 'confirmed')
                ->where('booking_date', '>=', $now->toDateString())
                ->with('devotee', 'seva')
                ->get();

            foreach ($bookings as $booking) {
                foreach ($seva->reminders as $reminder) {
                    $stats['considered']++;

                    $offsetMinutes = self::parseOffset($reminder['offset'] ?? null);
                    if ($offsetMinutes === null) continue;

                    $fireAt = $this->bookingMoment($booking)->copy()->subMinutes($offsetMinutes);

                    if ($fireAt->lessThanOrEqualTo($windowStart) || $fireAt->greaterThan($now)) {
                        continue;
                    }

                    $recipients = (array) ($reminder['recipients'] ?? []);
                    $hoursRemaining = max(0, (int) round($offsetMinutes / 60));
                    $timeRemainingLabel = self::humanLabel($offsetMinutes);

                    foreach ($recipients as $recipient) {
                        $idempotencyKey = "seva-reminder:{$booking->id}:{$reminder['offset']}:{$recipient}";

                        // Pre-check log to avoid even doing the dispatch
                        // work when the row already exists (cheap optimisation;
                        // NotificationService also catches duplicates internally
                        // via its 5-min window but our window is longer).
                        $alreadySent = NotificationLog::where('idempotency_key', $idempotencyKey)
                            ->where('status', '!=', NotificationLog::STATUS_SKIPPED)
                            ->exists();
                        if ($alreadySent) {
                            $stats['skipped_duplicate']++;
                            continue;
                        }

                        if ($recipient === 'devotee') {
                            $notifier->dispatch(
                                'seva.booking.reminder.devotee',
                                [
                                    'booking' => $booking,
                                    'devotee' => $booking->devotee,
                                    'hours_remaining' => $hoursRemaining,
                                    'time_remaining_label' => $timeRemainingLabel,
                                    'trust_name' => $trustName,
                                ],
                                idempotencyKey: $idempotencyKey,
                            );
                            $stats['dispatched']++;
                        } elseif ($recipient === 'staff') {
                            // Fan-out across all pujari admins, just like
                            // seva.booking.staff_alert. Use admin id in the
                            // idempotency key so each admin gets one nudge.
                            foreach ($pujariAdmins as $admin) {
                                $perAdminKey = "{$idempotencyKey}:{$admin->getKey()}";
                                $notifier->dispatch(
                                    'seva.booking.reminder.staff',
                                    [
                                        'booking' => $booking,
                                        'devotee' => $booking->devotee,
                                        'admin' => $admin,
                                        'hours_remaining' => $hoursRemaining,
                                        'time_remaining_label' => $timeRemainingLabel,
                                        'trust_name' => $trustName,
                                    ],
                                    idempotencyKey: $perAdminKey,
                                );
                                $stats['dispatched']++;
                            }
                        }
                    }
                }
            }
        }

        $this->info(sprintf(
            'Seva reminders run complete — considered=%d dispatched=%d skipped_duplicate=%d window=%dmin',
            $stats['considered'],
            $stats['dispatched'],
            $stats['skipped_duplicate'],
            $windowMinutes,
        ));

        Log::info('seva:dispatch-reminders', $stats + ['window_minutes' => $windowMinutes]);

        return self::SUCCESS;
    }

    /**
     * Build the actual seva moment as a Carbon — combines booking_date
     * with slot_time when present, otherwise treats the date as the
     * start of day. This is what we count back from for each reminder.
     */
    private function bookingMoment(SevaBooking $booking): Carbon
    {
        $date = $booking->booking_date instanceof Carbon
            ? $booking->booking_date->copy()->startOfDay()
            : Carbon::parse((string) $booking->booking_date)->startOfDay();

        $slot = $booking->slot_time;
        if (is_string($slot) && preg_match('/^(\d{1,2}):(\d{2})/', $slot, $m)) {
            $date->setTime((int) $m[1], (int) $m[2]);
        }

        return $date;
    }

    /**
     * Parse a reminder offset string ("3h", "24h", "168h", "30m", "7d")
     * into minutes. Returns null for malformed input — the caller skips
     * the entry rather than dispatching at the wrong time.
     */
    private static function parseOffset(?string $offset): ?int
    {
        if ($offset === null || $offset === '') return null;
        if (! preg_match('/^(\d+)\s*([mhd])$/i', trim($offset), $m)) return null;

        $value = (int) $m[1];
        return match (strtolower($m[2])) {
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 60 * 24,
            default => null,
        };
    }

    /**
     * Render an offset in minutes back into a human label for templates
     * — '{{ time_remaining_label }}' renders as e.g. "24 hours" or "3 days".
     */
    private static function humanLabel(int $minutes): string
    {
        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);
            return $days === 1 ? '1 day' : "{$days} days";
        }
        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }
        return "{$minutes} minutes";
    }
}
