<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HallBooking;
use App\Models\HallReminderRule;
use App\Models\HallReminderSchedule;
use App\Support\DurationLabel;
use Illuminate\Support\Carbon;

/**
 * Turns a confirmed hall booking into pending reminder rows.
 *
 * Twin of SevaReminderScheduler. The moment reminders count back from is the
 * hall's own configured day-start time on the booking's FIRST day — reusing
 * Hall::dayStartMoment(), the same method the booking cut-off already counts
 * back from, rather than inventing a second answer to "when does this booking
 * begin". A multi-day booking anchors on booking_date; end_date is not used.
 */
class HallReminderScheduler
{
    /** @return int rows created */
    public function generateForBooking(HallBooking $booking): int
    {
        if ($booking->status !== 'confirmed') {
            return 0;
        }

        $booking->loadMissing('hall');
        if (! $booking->hall) {
            return 0;
        }

        $moment = $this->bookingMoment($booking);
        $created = 0;

        $rules = HallReminderRule::active()
            ->where('hall_id', $booking->hall_id)
            ->get();

        foreach ($rules as $rule) {
            $fireAt = $moment->copy()->subMinutes($rule->offset_minutes);

            // A reminder whose moment has already passed is never created —
            // otherwise confirming a booking for tomorrow would immediately
            // fire its "one week before" rule.
            if ($fireAt->lessThanOrEqualTo(now())) {
                continue;
            }

            // These three keys MUST match the unique index exactly. A mismatch
            // raises a 1062 inside PaymentCaptureService's transaction, which
            // on the seva side rolled back a live payment capture.
            $row = HallReminderSchedule::firstOrCreate(
                [
                    'hall_booking_id' => $booking->getKey(),
                    'rule_id' => $rule->getKey(),
                    'offset' => $rule->offset_minutes.'m',
                ],
                [
                    'fire_at' => $fireAt,
                    'status' => HallReminderSchedule::STATUS_PENDING,
                ],
            );

            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /** Pending rows for a booking that is no longer happening. */
    public function cancelPendingFor(HallBooking $booking): int
    {
        return HallReminderSchedule::where('hall_booking_id', $booking->getKey())
            ->where('status', HallReminderSchedule::STATUS_PENDING)
            ->update(['status' => HallReminderSchedule::STATUS_SKIPPED]);
    }

    /**
     * When the booking is considered to start.
     *
     * Halls have no slot times, so this is the hall's own `day_start_time`
     * (default 09:00) on the FIRST day of the range — exactly what
     * HallAvailabilityService already counts the booking cut-off back from.
     */
    public function bookingMoment(HallBooking $booking): Carbon
    {
        $booking->loadMissing('hall');

        $date = $booking->booking_date instanceof Carbon
            ? $booking->booking_date->toDateString()
            : (string) $booking->booking_date;

        return $booking->hall
            ? $booking->hall->dayStartMoment($date)
            : Carbon::parse($date)->setTime(9, 0);
    }

    /** "2 days", "3 hours" — for the admin repeater's row label. */
    /**
     * Offset as a readable phrase. Defaults to English for the admin UI;
     * the reminder dispatcher passes the recipient's language so
     * {{ time_remaining_label }} matches the body it lands in.
     */
    public static function humanLabel(int $minutes, string $locale = 'en'): string
    {
        return DurationLabel::make($minutes, $locale);
    }
}
