<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Payment;
use App\Services\RazorpayService;
use Database\Factories\DonationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * payments:reconcile — the pull-based path for a capture that neither
 * /payments/verify nor the webhook reported (2026-09-18: a ₹1,100 UPI
 * donation sat invisible in admin while Razorpay showed it captured).
 */
class ReconcileRazorpayPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Capture side effects render PDFs — never let them reach a live bucket.
        Storage::fake('r2');
        Storage::fake('r2_private');
    }

    /** @param array<string, list<array<string,mixed>>> $byOrder */
    private function fakeRazorpay(array $byOrder): void
    {
        $stub = new class($byOrder) extends RazorpayService
        {
            /** @var list<string> */
            public array $asked = [];

            public function __construct(private array $byOrder) {}

            public function fetchOrderPayments(string $orderId): array
            {
                $this->asked[] = $orderId;

                return $this->byOrder[$orderId] ?? [];
            }
        };

        $this->app->instance(RazorpayService::class, $stub);
    }

    private function unpaidDonation(string $status, int $minutesOld, float $amount = 1100): Payment
    {
        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'razorpay_order_id' => 'order_'.Str::random(12),
            'amount' => $amount,
            'currency' => 'INR',
            'status' => $status,
            'description' => 'test',
        ]);
        // created_at is not mass-assignable — see PruneAbandonedCheckoutsTest.
        $when = now()->subMinutes($minutesOld);
        $payment->forceFill(['created_at' => $when, 'updated_at' => $when])->saveQuietly();

        DonationFactory::new()->create(['payment_id' => $payment->id, 'amount' => $amount]);

        return $payment->refresh();
    }

    public function test_a_payment_razorpay_captured_is_recovered_even_after_the_stale_sweep_failed_it(): void
    {
        $payment = $this->unpaidDonation('failed', 45);
        $paidAt = now()->subMinutes(44)->startOfSecond();
        $this->fakeRazorpay([$payment->razorpay_order_id => [
            ['id' => 'pay_failedAttempt', 'status' => 'failed', 'amount' => 110000, 'method' => 'upi', 'created_at' => $paidAt->timestamp - 60],
            ['id' => 'pay_realOne', 'status' => 'captured', 'amount' => 110000, 'method' => 'upi', 'created_at' => $paidAt->timestamp],
        ]]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame('captured', $payment->status->value);
        $this->assertSame('pay_realOne', $payment->razorpay_payment_id);
        $this->assertSame('upi', $payment->method);
        $this->assertTrue($paidAt->equalTo($payment->paid_at), 'paid_at must be when the donor paid, not when we noticed');
    }

    public function test_an_order_with_no_captured_attempt_is_left_alone(): void
    {
        $payment = $this->unpaidDonation('failed', 45);
        $this->fakeRazorpay([$payment->razorpay_order_id => [
            ['id' => 'pay_x', 'status' => 'failed', 'amount' => 110000, 'method' => 'upi', 'created_at' => now()->timestamp],
        ]]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('failed', $payment->refresh()->status->value);
    }

    public function test_an_amount_mismatch_is_never_captured(): void
    {
        $payment = $this->unpaidDonation('created', 45);
        $this->fakeRazorpay([$payment->razorpay_order_id => [
            ['id' => 'pay_x', 'status' => 'captured', 'amount' => 100, 'method' => 'upi', 'created_at' => now()->timestamp],
        ]]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame('created', $payment->refresh()->status->value);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $payment = $this->unpaidDonation('failed', 45);
        $this->fakeRazorpay([$payment->razorpay_order_id => [
            ['id' => 'pay_x', 'status' => 'captured', 'amount' => 110000, 'method' => 'upi', 'created_at' => now()->timestamp],
        ]]);

        $this->artisan('payments:reconcile --dry-run')->assertSuccessful();

        $this->assertSame('failed', $payment->refresh()->status->value);
    }

    public function test_open_checkouts_old_payments_and_counter_entries_are_not_asked_about(): void
    {
        $fresh = $this->unpaidDonation('created', 2);      // checkout may still be open
        $ancient = $this->unpaidDonation('failed', 60 * 30); // past --hours=24
        $due = $this->unpaidDonation('failed', 45);
        $cash = $this->unpaidDonation('failed', 45);
        $cash->forceFill(['razorpay_order_id' => Payment::OFFLINE_ORDER_PREFIX.Str::random(10)])->saveQuietly();
        $this->fakeRazorpay([]);

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame([$due->razorpay_order_id], $this->app->make(RazorpayService::class)->asked);
    }

    public function test_order_option_reaches_a_payment_outside_the_window(): void
    {
        $ancient = $this->unpaidDonation('failed', 60 * 24 * 5);
        $this->fakeRazorpay([$ancient->razorpay_order_id => [
            ['id' => 'pay_late', 'status' => 'captured', 'amount' => 110000, 'method' => 'upi', 'created_at' => now()->subDays(5)->timestamp],
        ]]);

        $this->artisan('payments:reconcile', ['--order' => $ancient->razorpay_order_id])->assertSuccessful();

        $this->assertSame('captured', $ancient->refresh()->status->value);
    }
}
