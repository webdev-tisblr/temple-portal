<?php

namespace Tests\Feature;

use App\Models\Seva;
use Database\Factories\HallBookingFactory;
use Database\Factories\HallFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * APP-COMPATIBILITY GUARD.
 *
 * Build 1.4.8+32 is live in both stores. Every JSON key these endpoints
 * emitted before the section-4 work must keep its NAME, TYPE and MEANING;
 * everything new must be strictly additive. If a future change renames or
 * re-means one of these keys, this test fails before the app does.
 */
class AvailabilityContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function timeSlotSeva(): Seva
    {
        return SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'slot_duration_minutes' => 60,
                'max_bookings_per_slot' => 1,
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'weekly_schedule' => ['default' => ['06:00', '18:00']],
                'blackout_dates' => [],
            ],
        ]);
    }

    // ── GET /api/v1/sevas/{seva}/available-dates ─────────────────────

    public function test_seva_available_dates_month_mode_keeps_its_shape(): void
    {
        $seva = $this->timeSlotSeva();
        $month = now()->format('Y-m');

        $response = $this->getJson("/api/v1/sevas/{$seva->id}/available-dates?month={$month}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data' => ['month', 'dates']]);

        // `dates` is STILL a flat list of 'Y-m-d' strings holding only the
        // BOOKABLE dates — the app maps it straight into a Set<String>.
        $dates = $response->json('data.dates');
        $this->assertIsArray($dates);
        foreach ($dates as $d) {
            $this->assertIsString($d);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $d);
        }
        $this->assertSame($month, $response->json('data.month'));

        // Additive only.
        $this->assertIsArray($response->json('data.days_detail'));
    }

    public function test_seva_available_dates_days_mode_keeps_its_shape(): void
    {
        $seva = $this->timeSlotSeva();

        $response = $this->getJson("/api/v1/sevas/{$seva->id}/available-dates?days=7");

        $response->assertOk()->assertJsonStructure(['data' => ['days', 'dates']]);
        $this->assertSame(7, $response->json('data.days'));
        $this->assertIsArray($response->json('data.dates'));
    }

    public function test_seva_dates_list_and_days_detail_never_disagree(): void
    {
        $seva = $this->timeSlotSeva();
        $month = now()->format('Y-m');

        $data = $this->getJson("/api/v1/sevas/{$seva->id}/available-dates?month={$month}")->json('data');

        $fromDetail = collect($data['days_detail'])->where('available', true)->pluck('date')->values()->all();
        $this->assertSame($data['dates'], $fromDetail);
    }

    // ── GET /api/v1/sevas/{seva}/slots ───────────────────────────────

    public function test_seva_slots_keeps_every_pre_existing_key(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(1, 0));
        $seva = $this->timeSlotSeva();
        $date = now()->addDay()->toDateString();

        $response = $this->getJson("/api/v1/sevas/{$seva->id}/slots?date={$date}");

        $response->assertOk()->assertJsonStructure([
            'data' => ['date', 'slots', 'booked', 'slot_type', 'slot_duration_minutes', 'max_bookings_per_slot'],
        ]);

        $data = $response->json('data');
        $this->assertSame($date, $data['date']);
        $this->assertSame('time_slots', $data['slot_type']);
        $this->assertSame(60, $data['slot_duration_minutes']);
        $this->assertSame(1, $data['max_bookings_per_slot']);
        // `slots` = bookable times, `booked` = not bookable — unchanged.
        $this->assertSame(['06:00', '18:00'], $data['slots']);
        $this->assertSame([], $data['booked']);
        // Additive.
        $this->assertCount(2, $data['slot_details']);
    }

    public function test_full_day_seva_slots_still_return_the_sentinel(): void
    {
        $seva = SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'full_day',
                'max_bookings_per_slot' => 1,
                'acceptance_period' => ['type' => 'perpetual'],
                'full_day_days' => [],
                'blackout_dates' => [],
            ],
        ]);
        $date = now()->addDays(3)->toDateString();

        $data = $this->getJson("/api/v1/sevas/{$seva->id}/slots?date={$date}")->json('data');

        $this->assertSame('full_day', $data['slot_type']);
        $this->assertSame(['full_day'], $data['slots']);
        $this->assertSame([], $data['booked']);
    }

    // ── GET /api/v1/halls/{hall}/available-dates ─────────────────────

    public function test_hall_available_dates_keeps_all_six_original_keys(): void
    {
        $hall = HallFactory::new()->create();
        $month = now()->format('Y-m');

        $response = $this->getJson("/api/v1/halls/{$hall->id}/available-dates?month={$month}");

        $response->assertOk()->assertJsonStructure([
            'data' => ['month', 'dates' => [[
                'date', 'full_day_available', 'morning_available', 'evening_available',
                'blocked', 'blocked_reason',
            ]]],
        ]);

        $first = $response->json('data.dates.0');
        $this->assertIsBool($first['full_day_available']);
        // morning/evening are still mirrors of full_day (old builds render
        // them); `blocked` still means "admin blockout", NOT "booked".
        $this->assertSame($first['full_day_available'], $first['morning_available']);
        $this->assertSame($first['full_day_available'], $first['evening_available']);
        $this->assertFalse($first['blocked']);
        $this->assertNull($first['blocked_reason']);
        // Additive.
        $this->assertArrayHasKey('reason_code', $first);
    }

    public function test_hall_booked_date_reports_unavailable_but_not_blocked(): void
    {
        $hall = HallFactory::new()->create();
        $target = now()->addDays(5)->toDateString();
        HallBookingFactory::new()->forHall($hall)->range($target, $target)->create(['status' => 'confirmed']);

        $dates = collect($this->getJson("/api/v1/halls/{$hall->id}/available-dates?month=".now()->format('Y-m'))->json('data.dates'))
            ->keyBy('date');

        // Only true when the ADMIN blocked it — a booking must not flip it.
        $this->assertFalse($dates[$target]['full_day_available']);
        $this->assertFalse($dates[$target]['blocked']);
        $this->assertSame('hall_booked', $dates[$target]['reason_code']);
    }

    public function test_hall_blackout_date_is_omitted_from_the_month_list(): void
    {
        // An admin blackout means the hall is NOT on offer that day, so the
        // date carousel must not render a chip for it at all (see
        // App\Enums\UnavailableReason::display). The keys are unchanged —
        // the list is simply shorter, which is exactly what the shipped
        // app build needs in order to stop showing greyed-out noise.
        $target = now()->addDays(6)->toDateString();
        $hall = HallFactory::new()->create([
            'blackout_dates' => [['date' => $target, 'reason' => 'Trust event']],
        ]);

        $dates = collect($this->getJson("/api/v1/halls/{$hall->id}/available-dates?month=".now()->format('Y-m'))->json('data.dates'))
            ->keyBy('date');

        $this->assertArrayNotHasKey($target, $dates->all());
        // Neighbouring dates are untouched.
        $this->assertTrue($dates[now()->addDays(7)->toDateString()]['full_day_available']);
    }

    public function test_hall_single_date_availability_still_reports_blocked_with_the_admin_reason(): void
    {
        // The single-date endpoint answers about a date the CALLER named,
        // so it always responds and `blocked` / `blocked_reason` keep their
        // exact old meaning. It carries no `display` — that key means
        // "should this entry exist in a list" and belongs to list rows.
        $target = now()->addDays(6)->toDateString();
        $hall = HallFactory::new()->create([
            'blackout_dates' => [['date' => $target, 'reason' => 'Trust event']],
        ]);

        $data = $this->getJson("/api/v1/halls/{$hall->id}/availability?date={$target}")
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['full_day_available']);
        $this->assertTrue($data['blocked']);
        $this->assertSame('Trust event', $data['blocked_reason']);
        $this->assertSame('blackout', $data['reason_code']);
        $this->assertArrayNotHasKey('display', $data);
    }

    // ── GET /api/v1/halls/{hall}/availability ────────────────────────

    public function test_hall_single_date_availability_keeps_its_keys(): void
    {
        $hall = HallFactory::new()->create();
        $date = now()->addDays(4)->toDateString();

        $this->getJson("/api/v1/halls/{$hall->id}/availability?date={$date}")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'date', 'full_day_available', 'morning_available', 'evening_available',
                'blocked', 'blocked_reason',
            ]])
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.full_day_available', true);
    }

    // ── GET /api/v1/halls (list) ─────────────────────────────────────

    public function test_hall_list_keeps_its_keys_and_gains_max_booking_days(): void
    {
        HallFactory::new()->create();

        $response = $this->getJson('/api/v1/halls');

        $response->assertOk()->assertJsonStructure(['data' => [[
            'id', 'name', 'name_gu', 'name_hi', 'name_en',
            'description', 'capacity', 'price_per_day', 'amenities', 'rules', 'image_url', 'media',
        ]]]);
        $this->assertSame(1, $response->json('data.0.max_booking_days'));
    }

    // ── GET /hall-booking/check (web, un-wrapped) ────────────────────

    public function test_web_hall_check_stays_unwrapped_with_available_and_message(): void
    {
        $hall = HallFactory::new()->create();
        $date = now()->addDays(4)->toDateString();

        $response = $this->getJson("/hall-booking/check?hall_id={$hall->id}&date={$date}");

        $response->assertOk();
        $json = $response->json();
        // NOT wrapped in {success,message,data} — the Alpine picker reads
        // json.available directly.
        $this->assertArrayNotHasKey('data', $json);
        $this->assertTrue($json['available']);
        $this->assertIsString($json['message']);
        // Additive.
        $this->assertSame(1, $json['days']);
        $this->assertSame(5000.0, (float) $json['total_amount']);
    }

    // ── new endpoints ────────────────────────────────────────────────

    public function test_seva_next_available_endpoint_shape(): void
    {
        $seva = $this->timeSlotSeva();

        $this->getJson("/api/v1/sevas/{$seva->id}/next-available")
            ->assertOk()
            ->assertJsonStructure(['data' => ['found', 'date', 'slot_time', 'slot_type', 'label']])
            ->assertJsonPath('data.found', true);
    }

    public function test_hall_next_available_and_range_quote_shape(): void
    {
        $hall = HallFactory::new()->multiDay(5)->create(['price_per_day' => 5000]);

        $this->getJson("/api/v1/halls/{$hall->id}/next-available")
            ->assertOk()
            ->assertJsonStructure(['data' => ['found', 'date', 'end_date', 'label']])
            ->assertJsonPath('data.found', true);

        $start = now()->addDays(20)->toDateString();
        $end = now()->addDays(22)->toDateString();

        $quote = $this->getJson("/api/v1/halls/{$hall->id}/range-quote?start={$start}&end={$end}")
            ->assertOk()
            ->assertJsonPath('data.days', 3)
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.amount_paise', 1500000)
            ->json('data');

        // Server-authoritative: 3 × ₹5000. (JSON drops the .0, so compare
        // numerically rather than with assertSame.)
        $this->assertEquals(15000.0, $quote['total_amount']);
        $this->assertEquals(5000.0, $quote['price_per_day']);
    }
}
