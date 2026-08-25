<?php

namespace Tests\Feature;

use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Double-press protection on the payment POSTs (2026-08-25).
 *
 * Reported from live use: on a slow connection the donate / book / checkout
 * buttons sit inert for a second or two while the controller talks to
 * Razorpay, so devotees press again. The browser abandons the first request
 * and sends a second, but PHP finishes BOTH — two Razorpay orders, two
 * pending rows, and for seva and hall a pending row also holds a slot.
 *
 * The browser-side guard (resources/js/submit-lock.js) is what devotees
 * feel; IdempotentPaymentRequest is what holds when JavaScript cannot run.
 * These tests exercise the middleware directly against a stand-in route
 * rather than the real controllers, because the behaviour under test is
 * "how many times did the handler run", and pinning that to Razorpay's
 * order API would make the test about the mock instead.
 */
class PaymentDoublePressTest extends TestCase
{
    use RefreshDatabase;

    private int $runs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runs = 0;

        Route::middleware(['web', 'payment.once'])->post('/_test/pay', function () {
            $this->runs++;

            // Stand-in for the rendered Razorpay handoff page: what matters
            // is that it carries an order id created by THIS run.
            return response('order-'.$this->runs, 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        });

        Route::middleware(['web', 'payment.once'])->post('/_test/pay-invalid', function () {
            $this->runs++;

            return redirect('/');
        });
    }

    private function devotee()
    {
        return DevoteeFactory::new()->create();
    }

    /** The second press is answered with the FIRST press's order, not a new one. */
    public function test_an_identical_resubmission_replays_the_first_response(): void
    {
        $devotee = $this->devotee();

        $first = $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100]);
        $second = $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100]);

        $first->assertOk();
        $second->assertOk();

        $this->assertSame(1, $this->runs, 'The controller must not run a second time.');
        $this->assertSame('order-1', $second->getContent());
        $this->assertSame('1', $second->headers->get('X-Payment-Replay'));
    }

    /** Field order is not something a browser guarantees, so it must not change the fingerprint. */
    public function test_the_same_fields_in_a_different_order_still_count_as_one_submission(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100, 'purpose' => 'seva']);
        $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['purpose' => 'seva', 'amount' => 1100]);

        $this->assertSame(1, $this->runs);
    }

    /** A genuinely different gift is a different submission and must go through. */
    public function test_a_different_amount_creates_its_own_order(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100]);
        $second = $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 2100]);

        $this->assertSame(2, $this->runs);
        $this->assertSame('order-2', $second->getContent());
    }

    /** One devotee must never be handed another devotee's checkout page. */
    public function test_two_devotees_giving_the_same_amount_are_kept_apart(): void
    {
        $this->actingAs($this->devotee(), 'devotee')->post('/_test/pay', ['amount' => 1100]);
        $second = $this->actingAs($this->devotee(), 'devotee')->post('/_test/pay', ['amount' => 1100]);

        $this->assertSame(2, $this->runs);
        $this->assertSame('order-2', $second->getContent());
    }

    /**
     * The window is short on purpose. A devotee who really does want to give
     * the same amount twice must not be handed the first gift's already-paid
     * order — so once the replay window closes, an identical submission is
     * simply a new one again.
     */
    public function test_a_genuine_repeat_gift_after_the_window_is_not_swallowed(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100]);

        $this->travel(31)->seconds();

        $second = $this->actingAs($devotee, 'devotee')->post('/_test/pay', ['amount' => 1100]);

        $this->assertSame(2, $this->runs);
        $this->assertSame('order-2', $second->getContent());
    }

    /**
     * The browser-side half. The hold screen only appears on forms carrying
     * `data-payment-form`, so a form that quietly loses the attribute in a
     * redesign would silently lose the fix — assert the wiring, on the page
     * iOS donors are actually sent to.
     */
    public function test_the_donate_form_is_wired_to_the_payment_hold_screen(): void
    {
        $this->actingAs($this->devotee(), 'devotee')
            ->get(route('donate'))
            ->assertOk()
            ->assertSee('data-payment-form', false)
            ->assertSee('data-payment-overlay', false);
    }

    /**
     * A validation bounce or the 80G-PAN redirect is not a result worth
     * replaying — and the devotee who fixes the problem must be able to
     * resubmit at once rather than wait out the window.
     */
    public function test_a_redirect_is_never_replayed_and_leaves_no_lock_behind(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')->post('/_test/pay-invalid', ['amount' => 1100]);
        $this->actingAs($devotee, 'devotee')->post('/_test/pay-invalid', ['amount' => 1100]);

        $this->assertSame(2, $this->runs, 'A failed submission must stay retryable.');
    }
}
