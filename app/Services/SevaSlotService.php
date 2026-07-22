<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use Carbon\Carbon;

class SevaSlotService
{
    /**
     * Slot modes. `time_slots` = the classic HH:MM time-slot system.
     * `full_day` = the whole day is the unit (no time slots); capacity is
     * counted per date. `full_week` = the whole ISO week is the unit;
     * capacity is counted across the Mon–Sun window the date falls in.
     * For the two "full" modes a booking stores its mode string in
     * `slot_time` as a sentinel (so it is never empty — the WhatsApp
     * template guard needs a non-empty slot_time).
     */
    public const SLOT_TYPE_TIME = 'time_slots';

    public const SLOT_TYPE_FULL_DAY = 'full_day';

    public const SLOT_TYPE_FULL_WEEK = 'full_week';

    /**
     * Fallback reminder anchor (HH:MM) for full-day / full-week sevas that
     * have no start time. Used when neither the per-seva config nor the
     * global system setting supplies one. See fullDayAnchorTime().
     */
    public const DEFAULT_FULLDAY_ANCHOR = '09:00';

    /**
     * Resolve the slot mode from a normalized config, defaulting safely.
     */
    public function slotType(array $config): string
    {
        $type = $config['slot_type'] ?? self::SLOT_TYPE_TIME;

        return in_array($type, [self::SLOT_TYPE_TIME, self::SLOT_TYPE_FULL_DAY, self::SLOT_TYPE_FULL_WEEK], true)
            ? $type
            : self::SLOT_TYPE_TIME;
    }

    /**
     * For full_day sevas: is the seva offered on this date's weekday?
     * `full_day_days` is an optional list of weekday names (monday..sunday) —
     * empty/absent means every day. Only applies to full_day mode.
     */
    public function fullDayAllowedOnDate(array $config, string $date): bool
    {
        if ($this->slotType($config) !== self::SLOT_TYPE_FULL_DAY) {
            return true;
        }

        $days = $config['full_day_days'] ?? [];
        if (empty($days) || ! is_array($days)) {
            return true; // no weekday restriction → available every day
        }

        $dayName = strtolower(Carbon::parse($date)->format('l'));

        // Toggle format {monday: false, tuesday: true, ...} — the enabled days
        // are the truthy keys. All-off means no restriction (every day).
        $isMap = array_keys($days) !== range(0, count($days) - 1);
        if ($isMap) {
            $enabled = array_keys(array_filter($days, fn ($v) => (bool) $v));
            if (empty($enabled)) {
                return true;
            }

            return in_array($dayName, array_map('strtolower', $enabled), true);
        }

        // Legacy list format ['tuesday', 'saturday'].
        return in_array($dayName, array_map('strtolower', $days), true);
    }

