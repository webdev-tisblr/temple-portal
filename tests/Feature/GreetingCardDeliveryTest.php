<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\GreetingCardService;
use App\Services\PaymentCaptureService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Greeting cards (2026-08-13): campaign artwork, and seva cards timed to the
 * day of the seva rather than the moment of payment.
 *
 * The trust's governing requirement is that NOTHING sends on its own — every
 * card is gated on artwork being uploaded AND a notification template being
 * enabled. The first test asserts exactly that, because it is the promise most
 * easily broken by a later change.
 */
class GreetingCardDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
        // r2 (public) too: artwork() writes the template here, and an
        // unfaked disk is the REAL bucket — these tests were uploading
        // greeting-templates/test.png into production on every local run,
        // and failing on CI, where there are no R2 credentials to reach it
        // with, so the card never rendered and four assertions went red.
        Storage::fake('r2');
    }

    /** A 1x1 PNG is enough: these tests assert routing, not pixels. */
    private function artwork(): string
    {
        $path = 'greeting-templates/test.png';
        Storage::disk('r2')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        return $path;
    }

    private function campaignWithCard(): DonationCampaign
    {
        return DonationCampaign::create([
            'title_gu' => 'શ્રી રામ વાટિકા',
            'title_en' => 'Shree Ram Vatika',
            'slug' => 'shree-ram-vatika',
            'goal_amount' => 1000000,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
            'greeting_card_template' => $this->artwork(),
            'greeting_card_config' => ['overlays' => [
                ['field_key' => '_donor_name', 'type' => 'text', 'x' => 10, 'y' => 10, 'font_size' => 20],
                ['field_key' => '_campaign_title', 'type' => 'text', 'x' => 10, 'y' => 40, 'font_size' => 20],
            ]],
        ]);
    }

    private function captureDonationTo(?DonationCampaign $campaign): Donation
    {
        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => $payment->id,
            'campaign_id' => $campaign?->id,
            // Exactly what the donate form posts in campaign mode: the legacy
            // enum string, and NO donation_type_id.
            'donation_type' => $campaign ? 'campaign' : 'general',
            'donation_type_id' => null,
            'amount' => 5100,
        ]);

        app(PaymentCaptureService::class)->markCaptured($payment, null, 'cash');

        return $donation->fresh();
    }

    /**
     * THE CONTROL REQUIREMENT. Artwork alone must never put a message on a
     * devotee's phone — a template has to be enabled too.
     */
    public function test_a_card_is_generated_but_nothing_is_sent_without_an_enabled_template(): void
    {
        $this->assertSame(0, NotificationTemplate::where('key', 'donation.campaign.greeting_card')->count());

        $donation = $this->captureDonationTo($this->campaignWithCard());

        // The image exists...
        $this->assertNotNull($donation->greeting_card_path, 'the card itself should still be rendered');

        // ...and not a single message went out.
        $this->assertDatabaseMissing('temple_notification_logs', [
            'template_key' => 'donation.campaign.greeting_card',
        ]);
    }

    /** A campaign gift renders from the CAMPAIGN's artwork. */
    public function test_a_campaign_donation_now_produces_a_card(): void
    {
        $campaign = $this->campaignWithCard();
        $donation = $this->captureDonationTo($campaign);

        $this->assertNotNull($donation->greeting_card_path);
        $this->assertTrue(Storage::disk('r2_private')->exists($donation->greeting_card_path));

        // And the service agrees about where the artwork came from — the job
        // uses this to choose the campaign trigger over the donation one.
        $this->assertTrue(app(GreetingCardService::class)->cardIsFromCampaign($donation));
    }

    /** Unchanged behaviour: no artwork anywhere means no card, as before. */
    public function test_a_campaign_without_artwork_still_produces_nothing(): void
    {
        $campaign = DonationCampaign::create([
            'title_gu' => 'કોઈ કાર્ડ નથી',
            'slug' => 'no-card',
            'goal_amount' => 1000,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
            'is_active' => true,
        ]);

        $donation = $this->captureDonationTo($campaign);

        $this->assertNull($donation->greeting_card_path);
        $this->assertFalse(app(GreetingCardService::class)->cardIsFromCampaign($donation));
    }

    /** A plain donation with no type and no campaign is untouched. */
    public function test_an_ordinary_donation_without_a_type_is_unaffected(): void
    {
        $donation = $this->captureDonationTo(null);

        $this->assertNull($donation->greeting_card_path);
    }

    // ── Seva cards: timing ────────────────────────────────────────────────

    private function sevaBooking(string $date, bool $withArtwork = true): SevaBooking
    {
        $seva = SevaFactory::new()->create(array_filter([
            'greeting_card_template' => $withArtwork ? $this->artwork() : null,
            'greeting_card_config' => $withArtwork
                ? ['overlays' => [['field_key' => '_donor_name', 'type' => 'text', 'x' => 5, 'y' => 5, 'font_size' => 18]]]
                : null,
        ]));

        $payment = PaymentFactory::new()->create(['status' => 'created']);

        return SevaBookingFactory::new()->create([
            'seva_id' => $seva->id,
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'payment_id' => $payment->id,
            'booking_date' => $date,
            'status' => 'pending',
        ]);
    }

    /**
     * The point of the change: a seva booked for next week must NOT be carded
     * the moment the devotee pays.
     */
    public function test_a_future_seva_sends_no_card_at_payment(): void
    {
        $booking = $this->sevaBooking(now()->addDays(7)->toDateString());

        app(PaymentCaptureService::class)->markCaptured($booking->payment, null, 'cash');

        $this->assertNull($booking->fresh()->greeting_card_path, 'card must wait for the seva day');
    }

    /** ...and the morning sweep on that day is what sends it. */
    public function test_the_day_of_sweep_cards_that_booking_on_the_day(): void
    {
        $date = now()->addDays(7)->toDateString();
        $booking = $this->sevaBooking($date);

        app(PaymentCaptureService::class)->markCaptured($booking->payment, null, 'cash');
        $this->assertNull($booking->fresh()->greeting_card_path);

        $this->artisan('seva:send-day-of-cards', ['--date' => $date])->assertExitCode(0);

        $this->assertNotNull($booking->fresh()->greeting_card_path, 'the sweep should card it on the day');
    }

    /**
     * A seva booked FOR TODAY is carded immediately, whatever the hour —
     * waiting for the next sweep would be a card a day late.
     */
    public function test_a_seva_booked_for_today_is_carded_at_payment(): void
    {
        $booking = $this->sevaBooking(now()->toDateString());

        app(PaymentCaptureService::class)->markCaptured($booking->payment, null, 'cash');

        $this->assertNotNull($booking->fresh()->greeting_card_path);
    }

    /**
     * ...and that booking must not be carded a SECOND time by the sweep.
     *
     * Live since the sweep moved to 10:00 (2026-08-19): a booking paid at
     * 08:00 for a seva today cards at capture, and the sweep two hours later
     * finds it again. The dispatch idempotency key only dedups for 30
     * minutes, so the durable notification log is what has to stop it.
     */
    public function test_the_sweep_does_not_card_a_booking_that_already_carded_at_payment(): void
    {
        $date = now()->toDateString();
        $booking = $this->sevaBooking($date);
        $booking->update(['status' => 'confirmed']);

        // The capture-time send is written straight into the log rather than
        // driven through markCaptured(): what is under test is the sweep's
        // memory of it, and the render step markCaptured() would run needs an
        // image stack the CI runner does not have.
        NotificationLog::create([
            'template_key' => 'seva.greeting_card',
            'channel' => NotificationTemplate::CHANNEL_EMAIL,
            'status' => NotificationLog::STATUS_SENT,
            'idempotency_key' => "seva-booking:{$booking->id}:greeting_card:email:t7:r0",
        ]);

        $this->artisan('seva:send-day-of-cards', ['--date' => $date])
            ->expectsOutputToContain('already carded today')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            NotificationLog::where('template_key', 'seva.greeting_card')->count(),
            'the sweep must not send the devotee a second card',
        );
    }

    /** A cancelled booking simply stops matching — no special handling. */
    public function test_the_sweep_skips_a_cancelled_booking(): void
    {
        $date = now()->addDays(3)->toDateString();
        $booking = $this->sevaBooking($date);

        app(PaymentCaptureService::class)->markCaptured($booking->payment, null, 'cash');
        $booking->update(['status' => 'cancelled']);

        $this->artisan('seva:send-day-of-cards', ['--date' => $date])->assertExitCode(0);

        $this->assertNull($booking->fresh()->greeting_card_path);
    }

    /** A seva with no artwork is skipped, and says so rather than failing. */
    public function test_the_sweep_skips_a_seva_with_no_artwork(): void
    {
        $date = now()->addDays(2)->toDateString();
        $booking = $this->sevaBooking($date, withArtwork: false);

        app(PaymentCaptureService::class)->markCaptured($booking->payment, null, 'cash');

        $this->artisan('seva:send-day-of-cards', ['--date' => $date])
            ->expectsOutputToContain('no card artwork')
            ->assertExitCode(0);

        $this->assertNull($booking->fresh()->greeting_card_path);
    }

    /** The gate lives in one place, so both capture paths ask the same question. */
    public function test_the_due_now_rule(): void
    {
        $svc = app(PaymentCaptureService::class);

        $this->assertTrue($svc->sevaCardIsDueNow($this->sevaBooking(now()->toDateString())));
        $this->assertTrue($svc->sevaCardIsDueNow($this->sevaBooking(now()->subDay()->toDateString())));
        $this->assertFalse($svc->sevaCardIsDueNow($this->sevaBooking(now()->addDay()->toDateString())));
    }
}
