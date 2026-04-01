<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\Generate80GReceipt;
use App\Jobs\SendSevaBookingConfirmation;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');

        $razorpayService = app(RazorpayService::class);

        if (!$razorpayService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Razorpay webhook: invalid signature');
            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $data = json_decode($payload, true);
        $event = $data['event'] ?? '';

        Log::info("Razorpay webhook received: {$event}");

        return match ($event) {
            'payment.captured' => $this->handlePaymentCaptured($data),
            'payment.failed' => $this->handlePaymentFailed($data),
            default => response()->json(['status' => 'ignored']),
        };
    }

    private function handlePaymentCaptured(array $data): JsonResponse
    {
        $paymentEntity = $data['payload']['payment']['entity'] ?? [];
        $razorpayPaymentId = $paymentEntity['id'] ?? null;
        $razorpayOrderId = $paymentEntity['order_id'] ?? null;

        if (!$razorpayOrderId) {
            Log::warning('Razorpay webhook: missing order_id in payment.captured');
            return response()->json(['status' => 'missing_order_id'], 400);
        }

        $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();

        if (!$payment) {
            Log::warning("Razorpay webhook: payment not found for order {$razorpayOrderId}");
            return response()->json(['status' => 'payment_not_found'], 404);
        }

        // Idempotency check
        if ($payment->status->value === 'captured') {
            Log::info("Razorpay webhook: payment {$razorpayOrderId} already captured");
            return response()->json(['status' => 'already_processed']);
        }

        $payment->update([
            'status' => 'captured',
            'razorpay_payment_id' => $razorpayPaymentId,
            'paid_at' => now(),
            'method' => $paymentEntity['method'] ?? null,
            'webhook_payload' => $data,
        ]);

        // Update associated seva booking
        $booking = SevaBooking::where('payment_id', $payment->id)->first();
        if ($booking) {
            $booking->update(['status' => 'confirmed']);
            SendSevaBookingConfirmation::dispatch($booking);
            Log::info("Seva booking {$booking->id} confirmed via webhook");
        }

        // Handle donation record
        $donation = Donation::where('payment_id', $payment->id)->first();

        if (!$donation && $booking) {
            // Seva booking payment — create donation record
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

        // Trigger 80G receipt generation
        if ($donation && $donation->is_80g_eligible && (float) $donation->amount >= 2000) {
            $devotee = $donation->devotee;
            if ($devotee && $devotee->pan_encrypted) {
                $donation->update([
                    'pan_verified' => true,
                    'pan_number_encrypted' => $devotee->pan_encrypted,
                ]);
                Generate80GReceipt::dispatch($donation);
                Log::info("80G receipt job dispatched for donation {$donation->id}");
            } else {
                Log::info("80G receipt skipped — PAN not on file for donation {$donation->id}");
            }
        }

        Log::info("Payment {$razorpayOrderId} captured successfully", [
            'payment_id' => $payment->id,
            'razorpay_payment_id' => $razorpayPaymentId,
            'amount' => $payment->amount,
        ]);

        return response()->json(['status' => 'captured']);
    }

    private function handlePaymentFailed(array $data): JsonResponse
    {
        $paymentEntity = $data['payload']['payment']['entity'] ?? [];
        $razorpayOrderId = $paymentEntity['order_id'] ?? null;

        if (!$razorpayOrderId) {
            return response()->json(['status' => 'missing_order_id'], 400);
        }

        $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();

        if (!$payment) {
            return response()->json(['status' => 'payment_not_found'], 404);
        }

        $payment->update([
            'status' => 'failed',
            'webhook_payload' => $data,
        ]);

        $booking = SevaBooking::where('payment_id', $payment->id)->first();
        if ($booking) {
            $booking->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => 'Payment failed',
            ]);
        }

        $donation = Donation::where('payment_id', $payment->id)->first();
        if ($donation) {
            Log::info("Donation {$donation->id} payment failed");
        }

        Log::info("Payment {$razorpayOrderId} failed", ['payment_id' => $payment->id]);

        return response()->json(['status' => 'failed_recorded']);
    }
}