    /**
     * Reminder anchor for a full-day / full-week seva as [hour, minute].
     * These sevas have no start time, so reminders count back from this
     * moment on the booking date instead of from midnight. Resolution:
     *   1. per-seva slot_config['reminder_anchor_time'] (HH:MM), if valid
     *   2. global default system setting 'seva_fullday_reminder_anchor'
     *   3. hard fallback DEFAULT_FULLDAY_ANCHOR (09:00)
     *
     * @return array{0:int,1:int}
     */
    public function fullDayAnchorTime(array $config): array
    {
        $global = SystemSetting::getValue('seva_fullday_reminder_anchor', self::DEFAULT_FULLDAY_ANCHOR);

        $raw = $config['reminder_anchor_time'] ?? null;
        $value = (is_string($raw) && preg_match('/^\d{1,2}:\d{2}$/', $raw)) ? $raw : $global;

        // Guard against a malformed global setting too — never fall back to
        // an implicit midnight anchor.
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) $value, $m)) {
            preg_match('/^(\d{1,2}):(\d{2})$/', self::DEFAULT_FULLDAY_ANCHOR, $m);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Mon–Sun date range (Y-m-d) for the ISO week the given date is in.
     *
     * @return array{0:string,1:string}
     */
    private function weekRange(string $date): array
    {
        $d = Carbon::parse($date);

        return [
            $d->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            $d->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        ];
    }

    /**
     * Active-booking count for a full_day (per date) or full_week (per ISO
     * week) unit. `$lock` adds lockForUpdate() for the race-safe re-check.
     */
    private function countFullUnitBookings(Seva $seva, string $slotType, string $date, bool $lock = false): int
    {
        $q = SevaBooking::where('seva_id', $seva->id)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->where('slot_time', $slotType);

        if ($slotType === self::SLOT_TYPE_FULL_WEEK) {
            [$weekStart, $weekEnd] = $this->weekRange($date);
            $q->whereBetween('booking_date', [$weekStart, $weekEnd]);
        } else {
            $q->where('booking_date', $date);
        }

        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->count();
    }

    /**
     * Normalize v1 slot_config to v2 format.
     */
    public function normalizeConfig(?array $config): array
    {
        if (empty($config)) {
            return $this->emptyConfig();
        }

        // Already v2
        if (($config['version'] ?? null) === 2) {
            $config['slot_type'] = $this->slotType($config);

            return $config;
        }

        // v1 → v2 conversion
        return [
            'version' => 2,
            'slot_type' => self::SLOT_TYPE_TIME,
            'slot_duration_minutes' => 60,
            'max_bookings_per_slot' => 1,
            'acceptance_period' => [
                'type' => 'perpetual',
                'start_date' => null,
                'end_date' => null,
            ],
            'weekly_schedule' => [
                'default' => $config['time_slots'] ?? [],
                'monday' => null,
                'tuesday' => null,
                'wednesday' => null,
                'thursday' => null,
                'friday' => null,
                'saturday' => null,
                'sunday' => null,
            ],
            'blackout_dates' => [],
        ];
    }

    /**
     * Get the time slots applicable for a specific date.
     */
    public function getSlotsForDate(Seva $seva, string $date): array
    {
        $config = $this->normalizeConfig($seva->slot_config);

        if (! $this->isDateInAcceptancePeriod($config, $date)) {
            return [];
        }

        if ($this->getBlackoutReason($config, $date) !== null) {
            return [];
        }

        // Full-day / full-week sevas have a single synthetic "slot" (the
        // mode string) instead of HH:MM times — the day or week IS the slot.
        $slotType = $this->slotType($config);
        if ($slotType === self::SLOT_TYPE_FULL_DAY) {
            return $this->fullDayAllowedOnDate($config, $date) ? [self::SLOT_TYPE_FULL_DAY] : [];
        }
        if ($slotType === self::SLOT_TYPE_FULL_WEEK) {
            return [self::SLOT_TYPE_FULL_WEEK];
        }

        $dayName = strtolower(Carbon::parse($date)->format('l')); // monday, tuesday, etc.
        $schedule = $config['weekly_schedule'] ?? [];

        // Day-specific override (explicit array, even empty)
        if (array_key_exists($dayName, $schedule) && is_array($schedule[$dayName])) {
            $slots = $schedule[$dayName];
        } else {
            // Fall back to default
            $slots = $schedule['default'] ?? [];
        }

        // Normalize to HH:MM, sort
        $slots = array_map(fn ($t) => substr((string) $t, 0, 5), array_filter($slots));
        sort($slots);

        return array_values(array_unique($slots));
    }

    /**
     * Check if a date is within the seva's acceptance period.
     */
    public function isDateInAcceptancePeriod(array $config, string $date): bool
    {
        $period = $config['acceptance_period'] ?? ['type' => 'perpetual'];

        if (($period['type'] ?? 'perpetual') === 'perpetual') {
            return true;
        }

        $target = Carbon::parse($date);

        if (! empty($period['start_date']) && $target->lt(Carbon::parse($period['start_date']))) {
            return false;
        }

        if (! empty($period['end_date']) && $target->gt(Carbon::parse($period['end_date']))) {
            return false;
        }

        return true;
    }

    /**
     * Get the blackout reason for a date, or null if not blacked out.
     */
    public function getBlackoutReason(array $config, string $date): ?string
    {
        $blackouts = $config['blackout_dates'] ?? [];

        foreach ($blackouts as $entry) {
            if (($entry['date'] ?? '') === $date) {
                return $entry['reason'] ?? 'Seva unavailable on this date';
            }
        }

        return null;
    }

    /**
     * Get full slot availability for a seva on a date.
     * Returns: ['available' => [...], 'booked' => [...], 'blackout' => bool, 'blackout_reason' => ?string, 'message' => ?string]
     */
    public function getSlotAvailability(Seva $seva, string $date): array
    {
        $config = $this->normalizeConfig($seva->slot_config);

        // Check acceptance period
        if (! $this->isDateInAcceptancePeriod($config, $date)) {
            return [
                'available' => [],
                'booked' => [],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => 'This seva is not available for booking on this date.',
            ];
        }

        // Check blackout
        $blackoutReason = $this->getBlackoutReason($config, $date);
        if ($blackoutReason) {
            return [
                'available' => [],
                'booked' => [],
                'blackout' => true,
                'blackout_reason' => $blackoutReason,
                'message' => null,
            ];
        }

        // Full-day / full-week: one unit, counted per date or per ISO week.
        $slotType = $this->slotType($config);
        if ($slotType === self::SLOT_TYPE_FULL_DAY || $slotType === self::SLOT_TYPE_FULL_WEEK) {
            // full_day may be restricted to specific weekdays.
            if (! $this->fullDayAllowedOnDate($config, $date)) {
                return [
                    'available' => [],
                    'booked' => [],
                    'blackout' => false,
                    'blackout_reason' => null,
                    'message' => null,
                    'slot_type' => $slotType,
                ];
            }

            $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
            $isFull = $this->countFullUnitBookings($seva, $slotType, $date) >= $maxPerSlot;

            return [
                'available' => $isFull ? [] : [$slotType],
                'booked' => $isFull ? [$slotType] : [],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => null,
                'slot_type' => $slotType,
            ];
        }

        // Get day's slots
        $allSlots = $this->getSlotsForDate($seva, $date);

        // For *today*, drop slots whose start time has already passed
        // (e.g. it is 12:30 PM, slot 10:00 AM should not be shown).
        if ($this->isToday($date)) {
            $allSlots = $this->filterPastSlots($allSlots);
        }

        if (empty($allSlots)) {
            return [
                'available' => [],
                'booked' => [],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => null,
            ];
        }

        // Count bookings per slot
        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $bookingCounts = SevaBooking::where('seva_id', $seva->id)
            ->where('booking_date', $date)
            // `pending` holds the slot during the ~10-30s payment window
            // so two devotees can't race-book the same slot while one
            // is mid-Razorpay. PaymentCaptureService::markFailed flips
            // failed payments to `cancelled`, releasing the hold.
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->selectRaw('LEFT(slot_time, 5) as slot, COUNT(*) as cnt')
            ->groupBy('slot')
            ->pluck('cnt', 'slot')
            ->toArray();

        $available = [];
        $booked = [];
        foreach ($allSlots as $slot) {
            $count = $bookingCounts[$slot] ?? 0;
            if ($count >= $maxPerSlot) {
                $booked[] = $slot;
            } else {
                $available[] = $slot;
            }
        }

        return [
            'available' => $available,
            'booked' => $booked,
            'blackout' => false,
            'blackout_reason' => null,
            'message' => null,
        ];
    }

    /**
     * Return the dates within the next $days for which this seva has at
     * least one open slot (respects acceptance period, blackouts and
     * fully-booked slots).
     *
     * Used by the date carousel in the mobile app's seva detail screen
     * to hide non-bookable dates entirely. One bulk booking-count query
     * powers the whole window.
     *
     * @return list<string> Dates in 'Y-m-d' format, ascending.
     */
    public function getAvailableDates(Seva $seva, int $days = 30): array
    {
        $days = max(1, min($days, 90));
        $start = now()->startOfDay();

        return $this->getAvailableDatesInRange($seva, $start, $start->copy()->addDays($days - 1));
    }

    /**
     * Bookable dates for one calendar month ('YYYY-MM'), for the
     * Year → Month → dates picker. Past days of the current month are
     * dropped; months beyond the horizon return an empty list.
     */
    public function getAvailableDatesForMonth(Seva $seva, string $month): array
    {
        $monthStart = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();

        $start = $monthStart->copy();
        $today = now()->startOfDay();
        if ($start->lt($today)) {
            $start = $today->copy();
        }

        $end = $monthStart->copy()->endOfMonth()->startOfDay();
        if ($end->lt($start)) {
            return [];
        }

        return $this->getAvailableDatesInRange($seva, $start, $end);
    }

    /**
     * Core window expansion shared by the rolling-days and month modes.
     * Applies every seva rule (acceptance period, blackouts, weekday
     * restrictions, slot/unit capacity) across [$start, $end] inclusive.
     *
     * @return list<string> Dates in 'Y-m-d' format, ascending.
     */
    private function getAvailableDatesInRange(Seva $seva, Carbon $start, Carbon $end): array
    {
        $config = $this->normalizeConfig($seva->slot_config);

        // Bulk-fetch the booking counts for the window, then index by date+slot.
        $rows = SevaBooking::where('seva_id', $seva->id)
            ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            // `pending` holds the slot during the ~10-30s payment window
            // so two devotees can't race-book the same slot while one
            // is mid-Razorpay. PaymentCaptureService::markFailed flips
            // failed payments to `cancelled`, releasing the hold.
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->selectRaw('DATE(booking_date) as bdate, LEFT(slot_time, 5) as slot, COUNT(*) as cnt')
            ->groupBy('bdate', 'slot')
            ->get();

        $counts = [];   // counts[date][slot] = n
        foreach ($rows as $row) {
            $counts[$row->bdate][$row->slot] = (int) $row->cnt;
        }

        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $slotType = $this->slotType($config);
        $fullUnitMemo = []; // memoize full_day (per date) / full_week (per week) counts
        $available = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();

            if (! $this->isDateInAcceptancePeriod($config, $date)) {
                continue;
            }
            if ($this->getBlackoutReason($config, $date)) {
                continue;
            }

            // Full-day / full-week: the date is bookable if its unit (day or
            // ISO week) still has capacity. Memoized so a full_week only
            // hits the DB once per week rather than once per day.
            if ($slotType === self::SLOT_TYPE_FULL_DAY || $slotType === self::SLOT_TYPE_FULL_WEEK) {
                // full_day may be restricted to specific weekdays.
                if (! $this->fullDayAllowedOnDate($config, $date)) {
                    continue;
                }
                $key = $slotType === self::SLOT_TYPE_FULL_WEEK
                    ? implode('_', $this->weekRange($date))
                    : $date;
                if (! array_key_exists($key, $fullUnitMemo)) {
                    $fullUnitMemo[$key] = $this->countFullUnitBookings($seva, $slotType, $date);
                }
                if ($fullUnitMemo[$key] < $maxPerSlot) {
                    $available[] = $date;
                }

                continue;
            }

            $slotsForDay = $this->getSlotsForDate($seva, $date);

            // For *today*, drop already-elapsed slots — a 10:00 slot
            // shouldn't be selectable at 12:30 PM.
            if ($this->isToday($date)) {
                $slotsForDay = $this->filterPastSlots($slotsForDay);
            }

            // Sevas that don't require booking still have a "date" — we
            // accept the date as long as it isn't blacked out / outside
            // the acceptance window.
            if (! $seva->requires_booking) {
                $available[] = $date;

                continue;
            }

            if (empty($slotsForDay)) {
                continue;
            }

            $dateCounts = $counts[$date] ?? [];
            foreach ($slotsForDay as $slot) {
                if (($dateCounts[$slot] ?? 0) < $maxPerSlot) {
                    $available[] = $date;
                    break;
                }
            }
        }

        return $available;
    }

    /**
     * True when the supplied 'Y-m-d' string equals today's local date.
     */
    private function isToday(string $date): bool
    {
        return $date === now()->toDateString();
    }

    /**
     * Drop slot strings ('HH:MM') whose start time is already in the past.
     * Used only when the date is *today*, so future dates aren't affected.
     *
     * @param  list<string>  $slots  HH:MM
     * @return list<string>
     */
    private function filterPastSlots(array $slots): array
    {
        $now = now();
        $result = [];
        foreach ($slots as $slot) {
            if (! is_string($slot) || strlen($slot) < 4) {
                continue;
            }
            [$h, $m] = array_pad(explode(':', $slot, 2), 2, '0');
            $slotMoment = $now->copy()->setTime((int) $h, (int) $m, 0);
            if ($slotMoment->greaterThan($now)) {
                $result[] = $slot;
            }
        }

        return $result;
    }

    /**
     * Validate a booking attempt. Returns error message or null on success.
     */
    public function validateBooking(Seva $seva, string $date, ?string $slotTime): ?string
    {
        $config = $this->normalizeConfig($seva->slot_config);

        if (! $this->isDateInAcceptancePeriod($config, $date)) {
            return 'This seva is not accepting bookings for this date.';
        }

        $blackoutReason = $this->getBlackoutReason($config, $date);
        if ($blackoutReason) {
            return "Seva unavailable on this date: {$blackoutReason}";
        }

        if (! $seva->requires_booking) {
            // Free-form seva (no booking system), nothing to validate.
            return null;
        }

        // Full-day / full-week: no time slot to pick — validate capacity for
        // the whole day or the whole ISO week. The controller stores the mode
        // string as slot_time, so incoming $slotTime is not consulted here.
        $slotType = $this->slotType($config);
        if ($slotType === self::SLOT_TYPE_FULL_DAY || $slotType === self::SLOT_TYPE_FULL_WEEK) {
            if (! $this->fullDayAllowedOnDate($config, $date)) {
                return 'This seva is not available on this day.';
            }

            $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
            if ($this->countFullUnitBookings($seva, $slotType, $date) >= $maxPerSlot) {
                return $slotType === self::SLOT_TYPE_FULL_WEEK
                    ? 'This week is fully booked. Please choose another.'
                    : 'This day is fully booked. Please choose another.';
            }

            return null;
        }

        $configuredSlots = $this->getSlotsForDate($seva, $date);

        if (empty($slotTime)) {
            // Empty slot_time was previously accepted unconditionally —
            // that caused production bookings to persist with NULL
            // slot_time, then WhatsApp confirmation templates that map
            // {{ slot_time }} → booking.slot_time rendered empty and
            // got rejected by Meta with (#131008) Required parameter
            // is missing. Reject empty slot_time when the seva has any
            // slots configured for the date.
            if (! empty($configuredSlots)) {
                return 'Please select a slot time.';
            }

            return null; // seva genuinely has no slots configured for this date
        }

        if (! in_array($slotTime, $configuredSlots, true)) {
            return 'Invalid slot time for this date.';
        }

        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $currentBookings = SevaBooking::where('seva_id', $seva->id)
            ->where('booking_date', $date)
            ->where('slot_time', $slotTime)
            // `pending` holds the slot during the ~10-30s payment window
            // so two devotees can't race-book the same slot while one
            // is mid-Razorpay. PaymentCaptureService::markFailed flips
            // failed payments to `cancelled`, releasing the hold.
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->count();

        if ($currentBookings >= $maxPerSlot) {
            return 'This slot is fully booked. Please choose another.';
        }

        return null;
    }

    /**
     * Race-safe capacity re-check, to be called INSIDE the booking
     * transaction immediately before inserting the SevaBooking row.
     *
     * validateBooking() above runs OUTSIDE any transaction, so two
     * devotees hitting the last slot in the same instant can both pass it
     * and both insert (classic check-then-act race — there is no DB unique
     * constraint because slots have a configurable capacity, which a unique
     * index can't express). This method re-counts the slot's bookings with
     * `lockForUpdate()`, so concurrent bookings for the same seva+date
     * serialise on InnoDB's row/gap locks: the second caller blocks until
     * the first commits, then sees the updated count and is correctly
     * rejected when the slot is full.
     *
     * Returns true if there is still capacity (safe to insert), false if
     * the slot filled up while we waited for the lock.
     */
    public function hasSlotCapacityForUpdate(Seva $seva, string $date, ?string $slotTime): bool
    {
        if (! $seva->requires_booking) {
            return true;
        }

        $config = $this->normalizeConfig($seva->slot_config);
        $slotType = $this->slotType($config);
        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;

        // Full-day / full-week: race-safe re-count over the day or ISO week.
        if ($slotType === self::SLOT_TYPE_FULL_DAY || $slotType === self::SLOT_TYPE_FULL_WEEK) {
            return $this->countFullUnitBookings($seva, $slotType, $date, true) < $maxPerSlot;
        }

        if (empty($slotTime)) {
            // No specific slot to contend for.
            return true;
        }

        $currentBookings = SevaBooking::where('seva_id', $seva->id)
            ->where('booking_date', $date)
            ->where('slot_time', $slotTime)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->lockForUpdate()
            ->count();

        return $currentBookings < $maxPerSlot;
    }

    private function emptyConfig(): array
    {
        return [
            'version' => 2,
            'slot_type' => self::SLOT_TYPE_TIME,
            'slot_duration_minutes' => 60,
            'max_bookings_per_slot' => 1,
            'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
            'weekly_schedule' => ['default' => []],
            'blackout_dates' => [],
        ];
    }
}
