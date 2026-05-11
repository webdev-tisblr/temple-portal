<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\Generate80GReceipt;
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
            // Fan out to every enabled template (email/whatsapp/push)
            // for the seva.booking.confirmed trigger. Replaces the
            // hardcoded SendSevaBookingConfirmation job.
            $booking->loadMissing('devotee', 'seva');
            app(\App\Services\Notifications\NotificationService::class)->dispatch(
                'seva.booking.confirmed',
                [
                    'booking' => $booking,
                    'devotee' => $booking->devotee,
                    'trust_name' => \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                ],
            );
            Log::info("Seva booking {$booking->id} confirmed via payment capture");
        }

        $order = Order::where('payment_id', $payment->id)->first();
        if ($order) {
            $order->update(['status' => 'confirmed']);
            // store.order.confirmed dispatch happens inside
            // GenerateStoreInvoice once the PDF is built — that path
            // attaches the invoice and uses the same trigger key.
            Log::info("Store order {$order->id} confirmed via payment capture");
        }

        $hallBooking = HallBooking::where('payment_id', $payment->id)->first();
        if ($hallBooking) {
            $hallBooking->update(['status' => 'confirmed']);
            // hall.booking.confirmed dispatch happens inside
            // HallBookingController::emailHallInvoice after the PDF is
            // built — same trigger key.
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

            // Immediate "thanks for your donation" pulse before the
            // (heavier) 80G receipt PDF build. This trigger fires
            // independently of donation.receipt_80g so admins can
            // confirm donations even before the receipt is ready.
            app(\App\Services\Notifications\NotificationService::class)->dispatch(
                'donation.confirmed',
                [
                    'donation' => $donation->loadMissing('devotee', 'campaign', 'donationType'),
                    'devotee' => $donation->devotee,
                    'trust_name' => \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                ],
            );

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
