<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Payment;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\SevaFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Abandoned checkouts are deleted; anything that touched money is not.
 *
 * Every assertion here is a guard against deleting a real financial record —
 * the failure mode is silent and unrecoverable, so each rule gets its own
 * test rather than being folded into a happy path.
 */
class PruneAbandonedCheckoutsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Backdate a row. created_at is NOT mass-assignable, so passing it to
     * create() is silently dropped and the row lands stamped "now" — which
     * makes every retention-window assertion here pass or fail for the wrong
     * reason. forceFill + saveQuietly is what actually moves it.
     */
    private function aged(Model $model, int $daysOld): Model
    {
        $when = now()->subDays($daysOld);
        $model->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

        return $model->refresh();
    }

    private function payment(string $status, int $daysOld): Payment
    {
        return $this->aged(Payment::create([
            'id' => (string) Str::uuid(),
            'razorpay_order_id' => 'order_'.Str::random(10),
            'amount' => 501,
            'currency' => 'INR',
            'status' => $status,
            'description' => 'test',
        ]), $daysOld);
    }

    private function sevaBooking(Payment $payment, string $status, int $daysOld): SevaBooking
    {
        return $this->aged(SevaBooking::create([
            'id' => (string) Str::uuid(),
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'seva_id' => SevaFactory::new()->create()->id,
            'booking_date' => now()->addDays(3)->toDateString(),
            'slot_time' => '10:00',
            'quantity' => 1,
            'total_amount' => 501,
            'status' => $status,
            'payment_id' => $payment->id,
        ]), $daysOld);
    }

    private function donation(Payment $payment, int $daysOld): Donation
    {
        // Via the factory: temple_donations has NOT NULL columns
        // (financial_year among them) that a hand-built insert misses.
        return $this->aged(DonationFactory::new()->create([
            'payment_id' => $payment->id,
        ]), $daysOld);
    }

    public function test_an_abandoned_seva_booking_is_deleted(): void
    {
        $booking = $this->sevaBooking($this->payment('failed', 10), 'cancelled', 10);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('temple_seva_bookings', ['id' => $booking->id]);
    }

    public function test_a_paid_booking_is_never_deleted(): void
    {
        // Cancelled by the devotee AFTER paying — the money is real.
        $booking = $this->sevaBooking($this->payment('captured', 30), 'cancelled', 30);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('temple_seva_bookings', ['id' => $booking->id]);
    }

    public function test_a_recent_abandonment_is_kept_for_the_retention_window(): void
    {
        // Razorpay can still capture this late; deleting it would leave that
        // capture with nothing to attach to.
        $booking = $this->sevaBooking($this->payment('failed', 1), 'cancelled', 1);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('temple_seva_bookings', ['id' => $booking->id]);
    }

    public function test_a_pending_booking_is_left_to_the_stale_sweep(): void
    {
        $booking = $this->sevaBooking($this->payment('created', 10), 'pending', 10);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('temple_seva_bookings', ['id' => $booking->id]);
    }

    public function test_an_abandoned_donation_is_deleted_with_its_payment(): void
    {
        $payment = $this->payment('failed', 10);
        $donation = $this->donation($payment, 10);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('temple_donations', ['id' => $donation->id]);
        $this->assertDatabaseMissing('temple_payments', ['id' => $payment->id]);
    }

    public function test_a_captured_donation_is_never_deleted(): void
    {
        $payment = $this->payment('captured', 40);
        $donation = $this->donation($payment, 40);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('temple_donations', ['id' => $donation->id]);
        $this->assertDatabaseHas('temple_payments', ['id' => $payment->id]);
    }

    public function test_a_donation_with_an_80g_receipt_is_never_deleted(): void
    {
        // Belt and braces: a receipt should imply a captured payment, but an
        // issued statutory document must survive even if the data disagrees.
        $payment = $this->payment('failed', 40);
        $donation = $this->donation($payment, 40);

        Receipt80G::create([
            'donation_id' => $donation->id,
            'receipt_number' => 'SPHST/80G/2026-27/09999',
            'financial_year' => '2026-27',
            'amount' => 501,
            'devotee_name' => 'Bhakt Ji',
            'pan_number' => 'ABCDE1234F',
            'amount_in_words' => 'Five hundred one only',
            'donation_date' => now()->subDays(40)->toDateString(),
            'payment_mode' => 'online',
            'issued_at' => now()->subDays(40),
        ]);

        $this->artisan('bookings:prune-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('temple_donations', ['id' => $donation->id]);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $booking = $this->sevaBooking($this->payment('failed', 10), 'cancelled', 10);

        $this->artisan('bookings:prune-abandoned', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('temple_seva_bookings', ['id' => $booking->id]);
    }
}
