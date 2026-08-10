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
 *
 * ─────────────────────────────────────────────────────────────────────
 * HIDE vs BADGE — the one rule, defined once (see display()).
 *
 * There are two completely different reasons a date/slot is unbookable
 * and they must NOT be rendered the same way:
 *
 *  1. STRUCTURALLY NOT OFFERED — the seva only runs on Tuesdays, the
 *     date is outside the acceptance window, the admin blacked it out,
 *     the pool has no slots that weekday. These were never bookable;
 *     showing them as "Not Available" is pure noise. → DISPLAY_HIDE.
 *  2. OFFERED BUT TAKEN — a genuinely bookable date/slot that someone
 *     already booked, or that the cut-off buffer has just closed. The
 *     devotee needs to see it, struck through, so the calendar reads as
 *     a real calendar. → DISPLAY_BADGE.
 *
 * Web, app and the API all resolve the question through this enum, so
 * they can never disagree. The API additionally FILTERS hide-class
 * entries out of the payloads it emits (see UnavailableReason::visible),
 * which means even the shipped app build 1.4.8+32 — which cannot know
 * about `display` — gets the correct behaviour.
 */
enum UnavailableReason: string
{
    /** Never render this entry — it was never on offer. */
    public const DISPLAY_HIDE = 'hide';

    /** Render it, disabled, with the "Not Available" badge. */
    public const DISPLAY_BADGE = 'badge';

    /** Render it normally — bookable. */
    public const DISPLAY_AVAILABLE = 'available';

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

    /**
     * THE mapping. Every reason must make an explicit choice here — the
     * match is exhaustive on purpose, so adding a case without deciding
     * hide-vs-badge is a compile-time error rather than a silent regression.
     */
    public function display(): string
    {
        return match ($this) {
            // ── Structurally not offered → hide the entry entirely ──
            // The seva/hall does not run on this weekday at all.
            self::WeekdayClosed,
            // Admin closed the temple / hall for this specific date.
            self::Blackout,
            // Outside the seva's acceptance period.
            self::OutsidePeriod,
            // No slots are configured for this weekday.
            self::NoSlots,
            // Already in the past — it is not a booking opportunity.
            self::PastDate => self::DISPLAY_HIDE,

            // ── Offered, but not bookable right now → show + badge ──
            // Capacity taken by other devotees.
            self::Full,
            // A hall booking (possibly multi-day) covers the date.
            self::HallBooked,
            // The slot's start time has passed today.
            self::Elapsed,
            // Inside the admin's now+N hours cut-off window.
            self::Cutoff,
            // Request-level verdict, surfaced as an error message.
            self::RangeTooLong => self::DISPLAY_BADGE,
        };
    }

    /** True when this reason means "never show this entry". */
    public function hidesEntry(): bool
    {
        return $this->display() === self::DISPLAY_HIDE;
    }

    /** Tolerant lookup — unknown codes resolve to null, never throw. */
    public static function fromCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom($code);
    }

    /**
     * How a payload row should be rendered. A row with no reason_code is
     * bookable; an unknown code (newer server, older reader) is shown
     * with a badge rather than silently dropped.
     */
    public static function displayFor(?string $code): string
    {
        if ($code === null || $code === '') {
            return self::DISPLAY_AVAILABLE;
        }

        return self::fromCode($code)?->display() ?? self::DISPLAY_BADGE;
    }

    /**
     * Drop the hide-class rows and stamp every survivor with `display`.
     *
     * Called by every availability endpoint, so the payload a client
     * receives already contains exactly the entries it should render —
     * and each one says how. Clients never re-derive the rule.
     *
     * @param  list<array<string, mixed>>  $rows  rows carrying `reason_code`
     * @return list<array<string, mixed>>
     */
    public static function visible(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $display = self::displayFor(is_string($row['reason_code'] ?? null) ? $row['reason_code'] : null);
            if ($display === self::DISPLAY_HIDE) {
                continue;
            }
            $row['display'] = $display;
            $out[] = $row;
        }

        return $out;
    }
}
