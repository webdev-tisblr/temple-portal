<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\Generate80GReceipt;
use App\Jobs\SendSevaBookingConfirmation;
use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SevaBooking;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single source of truth for "this payment captured" side-effects.
 *
 * Used by both the Razorpay webhook AND the client-side verify endpoint
 * (POST /v1/payments/verify) — whichever fires first wins; the second is a
 * no-op via the idempotency check on payment.status.
 */
class PaymentCaptureService
{
    public function markCaptured(
        Payment $payment,
        ?string $razorpayPaymentId = null,
        ?string $method = null,
        ?array $rawWebhookPayload = null,
    ): void {
        if ($payment->status->value === 'captured') {
            return;
        }

        $payment->update([
            'status' => 'captured',
            'razorpay_payment_id' => $razorpayPaymentId ?? $payment->razorpay_payment_id,
            'paid_at' => now(),
            'method' => $method ?? $payment->method,
            'webhook_payload' => $rawWebhookPayload ?? $payment->webhook_payload,
        ]);

        $booking = SevaBooking::where('payment_id', $payment->id)->first();
        if ($booking) {
            $booking->update(['status' => 'confirmed']);
            SendSevaBookingConfirmation::dispatch($booking);
            Log::info("Seva booking {$booking->id} confirmed via payment capture");
        }

        $order = Order::where('payment_id', $payment->id)->first();
        if ($order) {
            $order->update(['status' => 'confirmed']);
            Log::info("Store order {$order->id} confirmed via payment capture");
        }

        $hallBooking = HallBooking::where('payment_id', $payment->id)->first();
        if ($hallBooking) {
            $hallBooking->update(['status' => 'confirmed']);
            Log::info("Hall booking {$hallBooking->id} confirmed via payment capture");
        }

        $donation = Donation::where('payment_id', $payment->id)->first();

        if (! $donation && $booking) {
            $fy = now()->month >= 4
                ? now()->year . '-' . substr((string) (now()->year + 1), -2)
                : (now()->year - 1) . '-' . substr((string) now()->year, -2);

            $donation = Donation::create([
                'id' => (string) Str::uuid(),
                'devotee_id' => $booking->devotee_id,
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
                'donation_type' => 'seva',
                'purpose' => 'Seva: ' . ($booking->seva->name_en ?? 'Seva Booking'),
                'seva_booking_id' => $booking->id,
                'is_80g_eligible' => true,
                'financial_year' => $fy,
            ]);
        }

        if ($donation) {
            $devotee = $donation->devotee;
            if ($devotee && $devotee->pan_encrypted) {
                $donation->update([
                    'pan_verified' => true,
                    'pan_number_encrypted' => $devotee->pan_encrypted,
                ]);
            }
            // Hostinger has no queue worker — run inline so the receipt is
            // available the moment the user lands on the success screen.
            try {
                Generate80GReceipt::dispatchSync($donation);
            } catch (\Throwable $e) {
                Log::error("80G receipt generation failed for donation {$donation->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Payment {$payment->razorpay_order_id} captured", [
            'payment_id' => $payment->id,
            'razorpay_payment_id' => $razorpayPaymentId,
            'amount' => $payment->amount,
        ]);
    }

    public function markFailed(Payment $payment, ?array $rawWebhookPayload = null): void
    {
        $payment->update([
            'status' => 'failed',
            'webhook_payload' => $rawWebhookPayload ?? $payment->webhook_payload,
        ]);

        $booking = SevaBooking::where('payment_id', $payment->id)->first();
        if ($booking) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Payment failed',
            ]);
        }

        $order = Order::where('payment_id', $payment->id)->first();
        if ($order) {
            $order->update(['status' => 'cancelled']);
        }

        $hallBooking = HallBooking::where('payment_id', $payment->id)->first();
        if ($hallBooking) {
            $hallBooking->update(['status' => 'cancelled']);
        }
    }
}
