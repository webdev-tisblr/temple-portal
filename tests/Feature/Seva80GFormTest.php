<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use Database\Factories\DevoteeFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The WEB half of the seva 80G opt-in.
 *
 * The redirect guard here is the one that actually holds. The Alpine panel
 * on the form is the friendly version, and on iOS the booking flow IS this
 * website — a prompt built only in Flutter would let half the userbase past
 * the rule.
 *
 * The critical property: the guard returns BEFORE any Razorpay order and
 * before the slot is held, so a bounced devotee is charged nothing and
 * consumes no capacity.
 */
class Seva80GFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RazorpayService::createOrder() talks to the live Razorpay API.
        // Stub it so these tests are hermetic — what is under test here is
        // the 80G guard, which sits UPSTREAM of any gateway call. (That is
        // the whole point of the guard: a bounced devotee never reaches
        // this stub at all, which the assertions below rely on.)
        $this->mock(RazorpayService::class, function ($mock) {
            $mock->shouldReceive('createOrder')
                ->andReturn((object) ['id' => 'order_TESTSTUB123']);
        });
    }

    public function test_the_booking_form_shows_the_80g_checkbox_to_a_signed_in_devotee(): void
    {
        $seva = SevaFactory::new()->create(['price' => 501]);
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->get(route('seva.show', $seva))
            ->assertOk()
            ->assertSee('wants_80g', escape: false)
            ->assertSee(__('seva.want_80g'), escape: false);
    }

    public function test_a_devotee_without_a_pan_is_bounced_to_the_profile_and_charged_nothing(): void
    {
        $seva = SevaFactory::new()->create(['price' => 501]);
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->post(route('seva.book', $seva), [
                'booking_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
                'wants_80g' => 1,
            ])
            ->assertRedirect(route('dashboard.profile'))
            ->assertSessionHas('pan_required_for_80g', true);

        // Nothing was created: no order, no held slot, no charge.
        $this->assertSame(0, SevaBooking::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_devotee_with_a_pan_is_not_bounced(): void
    {
        $seva = SevaFactory::new()->create(['price' => 501]);
        $devotee = DevoteeFactory::new()->withPan()->create();

        $response = $this->actingAs($devotee, 'devotee')
            ->post(route('seva.book', $seva), [
                'booking_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
                'wants_80g' => 1,
            ]);

        $response->assertSessionMissing('pan_required_for_80g');
        $this->assertSame(1, SevaBooking::count());
        $this->assertTrue(SevaBooking::first()->wants_80g);
    }

    public function test_a_devotee_who_does_not_ask_is_never_bounced(): void
    {
        // No PAN AND no 80G tick — the overwhelmingly common case. Must be
        // completely unaffected by any of this.
        $seva = SevaFactory::new()->create(['price' => 501]);
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->post(route('seva.book', $seva), [
                'booking_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ])
            ->assertSessionMissing('pan_required_for_80g');

        $this->assertSame(1, SevaBooking::count());
        $this->assertFalse(SevaBooking::first()->wants_80g);
    }

    public function test_the_bounce_remembers_a_return_url_that_restores_the_form(): void
    {
        $seva = SevaFactory::new()->create(['price' => 501]);
        $devotee = DevoteeFactory::new()->create();
        $date = now()->addDays(3)->toDateString();

        $this->actingAs($devotee, 'devotee')
            ->post(route('seva.book', $seva), [
                'booking_date' => $date,
                'quantity' => 2,
                'wants_80g' => 1,
            ])
            ->assertRedirect(route('dashboard.profile'));

        // Saving the PAN must land them back on a form already filled in,
        // not an empty one — otherwise they re-pick date and slot by hand.
        $intended = session('url.intended');
        $this->assertNotNull($intended, 'no return destination was remembered');
        $this->assertStringContainsString($date, $intended);
        $this->assertStringContainsString('wants_80g=1', $intended);
        $this->assertStringContainsString('quantity=2', $intended);
    }
}
