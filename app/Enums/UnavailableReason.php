<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a date / slot cannot be booked.
 *
 * Item 4.1 (2026-08-09): availability endpoints stopped *omitting*
 * unbookable entries and started returning them flagged, so the web and
 * the app can render a "Not Available" badge instead of silently hiding
 * the chip. The string values are part of the public API surface
 * (`reason_code`) — treat them as append-only.
 *
 * The human-readable text always comes from the `availability` lang file
 * (resources/lang/{en,gu,hi}) so it honours X-Locale / the site locale.
 */
enum UnavailableReason: string
{
    /** Every slot / the day's capacity is taken. */
    case Full = 'full';

    /** Admin blackout date (seva slot_config or hall blackout_dates). */
    case Blackout = 'blackout';

    /** Weekday not offered (seva full_day_days / hall blackout_days). */
    case WeekdayClosed = 'weekday_closed';

    /** Outside the seva's acceptance period. */
    case OutsidePeriod = 'outside_period';

    /** No slots configured for that weekday. */
    case NoSlots = 'no_slots';

    /** The slot's start time is already in the past (today only). */
    case Elapsed = 'elapsed';

    /** Inside the admin-configured booking cut-off window (item 4.3). */
    case Cutoff = 'cutoff';

    /** A hall booking (possibly a multi-day range) already covers the date. */
    case HallBooked = 'hall_booked';

    /** Requested range exceeds the hall's max_booking_days (item 4.2). */
    case RangeTooLong = 'range_too_long';

    /** The requested date is before today. */
    case PastDate = 'past_date';

    /** Localized, devotee-facing label for this reason. */
    public function label(): string
    {
        return (string) __('availability.reason.'.$this->value);
    }

    /** The short badge text shown on a disabled chip. */
    public static function badge(): string
    {
        return (string) __('availability.not_available');
    }
}
