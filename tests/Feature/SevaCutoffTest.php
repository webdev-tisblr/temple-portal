<?php

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SevaSlotPool;
use App\Services\RazorpayService;
use App\Services\SevaSlotService;
use Database\Factories\DevoteeFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Item 4.3 — admin-configurable booking cut-off, enforced SERVER-SIDE
 * (not merely hidden in the UI), plus the pre-existing bug where
 * validateBooking() never applied the elapsed-slot filter so a stale page
 * or a crafted POST could book a slot that had already started.
 *
 * Time is pinned with Carbon::setTestNow to TODAY at a fixed hour, so the
 * `after_or_equal:today` request rule (which reads the real clock) stays
 * consistent with the service's view of "now".
 */
class SevaCutoffTest extends TestCase
{
    use RefreshDatabase;

    private function slots(): SevaSlotService
    {
        return app(SevaSlotService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Today at $hour:00, so "today" means the same day for Laravel and Carbon. */
    private function pinNow(int $hour): void
    {
        Carbon::setTestNow(Carbon::today()->setTime($hour, 0, 0));
    }

    private function timeSlotSeva(int $cutoffHours = 0, array $times = ['06:00', '10:00', '18:00', '23:00']): Seva
    {
        return SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'slot_duration_minutes' => 60,
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => $cutoffHours,
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'weekly_schedule' => ['default' => $times],
                'blackout_dates' => [],
            ],
        ]);
    }

    private function fakeRazorpay(): void
    {
        $stub = new class extends RazorpayService
        {
            public function __construct() {}

            public function createOrder(int $amountInPaise, string $receipt, array $notes = []): object
            {
                return (object) ['id' => 'order_test_'.Str::random(10), 'amount' => $amountInPaise];
            }
        };

        $this->app->instance(RazorpayService::class, $stub);
    }

    private function bookApi(Seva $seva, Devotee $devotee, string $date, ?string $slot)
    {
        Sanctum::actingAs($devotee);

        return $this->postJson("/api/v1/sevas/{$seva->id}/book", array_filter([
            'booking_date' => $date,
            'slot_time' => $slot,
            'quantity' => 1,
        ]));
    }

    // ── (a) a slot inside now+buffer is rejected SERVER-SIDE ─────────

    public function test_slot_inside_the_cutoff_window_is_rejected_by_validate_booking(): void
    {
        $this->pinNow(12);
        $seva = $this->timeSlotSeva(cutoffHours: 8); // cut-off moment = 20:00
        $today = Carbon::today()->toDateString();

        // 18:00 is in the future but inside now+8h → must be refused.
        $this->assertNotNull($this->slots()->validateBooking($seva, $today, '18:00'));
        // 23:00 is beyond the cut-off → still bookable.
        $this->assertNull($this->slots()->validateBooking($seva, $today, '23:00'));
    }

    public function test_posting_a_slot_inside_the_cutoff_window_returns_409(): void
    {
        $this->pinNow(12);
        $this->fakeRazorpay();
        $seva = $this->timeSlotSeva(cutoffHours: 8);
        $devotee = DevoteeFactory::new()->create();
        $today = Carbon::today()->toDateString();

        $this->bookApi($seva, $devotee, $today, '18:00')->assertStatus(409);
        $this->assertSame(0, SevaBooking::count());

        // …and the same POST for an open slot still succeeds, so the rule
        // is a cut-off and not a blanket block.
        $this->bookApi($seva, $devotee, $today, '23:00')->assertOk();
        $this->assertSame(1, SevaBooking::count());
    }

    public function test_cutoff_slots_are_shown_as_unavailable_rather_than_hidden(): void
    {
        $this->pinNow(12);
        $seva = $this->timeSlotSeva(cutoffHours: 8);
        $today = Carbon::today()->toDateString();

        $availability = $this->slots()->getSlotAvailability($seva, $today);

        // Item 4.1: nothing is dropped — every configured slot is present.
        $this->assertSame(['23:00'], $availability['available']);
        $this->assertSame(['06:00', '10:00', '18:00'], $availability['booked']);
        $this->assertCount(4, $availability['slot_details']);

        $byTime = collect($availability['slot_details'])->keyBy('time');
        $this->assertSame('elapsed', $byTime['06:00']['reason_code']);
        $this->assertSame('elapsed', $byTime['10:00']['reason_code']);
        $this->assertSame('cutoff', $byTime['18:00']['reason_code']);
        $this->assertNull($byTime['23:00']['reason_code']);
        $this->assertTrue($byTime['23:00']['available']);
    }

    // ── (b) an elapsed slot cannot be booked ─────────────────────────

    public function test_elapsed_slot_is_rejected_by_validate_booking_even_with_no_cutoff(): void
    {
        $this->pinNow(12);
        $seva = $this->timeSlotSeva(cutoffHours: 0);
        $today = Carbon::today()->toDateString();

        // The pre-existing bug: this used to return null (i.e. "book it").
        $this->assertNotNull($this->slots()->validateBooking($seva, $today, '10:00'));
        $this->assertNull($this->slots()->validateBooking($seva, $today, '18:00'));
    }

    public function test_posting_an_elapsed_slot_returns_409_and_creates_nothing(): void
    {
        $this->pinNow(12);
        $this->fakeRazorpay();
        $seva = $this->timeSlotSeva(cutoffHours: 0);
        $devotee = DevoteeFactory::new()->create();

        $this->bookApi($seva, $devotee, Carbon::today()->toDateString(), '10:00')->assertStatus(409);
        $this->assertSame(0, SevaBooking::count());
    }

    public function test_future_dates_are_unaffected_when_no_cutoff_is_configured(): void
    {
        $this->pinNow(12);
        $seva = $this->timeSlotSeva(cutoffHours: 0);
        $tomorrow = Carbon::today()->addDay()->toDateString();

        $availability = $this->slots()->getSlotAvailability($seva, $tomorrow);
        $this->assertSame(['06:00', '10:00', '18:00', '23:00'], $availability['available']);
        $this->assertSame([], $availability['booked']);
    }

    // ── full-day anchor ──────────────────────────────────────────────

    public function test_full_day_seva_uses_its_anchor_time_for_the_cutoff(): void
    {
        $this->pinNow(12);
        $seva = SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'full_day',
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 24,
                'reminder_anchor_time' => '09:00',
                'acceptance_period' => ['type' => 'perpetual'],
                'full_day_days' => [],
                'blackout_dates' => [],
            ],
        ]);

        // now = today 12:00, cut-off moment = tomorrow 12:00.
        // Tomorrow's anchor is 09:00 → inside the window → blocked.
        $tomorrow = Carbon::today()->addDay()->toDateString();
        $this->assertNotNull($this->slots()->validateBooking($seva, $tomorrow, 'full_day'));

        // The day after starts at 09:00 > tomorrow 12:00 → open.
        $dayAfter = Carbon::today()->addDays(2)->toDateString();
        $this->assertNull($this->slots()->validateBooking($seva, $dayAfter, 'full_day'));
    }

    public function test_full_day_seva_without_a_cutoff_is_still_bookable_later_the_same_day(): void
    {
        $this->pinNow(14); // after the 09:00 anchor
        $seva = SevaFactory::new()->create([
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'full_day',
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'acceptance_period' => ['type' => 'perpetual'],
                'full_day_days' => [],
                'blackout_dates' => [],
            ],
        ]);

        // No behaviour change for the default config — the elapsed rule is
        // deliberately scoped to real HH:MM slots.
        $this->assertNull($this->slots()->validateBooking($seva, Carbon::today()->toDateString(), 'full_day'));
    }

    // ── pooled sevas inherit the pool's cut-off ──────────────────────

    public function test_pooled_seva_inherits_the_pools_cutoff(): void
    {
        $this->pinNow(12);

        $pool = SevaSlotPool::create([
            'name' => 'Shared pool',
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'slot_duration_minutes' => 60,
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 8,
                'acceptance_period' => ['type' => 'perpetual'],
                'weekly_schedule' => ['default' => ['18:00', '23:00']],
                'blackout_dates' => [],
            ],
        ]);

        // The seva's OWN config says "no cut-off" — the pool must win,
        // which is exactly why the setting lives in slot_config and not in
        // a column on temple_sevas.
        $seva = SevaFactory::new()->create([
            'slot_pool_id' => $pool->id,
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'booking_cutoff_hours' => 0,
                'weekly_schedule' => ['default' => ['18:00', '23:00']],
            ],
        ]);

        $today = Carbon::today()->toDateString();
        $this->assertSame(8, $this->slots()->cutoffHours($this->slots()->configFor($seva)));
        $this->assertNotNull($this->slots()->validateBooking($seva, $today, '18:00'));
    }

    // ── date-level rollup ────────────────────────────────────────────

    public function test_a_day_whose_only_open_slots_are_elapsed_is_flagged_not_dropped(): void
    {
        $this->pinNow(12);
        $seva = $this->timeSlotSeva(cutoffHours: 0, times: ['06:00', '10:00']);
        $today = Carbon::today();

        $rows = $this->slots()->getDateAvailabilityInRange($seva, $today->copy(), $today->copy());

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['available']);
        $this->assertSame('elapsed', $rows[0]['reason_code']);
    }
}
