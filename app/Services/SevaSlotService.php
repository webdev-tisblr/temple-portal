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
            ->whereIn('status', ['confirmed', 'completed'])
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
            ->whereIn('status', ['confirmed', 'completed'])
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
