<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Services\HallAvailabilityService;
use App\Services\HallCancellationService;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Devotee-initiated hall cancellation REQUESTS (2026-08-12).
 *
 * The load-bearing rule: a request is not a cancellation. The booking keeps
 * its `confirmed` status and the hall stays blocked until the trust decides —
 * otherwise a merely requested cancellation would release the date and let
 * someone else book it out from under a request the trust then declines.
 */
class HallCancellationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = []): HallBooking
    {
        $hall = Hall::create([
            'name' => 'Test Hall',
            'name_gu' => 'ટેસ્ટ હૉલ',
            'price_per_day' => 5000,
            'capacity' => 100,
            'is_active' => true,
        ]);

        return HallBooking::create(array_merge([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'hall_id' => $hall->id,
            'booking_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(20)->toDateString(),
            'days_count' => 1,
            'booking_type' => 'full_day',
            'purpose' => 'Wedding',
            'contact_name' => 'Ramesh',
            'contact_phone' => '9876543210',
            'total_amount' => 5000,
            'status' => 'confirmed',
            'payment_id' => PaymentFactory::new()->create()->id,
        ], $overrides));
    }

    public function test_a_confirmed_future_booking_can_be_requested(): void
    {
        $booking = $this->booking();
        $this->assertTrue(app(HallCancellationService::class)->canRequest($booking));
    }

    public function test_requesting_does_not_cancel_and_does_not_free_the_date(): void
    {
        $booking = $this->booking();
        $start = $booking->booking_date->toDateString();

        $this->assertTrue(app(HallCancellationService::class)->request($booking, 'Change of plans'));

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status, 'a REQUEST must never cancel the booking');
        $this->assertNotNull($booking->cancel_requested_at);
        $this->assertSame('Change of plans', $booking->cancel_reason);
        $this->assertNull($booking->cancel_responded_at);

        // The whole point: the hall is still taken.
        $verdict = app(HallAvailabilityService::class)->checkRange($booking->hall, $start, $start);
        $this->assertFalse($verdict['ok'], 'the date must stay blocked while the trust decides');
    }

    public function test_a_second_request_is_rejected_while_one_is_open(): void
    {
        $booking = $this->booking();
        $service = app(HallCancellationService::class);

        $this->assertTrue($service->request($booking));
        $this->assertFalse($service->canRequest($booking->refresh()));
        $this->assertSame(
            HallCancellationService::REASON_ALREADY_REQUESTED,
            $service->ineligibilityReason($booking),
        );
        // Double-tap writes once — the second call is a no-op, not a second
        // notification to the trust.
        $this->assertFalse($service->request($booking));
    }

    public function test_a_declined_request_can_be_raised_again(): void
    {
        $booking = $this->booking();
        $service = app(HallCancellationService::class);

        $service->request($booking, 'first try');
        $booking->update(['cancel_responded_at' => now()]);   // admin declines

        $this->assertTrue($service->canRequest($booking->refresh()));
        $this->assertTrue($service->request($booking, 'second try'));
        $this->assertSame('second try', $booking->refresh()->cancel_reason);
        $this->assertNull($booking->cancel_responded_at, 'a new request re-opens the queue entry');
    }

    public function test_pending_and_cancelled_bookings_cannot_be_requested(): void
    {
        $service = app(HallCancellationService::class);

        foreach (['pending', 'cancelled', 'completed'] as $status) {
            $booking = $this->booking(['status' => $status]);
            $this->assertSame(
                HallCancellationService::REASON_NOT_CONFIRMED,
                $service->ineligibilityReason($booking),
                "status {$status} must not be cancellable by the devotee",
            );
        }
    }

    public function test_a_booking_that_has_already_started_cannot_be_requested(): void
    {
        $booking = $this->booking([
            'booking_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days_count' => 3,
        ]);

        $this->assertSame(
            HallCancellationService::REASON_ALREADY_STARTED,
            app(HallCancellationService::class)->ineligibilityReason($booking),
        );
    }

    public function test_api_rejects_a_request_for_someone_elses_booking(): void
    {
        $booking = $this->booking();
        $intruder = DevoteeFactory::new()->create();

        // 404, not 403 — a probe must not be able to tell "not yours" from
        // "does not exist" and enumerate other devotees' bookings.
        Sanctum::actingAs($intruder);
        $this->postJson("/api/v1/hall-bookings/{$booking->id}/cancel-request")
            ->assertNotFound();

        $this->assertNull($booking->refresh()->cancel_requested_at);
    }

    public function test_api_records_the_request_for_the_owner(): void
    {
        $booking = $this->booking();

        Sanctum::actingAs($booking->devotee);
        $this->postJson("/api/v1/hall-bookings/{$booking->id}/cancel-request", ['reason' => 'Family emergency'])
            ->assertOk()
            ->assertJsonPath('data.can_request_cancellation', false);

        $booking->refresh();
        $this->assertNotNull($booking->cancel_requested_at);
        $this->assertSame('Family emergency', $booking->cancel_reason);
        $this->assertSame('confirmed', $booking->status);
    }
}
