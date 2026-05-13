<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Seva;
use App\Models\SevaBooking;
use Carbon\Carbon;

class SevaSlotService
{
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
            return $config;
        }

        // v1 → v2 conversion
        return [
            'version' => 2,
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
            ->selectRaw("LEFT(slot_time, 5) as slot, COUNT(*) as cnt")
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
     * @return list<string>  Dates in 'Y-m-d' format, ascending.
     */
    public function getAvailableDates(Seva $seva, int $days = 30): array
    {
        $days = max(1, min($days, 90));
        $config = $this->normalizeConfig($seva->slot_config);

        $start = now()->startOfDay();
        $end = $start->copy()->addDays($days);

        // Bulk-fetch the booking counts for the window, then index by date+slot.
        $rows = SevaBooking::where('seva_id', $seva->id)
            ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            // `pending` holds the slot during the ~10-30s payment window
            // so two devotees can't race-book the same slot while one
            // is mid-Razorpay. PaymentCaptureService::markFailed flips
            // failed payments to `cancelled`, releasing the hold.
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->selectRaw("DATE(booking_date) as bdate, LEFT(slot_time, 5) as slot, COUNT(*) as cnt")
            ->groupBy('bdate', 'slot')
            ->get();

        $counts = [];   // counts[date][slot] = n
        foreach ($rows as $row) {
            $counts[$row->bdate][$row->slot] = (int) $row->cnt;
        }

        $maxPerSlot = $config['max_bookings_per_slot'] ?? 1;
        $available = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();

            if (! $this->isDateInAcceptancePeriod($config, $date)) {
                continue;
            }
            if ($this->getBlackoutReason($config, $date)) {
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
     * @param  list<string> $slots  HH:MM
     * @return list<string>
     */
    private function filterPastSlots(array $slots): array
    {
        $now = now();
        $result = [];
        foreach ($slots as $slot) {
            if (! is_string($slot) || strlen($slot) < 4) continue;
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

        if (! $seva->requires_booking || empty($slotTime)) {
            return null;
        }

        $configuredSlots = $this->getSlotsForDate($seva, $date);
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

    private function emptyConfig(): array
    {
        return [
            'version' => 2,
            'slot_duration_minutes' => 60,
            'max_bookings_per_slot' => 1,
            'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
            'weekly_schedule' => ['default' => []],
            'blackout_dates' => [],
        ];
    }
}
