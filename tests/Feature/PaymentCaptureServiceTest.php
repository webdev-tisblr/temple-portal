<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SevaBooking;
use App\Services\PaymentCaptureService;
use Database\Factories\DonationFactory;
use Database\Factories\OrderFactory;
use Database\Factories\OrderItemFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for PaymentCaptureService — the single source of truth
 * for "this payment captured" side effects on the money path.
 *
 * Guarantees exercised here:
 *   (a) capturing a payment confirms the linked seva booking / order /
 *       donation downstream rows,
 *   (b) IDEMPOTENCY — calling markCaptured twice does NOT double-fire side
 *       effects (stock decremented once, status stays 'captured'),
 *   (c) markFailed cancels the pending booking / order.
 *
 * MySQL-only project: requires the `temple_portal_test` database to exist
 * (see phpunit.xml / CLAUDE.md). RefreshDatabase migrates it fresh.
 */
class PaymentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): PaymentCaptureService
    {
        return app(PaymentCaptureService::class);
    }

    // ── (a) capture confirms downstream rows ─────────────────────────

    public function test_capturing_payment_confirms_linked_seva_booking(): void
    {
        $payment = PaymentFactory::new()->create(); // status: created
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->service()->markCaptured($payment, 'pay_test_123', 'upi');

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $booking->fresh()->status->value);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame('pay_test_123', $payment->fresh()->razorpay_payment_id);
    }

    public function test_capturing_payment_confirms_linked_order(): void
    {
        $payment = PaymentFactory::new()->create();
        $order = OrderFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->service()->markCaptured($payment);

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_capturing_payment_marks_donation_path(): void
    {
        $payment = PaymentFactory::new()->create();
        $donation = DonationFactory::new()->create([
            'payment_id' => $payment->id,
        ]);

        $this->service()->markCaptured($payment);

        // The donation's own row carries no status column; the guarantee is
        // that the payment captures cleanly with a donation attached and no
        // exception is thrown (the 80G receipt job is dispatched + try/caught).
        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertDatabaseHas('temple_donations', ['id' => $donation->id]);
    }

    // ── (b) idempotency ──────────────────────────────────────────────

    public function test_double_capture_decrements_stock_only_once(): void
    {
        $product = ProductFactory::new()->create(['stock_quantity' => 10]);
        $payment = PaymentFactory::new()->create();
        $order = OrderFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);
        OrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $service = $this->service();
        $service->markCaptured($payment);
        // Second call simulates webhook racing /payments/verify after the
        // first call already captured. Must be a no-op for side effects.
        $service->markCaptured($payment->fresh());

        // 10 - 3 = 7, decremented exactly once despite two markCaptured calls.
        $this->assertSame(7, (int) $product->fresh()->stock_quantity);
        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $order->fresh()->status->value);
    }

    public function test_untracked_product_confirms_without_touching_stock(): void
    {
        // track_stock=false → unlimited: capture must confirm the order
        // and leave stock_quantity exactly as it was.
        $product = ProductFactory::new()->create(['stock_quantity' => 0, 'track_stock' => false]);
        $payment = PaymentFactory::new()->create();
        $order = OrderFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);
        OrderItemFactory::new()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);

        $this->service()->markCaptured($payment);

        $this->assertSame(0, (int) $product->fresh()->stock_quantity);
        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertTrue($product->fresh()->inStock());
    }

    public function test_double_capture_keeps_booking_confirmed_once(): void
    {
        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $service = $this->service();
        $service->markCaptured($payment);
        $service->markCaptured($payment->fresh());

        $this->assertSame('confirmed', $booking->fresh()->status->value);
        $this->assertSame('captured', $payment->fresh()->status->value);
    }

    public function test_capture_on_already_captured_payment_is_noop(): void
    {
        // Fast-path early-exit branch: status already 'captured'.
        $payment = PaymentFactory::new()->captured()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->service()->markCaptured($payment);

        // Early exit means the booking is NOT touched — there is no work to
        // do because the payment was already captured before this call.
        $this->assertSame('pending', $booking->fresh()->status->value);
    }

    // ── (c) markFailed cancels downstream rows ───────────────────────

    public function test_mark_failed_cancels_pending_seva_booking(): void
    {
        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->service()->markFailed($payment);

        $this->assertSame('failed', $payment->fresh()->status->value);
        $fresh = $booking->fresh();
        $this->assertSame('cancelled', $fresh->status->value);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('Payment failed', $fresh->cancellation_reason);
    }

    public function test_mark_failed_cancels_pending_order(): void
    {
        $payment = PaymentFactory::new()->create();
        $order = OrderFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $this->service()->markFailed($payment);

        $this->assertSame('failed', $payment->fresh()->status->value);
        $this->assertSame('cancelled', $order->fresh()->status->value);
    }

    public function test_mark_failed_is_idempotent(): void
    {
        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        $service = $this->service();
        $service->markFailed($payment);
        $service->markFailed($payment->fresh()); // fast-path early exit

        $this->assertSame('failed', $payment->fresh()->status->value);
        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }
}
