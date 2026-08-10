<?php

namespace Tests\Feature;

use App\Enums\UnavailableReason;
use App\Models\Hall;
use App\Models\Seva;
use App\Models\SevaSlotPool;
use Database\Factories\DevoteeFactory;
use Database\Factories\HallBookingFactory;
use Database\Factories\HallFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HIDE vs BADGE — the distinction item 4.1 originally collapsed.
 *
 * Two different things make a date unbookable and they must NOT look the
 * same to a devotee:
 *
 *   • STRUCTURALLY NOT OFFERED — wrong weekday, blackout, outside the
 *     acceptance window, no slots configured, hall closed that weekday.
 *     Never bookable ⇒ ABSENT from the payload entirely.
 *   • OFFERED BUT TAKEN — a real bookable date/slot someone already
 *     booked, or one the cut-off just closed. ⇒ PRESENT and flagged.
 *
 * The rule lives in App\Enums\UnavailableReason::display(). These tests
 * pin it at the payload boundary (which is what web + app both consume)
 * for seva day-specific, seva slot pools, hall single-day and hall
 * multi-day — and re-assert that hiding a date is never a substitute for
 * server-side enforcement.
 */
class AvailabilityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Pin the clock so "today" is stable for both Laravel rules and Carbon. */
    private function pinNow(): void
    {
        Carbon::setTestNow(Carbon::today()->setTime(1, 0, 0));
    }

    /** The next occurrence of $weekday strictly after today. */
    private function nextWeekday(string $weekday): Carbon
    {
        $d = Carbon::today()->addDay();
        while (strtolower($d->format('l')) !== strtolower($weekday)) {
            $d->addDay();
        }

        return $d;
    }

    /** @return array<string, array<string, mixed>> days_detail keyed by date */
    private function sevaDays(Seva $seva, string $month): array
    {
        $rows = $this->getJson("/api/v1/sevas/{$seva->id}/available-dates?month={$month}")
            ->assertOk()
            ->json('data.days_detail');

        return collect($rows)->keyBy('date')->all();
    }

    /** @return array<string, array<string, mixed>> hall dates keyed by date */
    private function hallDays(Hall $hall, string $month): array
    {
        $rows = $this->getJson("/api/v1/halls/{$hall->id}/available-dates?month={$month}")
            ->assertOk()
            ->json('data.dates');

        return collect($rows)->keyBy('date')->all();
    }

    // ─────────────────────────────────────────────────────────────────
    // The mapping itself
    // ─────────────────────────────────────────────────────────────────

    public function test_every_reason_makes_an_explicit_hide_or_badge_choice(): void
    {
        $hidden = [
            UnavailableReason::WeekdayClosed,
            UnavailableReason::Blackout,
            UnavailableReason::OutsidePeriod,
            UnavailableReason::NoSlots,
            UnavailableReason::PastDate,
        ];
        $badged = [
            UnavailableReason::Full,
            UnavailableReason::HallBooked,
            UnavailableReason::Elapsed,
            UnavailableReason::Cutoff,
            UnavailableReason::RangeTooLong,
        ];

        foreach ($hidden as $reason) {
            $this->assertTrue($reason->hidesEntry(), "{$reason->value} must be hidden");
            $this->assertSame(UnavailableReason::DISPLAY_HIDE, $reason->display());
        }
        foreach ($badged as $reason) {
            $this->assertFalse($reason->hidesEntry(), "{$reason->value} must be shown with a badge");
            $this->assertSame(UnavailableReason::DISPLAY_BADGE, $reason->display());
        }

        // Nothing may be left undecided.
        $this->assertCount(count($hidden) + count($badged), UnavailableReason::cases());

        // A row with no reason is bookable; an unknown code from a newer
        // server is shown rather than silently dropped.
        $this->assertSame(UnavailableReason::DISPLAY_AVAILABLE, UnavailableReason::displayFor(null));
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, UnavailableReason::displayFor('something_new'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Seva — day-specific (full_day restricted to one weekday)
    // ─────────────────────────────────────────────────────────────────

    private function tuesdayOnlySeva(int $capacity = 1): Seva
    {
        return SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'full_day',
                'max_bookings_per_slot' => $capacity,
                'booking_cutoff_hours' => 0,
                'full_day_days' => ['tuesday'],
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'blackout_dates' => [],
            ],
        ]);
    }

    public function test_day_specific_seva_hides_every_other_weekday(): void
    {
        $this->pinNow();
        $seva = $this->tuesdayOnlySeva();

        $days = $this->sevaDays($seva, now()->format('Y-m'));

        $this->assertNotEmpty($days, 'the month must still offer its Tuesdays');
        foreach ($days as $date => $row) {
            $this->assertSame(
                'tuesday',
                strtolower(Carbon::parse($date)->format('l')),
                "a non-Tuesday ({$date}) leaked into the payload for a Tuesday-only seva",
            );
        }
    }

    public function test_day_specific_seva_shows_a_booked_tuesday_flagged_not_hidden(): void
    {
        $this->pinNow();
        $seva = $this->tuesdayOnlySeva();

        $tuesday = $this->nextWeekday('tuesday');
        // Keep the assertion inside the month the picker asks for.
        if ($tuesday->month !== now()->month) {
            $this->markTestSkipped('next Tuesday falls in the following month');
        }

        // Before: the date is on offer.
        $before = $this->sevaDays($seva, now()->format('Y-m'));
        $this->assertArrayHasKey($tuesday->toDateString(), $before);
        $this->assertTrue($before[$tuesday->toDateString()]['available']);

        SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $tuesday->toDateString(),
            'slot_time' => 'full_day',
            'status' => 'confirmed',
        ]);

        // After: still PRESENT, flagged `full`, badge-class — not hidden.
        $after = $this->sevaDays($seva, now()->format('Y-m'));
        $row = $after[$tuesday->toDateString()] ?? null;

        $this->assertNotNull($row, 'a booked-but-offerable date must stay visible');
        $this->assertFalse($row['available']);
        $this->assertSame(UnavailableReason::Full->value, $row['reason_code']);
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, $row['display']);
        $this->assertNotEmpty($row['reason']);

        // …and it is gone from the legacy bookable-only list, so old
        // clients still can't tap it.
        $dates = $this->getJson("/api/v1/sevas/{$seva->id}/available-dates?month=".now()->format('Y-m'))
            ->json('data.dates');
        $this->assertNotContains($tuesday->toDateString(), $dates);
    }

    public function test_seva_outside_its_acceptance_period_is_hidden_not_badged(): void
    {
        $this->pinNow();
        $seva = SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'acceptance_period' => [
                    'type' => 'range',
                    'start_date' => now()->addYear()->toDateString(),
                    'end_date' => now()->addYear()->addMonth()->toDateString(),
                ],
                'weekly_schedule' => ['default' => ['06:00']],
                'blackout_dates' => [],
            ],
        ]);

        $this->assertSame([], $this->sevaDays($seva, now()->format('Y-m')));
    }

    public function test_seva_blackout_date_is_hidden_but_a_full_slot_is_badged(): void
    {
        $this->pinNow();
        $blackout = now()->addDays(3)->toDateString();
        $other = now()->addDays(4)->toDateString();

        $seva = SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'weekly_schedule' => ['default' => ['06:00', '18:00']],
                'blackout_dates' => [['date' => $blackout, 'reason' => 'Temple closed']],
            ],
        ]);

        $days = $this->sevaDays($seva, now()->format('Y-m'));
        $this->assertArrayNotHasKey($blackout, $days, 'an admin blackout date must not be offered at all');
        $this->assertArrayHasKey($other, $days);

        // One of the two slots on $other is taken → the DATE stays open,
        // and the SLOT is present-but-flagged.
        SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $other,
            'slot_time' => '06:00',
            'status' => 'confirmed',
        ]);

        $slotData = $this->getJson("/api/v1/sevas/{$seva->id}/slots?date={$other}")->assertOk()->json('data');

        $this->assertSame(['18:00'], $slotData['slots']);
        $this->assertSame(['06:00'], $slotData['booked']);

        $details = collect($slotData['slot_details'])->keyBy('time');
        $this->assertCount(2, $details, 'both slots must be rendered — one open, one badged');
        $this->assertFalse($details['06:00']['available']);
        $this->assertSame(UnavailableReason::Full->value, $details['06:00']['reason_code']);
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, $details['06:00']['display']);
        $this->assertTrue($details['18:00']['available']);
        $this->assertSame(UnavailableReason::DISPLAY_AVAILABLE, $details['18:00']['display']);

        // The blacked-out date offers no slots at all — nothing to badge.
        $blackoutSlots = $this->getJson("/api/v1/sevas/{$seva->id}/slots?date={$blackout}")->assertOk()->json('data');
        $this->assertSame([], $blackoutSlots['slot_details']);
        $this->assertTrue($blackoutSlots['blackout']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Seva — slot pool (config + capacity shared across member sevas)
    // ─────────────────────────────────────────────────────────────────

    /** @return array{0:Seva,1:Seva} two sevas sharing one Wednesday-only pool */
    private function wednesdayOnlyPool(): array
    {
        $pool = SevaSlotPool::create([
            'name' => 'Wednesday pool',
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'full_day',
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'full_day_days' => ['wednesday'],
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'blackout_dates' => [],
            ],
        ]);

        return [
            SevaFactory::new()->create(['slot_pool_id' => $pool->id]),
            SevaFactory::new()->create(['slot_pool_id' => $pool->id]),
        ];
    }

    public function test_pooled_seva_hides_days_the_pool_does_not_operate(): void
    {
        $this->pinNow();
        [$sevaA] = $this->wednesdayOnlyPool();

        $days = $this->sevaDays($sevaA, now()->format('Y-m'));

        $this->assertNotEmpty($days);
        foreach ($days as $date => $row) {
            $this->assertSame('wednesday', strtolower(Carbon::parse($date)->format('l')), "{$date} leaked");
        }
    }

    public function test_pooled_seva_badges_a_date_another_pool_member_booked(): void
    {
        $this->pinNow();
        [$sevaA, $sevaB] = $this->wednesdayOnlyPool();

        $wednesday = $this->nextWeekday('wednesday');
        if ($wednesday->month !== now()->month) {
            $this->markTestSkipped('next Wednesday falls in the following month');
        }

        // Booked against the OTHER member — capacity is pool-wide.
        SevaBookingFactory::new()->forSeva($sevaB)->create([
            'booking_date' => $wednesday->toDateString(),
            'slot_time' => 'full_day',
            'status' => 'pending',
        ]);

        $row = $this->sevaDays($sevaA, now()->format('Y-m'))[$wednesday->toDateString()] ?? null;

        $this->assertNotNull($row, 'a pool-booked Wednesday must still be shown');
        $this->assertFalse($row['available']);
        $this->assertSame(UnavailableReason::Full->value, $row['reason_code']);
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, $row['display']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Hall — single day
    // ─────────────────────────────────────────────────────────────────

    public function test_hall_blackout_date_and_weekday_closure_are_hidden(): void
    {
        $this->pinNow();
        $blackout = now()->addDays(6)->toDateString();
        $hall = HallFactory::new()->create([
            'blackout_dates' => [['date' => $blackout, 'reason' => 'Trust event']],
            'blackout_days' => ['sunday'],
        ]);

        $days = $this->hallDays($hall, now()->format('Y-m'));

        $this->assertArrayNotHasKey($blackout, $days, 'an admin blackout date is not on offer');
        foreach ($days as $date => $row) {
            $this->assertNotSame('sunday', strtolower(Carbon::parse($date)->format('l')), "{$date} leaked");
            // `blocked` only ever meant "admin blockout" — and those are
            // now hidden, so no surviving row may carry it.
            $this->assertFalse($row['blocked']);
        }
    }

    public function test_hall_booked_date_stays_visible_with_the_badge(): void
    {
        $this->pinNow();
        $hall = HallFactory::new()->create();
        $target = now()->addDays(5)->toDateString();

        $before = $this->hallDays($hall, now()->format('Y-m'));
        $this->assertArrayHasKey($target, $before);
        $this->assertTrue($before[$target]['full_day_available']);

        HallBookingFactory::new()->forHall($hall)->range($target, $target)->create(['status' => 'confirmed']);

        $row = $this->hallDays($hall, now()->format('Y-m'))[$target] ?? null;

        $this->assertNotNull($row, 'a booked hall date must be shown, not hidden');
        $this->assertFalse($row['full_day_available']);
        $this->assertFalse($row['blocked'], 'a booking is not an admin blockout');
        $this->assertSame(UnavailableReason::HallBooked->value, $row['reason_code']);
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, $row['display']);
    }

    public function test_hall_cutoff_dates_are_badged_not_hidden(): void
    {
        // 48h cut-off, clock pinned to 08:00 → tomorrow's 09:00 start is
        // inside the window, so tomorrow is offered-but-closed.
        Carbon::setTestNow(Carbon::today()->setTime(8, 0, 0));
        $hall = HallFactory::new()->withCutoff(48)->create();
        $tomorrow = now()->addDay()->toDateString();

        $row = $this->hallDays($hall, now()->format('Y-m'))[$tomorrow] ?? null;

        $this->assertNotNull($row, 'a cut-off date is a real date the hall offers');
        $this->assertFalse($row['full_day_available']);
        $this->assertSame(UnavailableReason::Cutoff->value, $row['reason_code']);
        $this->assertSame(UnavailableReason::DISPLAY_BADGE, $row['display']);
    }

    // ─────────────────────────────────────────────────────────────────
    // Hall — multi-day ranges
    // ─────────────────────────────────────────────────────────────────

    public function test_every_interior_day_of_a_multi_day_booking_is_badged(): void
    {
        $this->pinNow();
        $hall = HallFactory::new()->multiDay(7)->create();

        $start = now()->addDays(8);
        $end = now()->addDays(10);
        if ($end->month !== now()->month) {
            $this->markTestSkipped('the range crosses into the next month');
        }

        HallBookingFactory::new()->forHall($hall)
            ->range($start->toDateString(), $end->toDateString())
            ->create(['status' => 'confirmed', 'days_count' => 3]);

        $days = $this->hallDays($hall, now()->format('Y-m'));

        foreach ([$start, $start->copy()->addDay(), $end] as $covered) {
            $row = $days[$covered->toDateString()] ?? null;
            $this->assertNotNull($row, "{$covered->toDateString()} must still be rendered");
            $this->assertFalse($row['full_day_available']);
            $this->assertSame(UnavailableReason::HallBooked->value, $row['reason_code']);
            $this->assertSame(UnavailableReason::DISPLAY_BADGE, $row['display']);
        }

        // The day after the range is untouched.
        $free = $end->copy()->addDay()->toDateString();
        $this->assertTrue($days[$free]['full_day_available']);
    }

    public function test_hidden_weekday_inside_a_requested_range_is_still_rejected_server_side(): void
    {
        // Hiding a date in the UI is not a fix — a crafted POST spanning a
        // weekday the hall never opens must still be refused.
        $this->pinNow();
        $hall = HallFactory::new()->multiDay(7)->create(['blackout_days' => ['sunday']]);

        $sunday = $this->nextWeekday('sunday');
        $start = $sunday->copy()->subDay();
        $end = $sunday->copy()->addDay();

        $devotee = DevoteeFactory::new()->create();
        Sanctum::actingAs($devotee);

        $this->postJson("/api/v1/halls/{$hall->id}/book", [
            'booking_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'purpose' => 'Wedding',
            'contact_name' => 'Test',
            'contact_phone' => '9876543210',
        ])->assertStatus(409);

        $this->assertDatabaseCount('temple_hall_bookings', 0);
    }

    public function test_hidden_seva_weekday_is_still_rejected_server_side(): void
    {
        $this->pinNow();
        $seva = $this->tuesdayOnlySeva();

        // A Wednesday — never rendered, but a stale page could still POST it.
        $wednesday = $this->nextWeekday('wednesday');

        $devotee = DevoteeFactory::new()->create();
        Sanctum::actingAs($devotee);

        $this->postJson("/api/v1/sevas/{$seva->id}/book", [
            'booking_date' => $wednesday->toDateString(),
            'slot_time' => 'full_day',
            'quantity' => 1,
        ])->assertStatus(409);

        $this->assertDatabaseCount('temple_seva_bookings', 0);
    }

    /** Guard against the enum drifting out of sync with the lang file. */
    public function test_every_reason_has_a_localized_label_in_all_three_languages(): void
    {
        foreach (['en', 'gu', 'hi'] as $locale) {
            app()->setLocale($locale);
            foreach (UnavailableReason::cases() as $reason) {
                $label = $reason->label();
                $this->assertNotSame(
                    'availability.reason.'.$reason->value,
                    $label,
                    "missing {$locale} translation for {$reason->value}",
                );
            }
        }
        app()->setLocale(config('app.locale'));
    }
}
