<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\SevaSlotService;
use Database\Factories\DevoteeFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * An abandoned payment must not keep a seva slot to itself (2026-08-29).
 *
 * A booking is written as `pending` the moment the devotee is handed to
 * Razorpay, so two people cannot pay for one slot at once. That hold used to
 * have no expiry in the availability queries: a devotee who closed the
 * Razorpay sheet locked the slot — for themselves and for everyone else, along
 * with the seva's linked products — until `bookings:clean-stale` swept it half
 * an hour later. The retry they attempted immediately was refused by their own
 * abandoned attempt.
 *
 * Two rules now, and this file holds both to the fire:
 *   1. past the hold window, a pending booking stops counting for ANYONE;
 *   2. the devotee who abandoned it can retry AT ONCE, because re-submitting
 *      the same seva + date + slot releases their own hold first.
 *
 * The clock is pinned to mid-month throughout: these assertions are about a
 * date a few days out, which in the last week of a month would fall outside
 * the queried month and make the test pass for the wrong reason.
 */
class SevaAbandonedHoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now()->startOfMonth()->addDays(9)->setTime(9, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function slots(): SevaSlotService
    {
        return app(SevaSlotService::class);
    }

    /** A one-slot-per-day seva, so a single booking fills it. */
    private function seva(): Seva
    {
        return SevaFactory::new()->create([
            'requires_booking' => true,
            'slot_config' => [
                'version' => 2,
                'slot_type' => 'time_slots',
                'slot_duration_minutes' => 60,
                'max_bookings_per_slot' => 1,
                'booking_cutoff_hours' => 0,
                'acceptance_period' => ['type' => 'perpetual', 'start_date' => null, 'end_date' => null],
                'weekly_schedule' => ['default' => ['10:00']],
                'blackout_dates' => [],
            ],
        ]);
    }

    private function date(): string
    {
        return Carbon::now()->addDays(3)->toDateString();
    }

    public function test_a_fresh_pending_booking_still_holds_its_slot(): void
    {
        $seva = $this->seva();

        SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $this->date(),
            'slot_time' => '10:00',
            'status' => 'pending',
            'created_at' => now(),
        ]);

        // The whole point of the hold: someone is mid-Razorpay right now.
        $this->assertNotNull(
            $this->slots()->validateBooking($seva, $this->date(), '10:00'),
            'a payment in flight must still reserve the slot',
        );
    }

    public function test_a_pending_booking_past_the_hold_window_frees_the_slot(): void
    {
        $seva = $this->seva();

        $booking = SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $this->date(),
            'slot_time' => '10:00',
            'status' => 'pending',
        ]);

        // Older than the hold, but not yet swept by bookings:clean-stale —
        // exactly the window devotees were being turned away in.
        $booking->forceFill([
            'created_at' => now()->subMinutes(SevaSlotService::DEFAULT_HOLD_MINUTES + 1),
        ])->save();

        $this->assertNull(
            $this->slots()->validateBooking($seva, $this->date(), '10:00'),
            'an abandoned attempt must stop blocking the slot once its payment window has passed',
        );

        // …and the same must be true of the locked re-check the insert runs,
        // or the devotee is shown a free slot and then refused at the till.
        $this->assertTrue($this->slots()->hasSlotCapacityForUpdate($seva, $this->date(), '10:00'));
    }

    public function test_a_confirmed_booking_holds_the_slot_however_old_it_is(): void
    {
        $seva = $this->seva();

        $booking = SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $this->date(),
            'slot_time' => '10:00',
            'status' => 'confirmed',
        ]);
        $booking->forceFill(['created_at' => now()->subYear()])->save();

        $this->assertNotNull(
            $this->slots()->validateBooking($seva, $this->date(), '10:00'),
            'the hold window applies to unpaid attempts only — a paid booking never expires',
        );
    }

    public function test_the_devotee_who_abandoned_can_retry_immediately(): void
    {
        $seva = $this->seva();
        $devotee = DevoteeFactory::new()->create();

        $abandoned = SevaBookingFactory::new()->forSeva($seva)->forDevotee($devotee)->create([
            'booking_date' => $this->date(),
            'slot_time' => '10:00',
            'status' => 'pending',
            'created_at' => now(),
        ]);

        // Still inside the hold window, so without the release below this
        // devotee would be blocked by their own ghost.
        $this->assertNotNull($this->slots()->validateBooking($seva, $this->date(), '10:00'));

        $released = $this->slots()->releaseOwnPendingHold($seva, (string) $devotee->id, $this->date(), '10:00');

        $this->assertSame(1, $released);
        $this->assertSame('cancelled', $abandoned->fresh()->status->value);
        $this->assertNull(
            $this->slots()->validateBooking($seva, $this->date(), '10:00'),
            'a devotee re-submitting the same seva, date and slot is retrying — their own abandoned attempt must step aside',
        );
    }

    public function test_the_release_never_touches_another_devotee_or_another_slot(): void
    {
        $seva = $this->seva();
        $mine = DevoteeFactory::new()->create();
        $theirs = DevoteeFactory::new()->create();

        $someoneElse = SevaBookingFactory::new()->forSeva($seva)->forDevotee($theirs)->create([
            'booking_date' => $this->date(),
            'slot_time' => '10:00',
            'status' => 'pending',
        ]);

        $myOtherDay = SevaBookingFactory::new()->forSeva($seva)->forDevotee($mine)->create([
            'booking_date' => Carbon::now()->addDays(4)->toDateString(),
            'slot_time' => '10:00',
            'status' => 'pending',
        ]);

        $this->slots()->releaseOwnPendingHold($seva, (string) $mine->id, $this->date(), '10:00');

        $this->assertSame('pending', $someoneElse->fresh()->status->value, "another devotee's attempt is not mine to cancel");
        $this->assertSame('pending', $myOtherDay->fresh()->status->value, 'a different date is a different booking');
    }

    public function test_the_month_list_offers_a_date_whose_only_booking_was_abandoned(): void
    {
        $seva = $this->seva();
        $date = $this->date();

        $booking = SevaBookingFactory::new()->forSeva($seva)->create([
            'booking_date' => $date,
            'slot_time' => '10:00',
            'status' => 'pending',
        ]);
        $booking->forceFill([
            'created_at' => now()->subMinutes(SevaSlotService::DEFAULT_HOLD_MINUTES + 1),
        ])->save();

        // This is the query the app and the website both render the calendar
        // from — the surface the devotee actually saw the slot missing on.
        $rows = collect($this->slots()->getDateAvailabilityInRange(
            $seva,
            Carbon::parse($date)->startOfDay(),
            Carbon::parse($date)->endOfDay(),
        ));

        $row = $rows->firstWhere('date', $date);

        $this->assertNotNull($row, 'the queried date must appear in the range');
        $this->assertTrue($row['available'], 'an abandoned attempt must not grey out the date on the calendar');
        $this->assertSame(0, SevaBooking::where('status', 'confirmed')->count());
    }
}
