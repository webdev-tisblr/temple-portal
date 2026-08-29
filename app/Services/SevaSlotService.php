<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UnavailableReason;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

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
     * Per-request memo of pool member ids: pool_id => list<int seva id>.
     *
     * @var array<int, list<int>>
     */
    private array $poolMemberIds = [];

    /**
     * How long a `pending` booking keeps holding its slot.
     *
     * A booking is inserted as `pending` the moment the devotee is handed to
     * Razorpay, so that two people cannot pay for the same slot at once. The
     * hold only ever HAD to outlive the checkout window — but until 2026-08-29
     * it had no expiry at all in the availability queries, so a devotee who
     * closed the Razorpay sheet locked their own slot (and everyone else's
     * shot at it, and the seva's linked products with it) until
     * `bookings:clean-stale` swept the row half an hour later. The retry they
     * immediately attempted was refused by their own abandoned attempt.
     *
     * Past this many minutes a pending booking stops counting toward capacity.
     * The row itself is still cancelled by clean-stale on its own schedule —
     * this is purely about who the slot is offered to in the meantime.
     *
     * Tunable without a deploy via the `seva_slot_hold_minutes` setting. Keep
     * it comfortably above the time a real checkout takes (~10-30s) and below
     * clean-stale's window, or the two disagree about live rows.
     *
     * Set to 5 on 2026-08-29, down from 15, and the production setting row
     * matches. The trade is explicit: five minutes is still an order of
     * magnitude longer than a real checkout, but a devotee genuinely stuck
     * inside Razorpay on bad mobile data can now have their slot offered to
     * someone else. If both then pay, PaymentCaptureService logs the oversell
     * for an admin rather than failing a paid booking — that is the failure
     * mode being accepted in exchange for slots freeing up quickly.
     */
    public const DEFAULT_HOLD_MINUTES = 5;

    /** @var int|null Per-request memo — the setting is read on every count. */
    private ?int $holdMinutes = null;

    public function holdMinutes(): int
    {
        if ($this->holdMinutes === null) {
            // getValue's $default is typed string — pass the number as one.
            $configured = (int) SystemSetting::getValue('seva_slot_hold_minutes', (string) self::DEFAULT_HOLD_MINUTES);
            $this->holdMinutes = $configured > 0 ? $configured : self::DEFAULT_HOLD_MINUTES;
        }

        return $this->holdMinutes;
    }

    /**
     * Restrict a booking query to the rows that actually occupy a slot:
     * everything confirmed or completed, plus pending bookings still inside
     * their payment hold. @see DEFAULT_HOLD_MINUTES
     *
     * Every capacity count in this service goes through here, including the
     * locked re-check, so the answer a devotee is shown and the answer the
     * insert enforces can never drift apart.
     *
     * @param  Builder<SevaBooking>  $query
     * @return Builder<SevaBooking>
     */
    public function scopeOccupied(Builder $query): Builder
    {
        $holdFrom = now()->subMinutes($this->holdMinutes());

        return $query->where(function ($q) use ($holdFrom) {
            $q->whereIn('status', ['confirmed', 'completed'])
                ->orWhere(fn ($p) => $p->where('status', 'pending')->where('created_at', '>=', $holdFrom));
        });
    }

    /**
     * Drop this devotee's own abandoned attempt at the exact same slot before
     * they try again.
     *
     * The hold above frees a slot for OTHER devotees after a few minutes; this
     * is what lets the person who backed out of Razorpay retry immediately.
     * Re-submitting the same seva + date + slot is unambiguously a retry, so
     * the earlier pending row is cancelled rather than left to contend with
     * the new one.
     *
     * Safe against a late capture: PaymentCaptureService::markCaptured
     * confirms whatever booking its payment points at, whatever status the row
     * is sitting in, so a payment that lands after this still produces a
     * confirmed booking.
     */
    public function releaseOwnPendingHold(Seva $seva, string $devoteeId, string $date, ?string $slotTime): int
    {
        $query = SevaBooking::whereIn('seva_id', $this->memberIds($seva))
            ->where('devotee_id', $devoteeId)
            ->where('booking_date', $date)
            ->where('status', 'pending');

        if ($slotTime === null || $slotTime === '') {
            $query->whereNull('slot_time');
        } else {
            $query->where('slot_time', $slotTime);
        }

        return $query->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'Superseded by a new booking attempt',
        ]);
    }

    /**
     * The slot config that actually governs a seva: its pool's config
     * when it belongs to a shared slot pool, else its own. Always
     * normalized to v2.
     */
    public function configFor(Seva $seva): array
    {
        if ($seva->slot_pool_id) {
            $seva->loadMissing('slotPool');
            if ($seva->slotPool) {
                return $this->normalizeConfig($seva->slotPool->slot_config);
            }
        }

        return $this->normalizeConfig($seva->slot_config);
    }

    /**
     * The seva ids whose bookings count against this seva's capacity:
     * every member of its pool (active or not — their bookings still
     * occupy slots), or just the seva itself when unpooled.
     *
     * @return list<int>
     */
    public function memberIds(Seva $seva): array
    {
        if (! $seva->slot_pool_id) {
            return [(int) $seva->id];
        }

        $poolId = (int) $seva->slot_pool_id;
        if (! array_key_exists($poolId, $this->poolMemberIds)) {
            $this->poolMemberIds[$poolId] = Seva::where('slot_pool_id', $poolId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        // The seva itself is always included even if the memo raced a
        // just-saved pool assignment.
        return array_values(array_unique([...$this->poolMemberIds[$poolId], (int) $seva->id]));
    }

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
     * Admin-configured booking cut-off in hours (item 4.3, 2026-08-09).
     *
     * Lives INSIDE slot_config (not as a column on temple_sevas) so that
     * sevas sharing a slot pool inherit the pool's cut-off through
     * configFor() — a column would be silently ignored for pooled sevas,
     * which is precisely the bug class this avoids.
     *
     * Resolution: slot_config['booking_cutoff_hours'] → SystemSetting
     * 'seva_default_cutoff_hours' → 0 (no cut-off = pre-4.3 behaviour).
     */
    public function cutoffHours(array $config): int
    {
        $raw = $config['booking_cutoff_hours'] ?? null;
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return max(0, (int) SystemSetting::getValue('seva_default_cutoff_hours', '0'));
    }

    /** Slots starting before this moment are no longer bookable. */
    public function cutoffMoment(array $config): Carbon
    {
        return now()->addHours($this->cutoffHours($config));
    }

    /**
     * The moment a candidate booking starts: the slot's own HH:MM, or the
     * full-day / full-week anchor time when there is no time slot.
     */
    public function slotMoment(array $config, string $date, ?string $slotTime): Carbon
    {
        if (is_string($slotTime) && preg_match('/^(\d{1,2}):(\d{2})$/', $slotTime, $m)) {
            return Carbon::parse($date)->setTime((int) $m[1], (int) $m[2], 0);
        }

        [$h, $min] = $this->fullDayAnchorTime($config);

        return Carbon::parse($date)->setTime($h, $min, 0);
    }

    /**
     * Why a candidate is time-blocked, or null when it is still bookable.
     *
     *  • `elapsed` — an HH:MM slot whose start time has already passed.
     *    Applies ONLY to real time slots, exactly as the old
     *    filterPastSlots() did, so full-day sevas keep being bookable
     *    later the same day when no cut-off is configured.
     *  • `cutoff`  — inside the admin's now+N hours window (any mode).
     *
     * This is the single place the 4.3 rule is expressed; every read path
     * AND validateBooking() call it, so the UI can never be the only gate.
     */
    public function cutoffReason(array $config, string $date, ?string $slotTime): ?UnavailableReason
    {
        $isTimeSlot = is_string($slotTime) && preg_match('/^\d{1,2}:\d{2}$/', $slotTime) === 1;
        $moment = $this->slotMoment($config, $date, $slotTime);
        $now = now();

        if ($isTimeSlot && $moment->lessThanOrEqualTo($now)) {
            return UnavailableReason::Elapsed;
        }

        $hours = $this->cutoffHours($config);
        if ($hours > 0 && $moment->lessThan($now->copy()->addHours($hours))) {
            return UnavailableReason::Cutoff;
        }

        return null;
    }

    /** Devotee-facing message for a time-block reason. */
    private function cutoffMessage(array $config, UnavailableReason $reason): string
    {
        return $reason === UnavailableReason::Elapsed
            ? (string) __('availability.elapsed_blocked')
            : (string) __('availability.cutoff_blocked', ['hours' => $this->cutoffHours($config)]);
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
        // Pool members share capacity — count bookings across the pool.
        $q = $this->scopeOccupied(SevaBooking::whereIn('seva_id', $this->memberIds($seva)))
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
            // Item 4.3 — 0 means "no cut-off", i.e. pre-4.3 behaviour.
            'booking_cutoff_hours' => 0,
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
        $config = $this->configFor($seva);

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
     *
     * Returns: ['available' => [...], 'booked' => [...], 'blackout' => bool,
     *           'blackout_reason' => ?string, 'message' => ?string,
     *           'slot_details' => [...]]
     *
     * `slot_details` is NEW (item 4.1) and additive: one entry per slot the
     * seva offers that day, each with `available` + `reason_code` +
     * localized `reason`, so the UI can render a "Not Available" badge
     * instead of silently hiding the chip.
     *
     * `available` / `booked` keep their exact old shape (lists of slot
     * strings) — App 1.4.8+32 reads them. Slots that are elapsed or inside
     * the cut-off window used to be DROPPED from both lists; they now land
     * in `booked`, which is the closest existing "shown but not tappable"
     * bucket (the web blade already renders `booked` struck through).
     */
    public function getSlotAvailability(Seva $seva, string $date): array
    {
        $config = $this->configFor($seva);

        // Check acceptance period
        if (! $this->isDateInAcceptancePeriod($config, $date)) {
            return [
                'available' => [],
                'booked' => [],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => 'This seva is not available for booking on this date.',
                'slot_details' => [],
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
                'slot_details' => [],
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
                    'slot_details' => [],
                ];
            }

            $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
            $reason = $this->countFullUnitBookings($seva, $slotType, $date) >= $maxPerSlot
                ? UnavailableReason::Full
                : $this->cutoffReason($config, $date, $slotType);

            return [
                'available' => $reason === null ? [$slotType] : [],
                'booked' => $reason === null ? [] : [$slotType],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => null,
                'slot_type' => $slotType,
                'slot_details' => [$this->slotDetail($slotType, $reason)],
            ];
        }

        // Get day's slots. Elapsed / cut-off slots are NO LONGER dropped
        // here (item 4.1) — they are flagged below instead.
        $allSlots = $this->getSlotsForDate($seva, $date);

        if (empty($allSlots)) {
            return [
                'available' => [],
                'booked' => [],
                'blackout' => false,
                'blackout_reason' => null,
                'message' => null,
                'slot_details' => [],
            ];
        }

        // Count bookings per slot — across the pool when the seva shares one.
        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $bookingCounts = $this->scopeOccupied(
            SevaBooking::whereIn('seva_id', $this->memberIds($seva))
                ->where('booking_date', $date)
        )
            ->selectRaw('LEFT(slot_time, 5) as slot, COUNT(*) as cnt')
            ->groupBy('slot')
            ->pluck('cnt', 'slot')
            ->toArray();

        $available = [];
        $booked = [];
        $details = [];
        foreach ($allSlots as $slot) {
            $count = $bookingCounts[$slot] ?? 0;
            $reason = $count >= $maxPerSlot
                ? UnavailableReason::Full
                : $this->cutoffReason($config, $date, $slot);

            if ($reason === null) {
                $available[] = $slot;
            } else {
                $booked[] = $slot;
            }
            $details[] = $this->slotDetail($slot, $reason);
        }

        return [
            'available' => $available,
            'booked' => $booked,
            'blackout' => false,
            'blackout_reason' => null,
            'message' => null,
            'slot_details' => $details,
        ];
    }

    /**
     * One `slot_details` entry.
     *
     * @return array{time:string, available:bool, reason_code:?string, reason:?string}
     */
    private function slotDetail(string $slot, ?UnavailableReason $reason): array
    {
        return [
            'time' => $slot,
            'available' => $reason === null,
            'reason_code' => $reason?->value,
            'reason' => $reason?->label(),
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
     * Per-date availability for one calendar month ('YYYY-MM') — item 4.1.
     * Same window as getAvailableDatesForMonth() but EVERY date is
     * returned, unbookable ones flagged with a reason instead of omitted.
     *
     * @return list<array{date:string, available:bool, reason_code:?string, reason:?string}>
     */
    public function getDateAvailabilityForMonth(Seva $seva, string $month): array
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

        return $this->getDateAvailabilityInRange($seva, $start, $end);
    }

    /**
     * Core window expansion shared by the rolling-days and month modes.
     * Applies every seva rule (acceptance period, blackouts, weekday
     * restrictions, slot/unit capacity) across [$start, $end] inclusive.
     *
     * Thin filter over getDateAvailabilityInRange() so there is exactly
     * ONE availability algorithm — the flagged list and the legacy
     * bookable-dates list can never disagree.
     *
     * @return list<string> Dates in 'Y-m-d' format, ascending.
     */
    private function getAvailableDatesInRange(Seva $seva, Carbon $start, Carbon $end): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['date'],
            array_filter(
                $this->getDateAvailabilityInRange($seva, $start, $end),
                static fn (array $row): bool => $row['available'] === true,
            ),
        ));
    }

    /**
     * Every date in [$start, $end] with an availability verdict — the
     * single source of truth for both the legacy `dates` list and the new
     * `days_detail` payload (item 4.1).
     *
     * One bulk booking-count query powers the whole window, exactly as
     * before; the `continue`s that used to silently drop a date now emit
     * a flagged row instead.
     *
     * NOTE: this returns EVERY date with its verdict, including the ones
     * that must never be shown to a devotee (wrong weekday, blackout,
     * outside the acceptance window, no slots configured). Deciding which
     * of those reach the UI is NOT this method's job — it belongs to
     * UnavailableReason::display(), applied by the controllers via
     * UnavailableReason::visible(). Keeping the full verdict here is what
     * lets nextAvailable() and the tests reason about every date.
     *
     * @return list<array{date:string, available:bool, reason_code:?string, reason:?string}>
     */
    public function getDateAvailabilityInRange(Seva $seva, Carbon $start, Carbon $end): array
    {
        $config = $this->configFor($seva);

        // Bulk-fetch the booking counts for the window, then index by
        // date+slot. Pool members share capacity, so the count spans
        // every seva in the pool.
        $rows = $this->scopeOccupied(
            SevaBooking::whereIn('seva_id', $this->memberIds($seva))
                ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
        )
            ->selectRaw('DATE(booking_date) as bdate, slot_time as slot, COUNT(*) as cnt')
            ->groupBy('bdate', 'slot')
            ->get();

        $counts = [];   // counts[date][slot] = n
        foreach ($rows as $row) {
            // Time slots are stored as HH:MM:SS but configured as HH:MM, so
            // only those are truncated — the full_day / full_week sentinels
            // are kept whole. Truncating everything to 5 chars collapsed
            // both sentinels into 'full_' and made the full-day count
            // unusable, which is why that branch fell back to a COUNT query
            // per date.
            //
            // Legacy rows can still carry a NULL slot_time; using null as
            // an array key is deprecated in PHP 8.4 and was emitting a
            // notice on every availability request. Such rows never match
            // a configured slot, so bucketing them under '' is both silent
            // and correct.
            $slot = (string) $row->slot;
            $slot = preg_match('/^\d{2}:\d{2}/', $slot) === 1 ? substr($slot, 0, 5) : $slot;
            $counts[$row->bdate][$slot] = ($counts[$row->bdate][$slot] ?? 0) + (int) $row->cnt;
        }

        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $slotType = $this->slotType($config);
        $fullUnitMemo = []; // memoize full_day (per date) / full_week (per week) counts
        $out = [];

        $row = static function (string $date, ?UnavailableReason $reason, ?string $text = null): array {
            return [
                'date' => $date,
                'available' => $reason === null,
                'reason_code' => $reason?->value,
                'reason' => $reason === null ? null : ($text ?? $reason->label()),
            ];
        };

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();

            if (! $this->isDateInAcceptancePeriod($config, $date)) {
                $out[] = $row($date, UnavailableReason::OutsidePeriod);

                continue;
            }
            $blackout = $this->getBlackoutReason($config, $date);
            if ($blackout) {
                // Surface the admin's own wording, keyed by 'blackout'.
                $out[] = $row($date, UnavailableReason::Blackout, $blackout);

                continue;
            }

            // Full-day / full-week: the date is bookable if its unit (day or
            // ISO week) still has capacity. Memoized so a full_week only
            // hits the DB once per week rather than once per day.
            if ($slotType === self::SLOT_TYPE_FULL_DAY || $slotType === self::SLOT_TYPE_FULL_WEEK) {
                // full_day may be restricted to specific weekdays.
                if (! $this->fullDayAllowedOnDate($config, $date)) {
                    $out[] = $row($date, UnavailableReason::WeekdayClosed);

                    continue;
                }
                $key = $slotType === self::SLOT_TYPE_FULL_WEEK
                    ? implode('_', $this->weekRange($date))
                    : $date;
                if (! array_key_exists($key, $fullUnitMemo)) {
                    // full_day is answered straight from the bulk counts —
                    // the day's own bookings are all that matter and they
                    // are already in the window. full_week still needs its
                    // own query: a week can start before $start, and the
                    // bulk fetch would undercount it.
                    $fullUnitMemo[$key] = $slotType === self::SLOT_TYPE_FULL_DAY
                        ? ($counts[$date][self::SLOT_TYPE_FULL_DAY] ?? 0)
                        : $this->countFullUnitBookings($seva, $slotType, $date);
                }
                $reason = $fullUnitMemo[$key] >= $maxPerSlot
                    ? UnavailableReason::Full
                    // Cut-off is time-aware and must run for FUTURE days too
                    // once the buffer exceeds 24h — not just for today.
                    : $this->cutoffReason($config, $date, $slotType);
                $out[] = $row($date, $reason);

                continue;
            }

            $slotsForDay = $this->getSlotsForDate($seva, $date);

            // Sevas that don't require booking still have a "date" — we
            // accept the date as long as it isn't blacked out / outside
            // the acceptance window.
            if (! $seva->requires_booking) {
                $out[] = $row($date, null);

                continue;
            }

            if (empty($slotsForDay)) {
                $out[] = $row($date, UnavailableReason::NoSlots);

                continue;
            }

            $dateCounts = $counts[$date] ?? [];
            $reason = UnavailableReason::Full;
            foreach ($slotsForDay as $slot) {
                if (($dateCounts[$slot] ?? 0) >= $maxPerSlot) {
                    continue;
                }
                // Slot has capacity — is it still in time? A day whose only
                // open slots are elapsed / inside the cut-off reports that
                // reason rather than the misleading "fully booked".
                $timeBlock = $this->cutoffReason($config, $date, $slot);
                if ($timeBlock === null) {
                    $reason = null;
                    break;
                }
                $reason = $timeBlock;
            }
            $out[] = $row($date, $reason);
        }

        return $out;
    }

    /**
     * The first bookable date (and slot) from $from forward — item 4.4.
     *
     * Replaces the app's client-side 12+N request scan with one request.
     * Walks the horizon in 62-day chunks so each chunk costs a single bulk
     * booking-count query, exactly like the month picker.
     *
     * @return array{date:string, slot_time:?string, slot_type:string}|null
     */
    public function nextAvailable(Seva $seva, ?string $from = null, int $horizonDays = 365): ?array
    {
        $today = now()->startOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $today->copy();
        if ($start->lt($today)) {
            $start = $today->copy();
        }

        $horizonDays = max(1, min($horizonDays, 730));
        $end = $start->copy()->addDays($horizonDays - 1);
        $slotType = $this->slotType($this->configFor($seva));

        $chunkStart = $start->copy();
        while ($chunkStart->lte($end)) {
            $chunkEnd = $chunkStart->copy()->addDays(61);
            if ($chunkEnd->gt($end)) {
                $chunkEnd = $end->copy();
            }

            foreach ($this->getDateAvailabilityInRange($seva, $chunkStart, $chunkEnd) as $row) {
                if ($row['available'] !== true) {
                    continue;
                }

                if (! $seva->requires_booking) {
                    return ['date' => $row['date'], 'slot_time' => null, 'slot_type' => $slotType];
                }

                $availability = $this->getSlotAvailability($seva, $row['date']);
                $slot = $availability['available'][0] ?? null;
                if ($slot === null) {
                    continue; // defensive: the two views disagreed
                }

                return ['date' => $row['date'], 'slot_time' => $slot, 'slot_type' => $slotType];
            }

            $chunkStart = $chunkEnd->copy()->addDay();
        }

        return null;
    }

    /**
     * Validate a booking attempt. Returns error message or null on success.
     */
    public function validateBooking(Seva $seva, string $date, ?string $slotTime): ?string
    {
        $config = $this->configFor($seva);

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

            // MANDATORY server-side cut-off enforcement (item 4.3). The UI
            // only hides these; a stale page or a crafted POST must still
            // be rejected here.
            if (($timeBlock = $this->cutoffReason($config, $date, $slotType)) !== null) {
                return $this->cutoffMessage($config, $timeBlock);
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

        // MANDATORY server-side enforcement (item 4.3). This is also the fix
        // for the pre-existing bug where validateBooking() never applied the
        // elapsed-slot filter, so a stale page or a crafted POST could book
        // a slot that had already started.
        if (($timeBlock = $this->cutoffReason($config, $date, $slotTime)) !== null) {
            return $this->cutoffMessage($config, $timeBlock);
        }

        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $currentBookings = $this->scopeOccupied(
            SevaBooking::whereIn('seva_id', $this->memberIds($seva))
                ->where('booking_date', $date)
                ->where('slot_time', $slotTime)
        )->count();

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

        $config = $this->configFor($seva);
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

        // whereIn keeps InnoDB's locking-read semantics: concurrent
        // bookings for ANY pool member on this date+slot serialise on
        // the same row/gap locks, exactly as the single-seva case did.
        $currentBookings = $this->scopeOccupied(
            SevaBooking::whereIn('seva_id', $this->memberIds($seva))
                ->where('booking_date', $date)
                ->where('slot_time', $slotTime)
        )
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
            // Item 4.3 — 0 means "no cut-off", i.e. pre-4.3 behaviour.
            'booking_cutoff_hours' => 0,
            'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
            'weekly_schedule' => ['default' => []],
            'blackout_dates' => [],
        ];
    }
}
