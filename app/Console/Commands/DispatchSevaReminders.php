<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Fires reminder notifications for confirmed seva bookings based on
 * the per-seva `reminder_offsets` list. Runs on a 30-minute cron tick
 * (see routes/console.php).
 *
 * Offsets are a flat list of strings, eg:
 *   ["168h", "72h", "24h", "12h", "3h"]
 *
 * For each (booking × offset), the command dispatches the trigger key
 * `seva.booking.reminder` ONCE. NotificationService then fires every
 * enabled template for that key — admin creates one template per
 * audience (devotee, admin role, etc.) and each one decides its own
 * recipient + channel + body. The admin_role strategy fans out across
 * role-holders automatically at the service level.
 *
 * Idempotency: NotificationLog stores `seva-reminder:{booking_id}:{offset}`
 * (plus per-channel and per-admin suffixes added downstream) — cron
 * re-runs are no-ops because the log row already exists.
 *
 * Window sizing: must be >= cron cadence or some reminders fall through
 * the cracks. Default window is 10 min for a 5-min cadence (5 min slack
 * for clock drift / staggered runs). Idempotency at NotificationService
 * dedups any overlap inside that window.
 */
class DispatchSevaReminders extends Command
{
    protected $signature = 'seva:dispatch-reminders {--window=10 : Window minutes — bookings whose fire_at falls in (now-window, now] get reminded}';

    protected $description = 'Dispatch seva.booking.reminder for confirmed bookings based on per-seva reminder_offsets';

    public function handle(NotificationService $notifier): int
    {
        $windowMinutes = max(1, (int) $this->option('window'));
        $now = Carbon::now();
        $windowStart = $now->copy()->subMinutes($windowMinutes);

        $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');

        $stats = ['considered' => 0, 'dispatched' => 0];

        $sevas = Seva::query()
            ->whereNotNull('reminder_offsets')
            ->get()
            ->filter(fn (Seva $s) => is_array($s->reminder_offsets) && count($s->reminder_offsets) > 0);

        foreach ($sevas as $seva) {
            $bookings = SevaBooking::query()
                ->where('seva_id', $seva->id)
                ->where('status', 'confirmed')
                ->where('booking_date', '>=', $now->toDateString())
                ->with('devotee', 'seva')
                ->get();

            foreach ($bookings as $booking) {
                foreach ($seva->reminder_offsets as $offset) {
                    $stats['considered']++;

                    $offsetMinutes = self::parseOffset($offset);
                    if ($offsetMinutes === null) continue;

                    $fireAt = $this->bookingMoment($booking)->copy()->subMinutes($offsetMinutes);

                    if ($fireAt->lessThanOrEqualTo($windowStart) || $fireAt->greaterThan($now)) {
                        continue;
                    }

                    $hoursRemaining = max(0, (int) round($offsetMinutes / 60));
                    $timeRemainingLabel = self::humanLabel($offsetMinutes);

                    // ONE dispatch per (booking × offset). NotificationService
                    // expands across every enabled template (devotee +
                    // admin-role audiences) and per-admin role fan-out
                    // happens inside the service.
                    $notifier->dispatch(
                        'seva.booking.reminder',
                        [
                            'booking' => $booking,
                            'devotee' => $booking->devotee,
                            'hours_remaining' => $hoursRemaining,
                            'time_remaining_label' => $timeRemainingLabel,
                            'trust_name' => $trustName,
                        ],
                        idempotencyKey: "seva-reminder:{$booking->id}:{$offset}",
                    );
                    $stats['dispatched']++;
                }
            }
        }

        $this->info(sprintf(
            'Seva reminders run complete — considered=%d dispatched=%d window=%dmin',
            $stats['considered'],
            $stats['dispatched'],
            $windowMinutes,
        ));

        Log::info('seva:dispatch-reminders', $stats + ['window_minutes' => $windowMinutes]);

        return self::SUCCESS;
    }

    /**
     * Build the actual seva moment as a Carbon — combines booking_date
     * with slot_time when present, otherwise treats the date as start
     * of day. Reminders count back from this moment.
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
     * "3h" → 180, "24h" → 1440, "7d" → 10080, "30m" → 30. Returns null
     * for malformed input — caller skips rather than misfiring.
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
     * Render an offset as "{{ time_remaining_label }}", eg "24 hours" or "3 days".
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
