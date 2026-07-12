<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SevaBooking;
use App\Models\SevaReminderSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Turns a booking's per-seva reminder_offsets list (eg ["12h","6h"]) into
 * concrete SevaReminderSchedule rows — one per offset, each stamped with
 * the absolute moment it should fire.
 *
 * Called from SevaBookingObserver when a booking becomes confirmed, and
 * from the `seva:backfill-reminder-schedule` command for bookings that
 * predate this system. Generation is idempotent (firstOrCreate on the
 * unique booking+offset key), so re-running is always safe.
 */
class SevaReminderScheduler
{
    /**
     * Materialise the pending reminder rows for a confirmed booking.
     * Returns the number of NEW rows created.
     *
     * Offsets whose fire moment is already in the past are skipped — a
     * "12h before" reminder for a booking made 3h before the seva has no
     * meaning, so we don't create a row that would fire immediately.
     */
    public function generateForBooking(SevaBooking $booking): int
    {
        $statusValue = $booking->status instanceof \BackedEnum
            ? $booking->status->value
            : (string) $booking->status;
        if ($statusValue !== 'confirmed') {
            return 0;
        }

        $seva = $booking->seva ?? $booking->seva()->first();
        if (! $seva) {
            return 0;
        }

        $moment = $this->bookingMoment($booking);
        $now = Carbon::now();
        $created = 0;

        // ── Rule-based reminders (the current system) ──────────────────
        // Precedence: the seva's own rules win; otherwise any global
        // (seva_id NULL) rules apply; otherwise the legacy offsets below.
        // Rules are managed on the Edit Seva page ("Reminders" section).
        $rules = \App\Models\SevaReminderRule::query()
            ->active()
            ->where('seva_id', $seva->getKey())
            ->get();

        if ($rules->isEmpty()) {
            $rules = \App\Models\SevaReminderRule::query()
                ->active()
                ->whereNull('seva_id')
                ->get();
        }

        foreach ($rules as $rule) {
            $fireAt = $moment->copy()->subMinutes($rule->offset_minutes);
            if ($fireAt->lessThanOrEqualTo($now)) {
                continue; // reminder point already passed at confirmation
            }

            $row = SevaReminderSchedule::firstOrCreate(
                ['seva_booking_id' => $booking->getKey(), 'rule_id' => $rule->getKey()],
                [
                    'offset' => $rule->offset_minutes . 'm',
                    'fire_at' => $fireAt,
                    'status' => SevaReminderSchedule::STATUS_PENDING,
                ],
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        // ── Legacy offsets fallback ────────────────────────────────────
        // Sevas configured before rules existed keep working: when NO rule
        // matched (none defined yet), fall back to reminder_offsets +
        // template-driven dispatch, exactly as before.
        if ($rules->isEmpty()) {
            $offsets = $seva->reminder_offsets;
            if (is_array($offsets)) {
                foreach ($offsets as $offset) {
                    $offsetMinutes = self::parseOffset(is_string($offset) ? $offset : null);
                    if ($offsetMinutes === null) {
                        continue;
                    }

                    $fireAt = $moment->copy()->subMinutes($offsetMinutes);
                    if ($fireAt->lessThanOrEqualTo($now)) {
                        continue;
                    }

                    $row = SevaReminderSchedule::firstOrCreate(
                        ['seva_booking_id' => $booking->getKey(), 'offset' => (string) $offset, 'rule_id' => null],
                        ['fire_at' => $fireAt, 'status' => SevaReminderSchedule::STATUS_PENDING],
                    );
                    if ($row->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }
        }

        if ($created > 0) {
            Log::info('SevaReminderScheduler: generated reminder rows', [
                'booking_id' => $booking->getKey(),
                'created' => $created,
            ]);
        }

        return $created;
    }

    /**
     * Cancel any still-pending reminders for a booking that is no longer
     * going ahead (cancelled / refunded). The cron also re-checks booking
     * status at send time, so this is a tidy-up, not the only guard.
     */
    public function cancelPendingFor(SevaBooking $booking): int
    {
        return SevaReminderSchedule::query()
            ->where('seva_booking_id', $booking->getKey())
            ->where('status', SevaReminderSchedule::STATUS_PENDING)
            ->update(['status' => SevaReminderSchedule::STATUS_SKIPPED]);
    }

    /**
     * The actual seva moment — booking_date combined with slot_time when
     * present, otherwise the start of the booking day. Reminders count
     * back from this. (Mirrors the original DispatchSevaReminders logic.)
     */
    public function bookingMoment(SevaBooking $booking): Carbon
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
     * for malformed input so the caller skips rather than misfiring.
     */
    public static function parseOffset(?string $offset): ?int
    {
        if ($offset === null || $offset === '') {
            return null;
        }
        if (! preg_match('/^(\d+)\s*([mhd])$/i', trim($offset), $m)) {
            return null;
        }

        $value = (int) $m[1];
        return match (strtolower($m[2])) {
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 60 * 24,
            default => null,
        };
    }

    /**
     * Render an offset as a human label, eg "24 hours" or "3 days".
     */
    public static function humanLabel(int $minutes): string
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
