<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\SevaSlotService;
use Database\Factories\DevoteeFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The counter's booking-date picker greys out dates the seva cannot take
 * (2026-08-17). Previously a clerk could pick a fully-booked date and only
 * discover it at submit, after the money conversation.
 *
 * These assert the availability source the picker reads — the same one the
 * website and app calendars use — so a clerk sees what a devotee would.
 */
class CounterEntrySevaDatesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A v2 full-day slot config. `version => 2` is required: normalizeConfig()
     * otherwise treats the array as v1 and rewrites slot_type to time-slots.
     *
     * @param  list<array{date:string, reason:string}>  $blackouts
     * @return array<string, mixed>
     */
    private static function fullDayConfig(int $capacity, array $blackouts = []): array
    {
        return [
            'version' => 2,
            'slot_type' => 'full_day',
            'max_bookings_per_slot' => $capacity,
            'booking_cutoff_hours' => 0,
            'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
            'blackout_dates' => $blackouts,
        ];
    }

    /** @return list<string> */
    private function unavailableDates(Seva $seva, int $days = 30): array
    {
        return collect(app(SevaSlotService::class)->getDateAvailabilityInRange(
            $seva,
            now()->startOfDay(),
            now()->startOfDay()->addDays($days),
        ))
            ->reject(fn (array $day): bool => (bool) ($day['available'] ?? false))
            ->pluck('date')
            ->values()
            ->all();
    }

    public function test_a_fully_booked_full_day_seva_date_is_unavailable(): void
    {
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            // version 2 is load-bearing: without it normalizeConfig() takes
            // the v1 conversion path, which hardcodes slot_type to time-slots
            // and quietly ignores the full_day asked for here.
            'slot_config' => self::fullDayConfig(1),
        ]);

        $date = now()->addDays(6)->toDateString();
        $this->assertNotContains($date, $this->unavailableDates($seva), 'should start bookable');

        // One confirmed booking fills the day (capacity 1).
        SevaBooking::create([
            'id' => (string) Str::uuid(),
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'seva_id' => $seva->id,
            'booking_date' => $date,
            'slot_time' => SevaSlotService::SLOT_TYPE_FULL_DAY,
            'quantity' => 1,
            'total_amount' => 101,
            'status' => 'confirmed',
        ]);

        $this->assertContains($date, $this->unavailableDates($seva),
            'a full date must be greyed out, not merely rejected at submit');
    }

    public function test_a_pending_booking_also_holds_the_date(): void
    {
        // A pending row is the ~30s payment window; it must hold the slot or
        // two clerks could sell the same one.
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'slot_config' => self::fullDayConfig(1),
        ]);

        $date = now()->addDays(7)->toDateString();
        SevaBooking::create([
            'id' => (string) Str::uuid(),
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'seva_id' => $seva->id,
            'booking_date' => $date,
            'slot_time' => SevaSlotService::SLOT_TYPE_FULL_DAY,
            'quantity' => 1,
            'total_amount' => 101,
            'status' => 'pending',
        ]);

        $this->assertContains($date, $this->unavailableDates($seva));
    }

    public function test_a_blacked_out_date_is_unavailable(): void
    {
        $date = now()->addDays(9)->toDateString();
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'slot_config' => self::fullDayConfig(5, [
                ['date' => $date, 'reason' => 'Temple closed'],
            ]),
        ]);

        $this->assertContains($date, $this->unavailableDates($seva));
    }

    public function test_yesterday_is_never_offered(): void
    {
        $seva = SevaFactory::new()->create([
            'requires_booking' => true,
            'slot_config' => self::fullDayConfig(5),
        ]);

        // The picker's own minDate is today; the range simply never starts
        // earlier, so a past date can't be reached at all.
        $dates = collect(app(SevaSlotService::class)->getDateAvailabilityInRange(
            $seva,
            now()->startOfDay(),
            now()->startOfDay()->addDays(3),
        ))->pluck('date');

        $this->assertFalse($dates->contains(Carbon::yesterday()->toDateString()));
    }
}
