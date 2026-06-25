<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Client-side payment verification — called by the mobile app right after
 * the Razorpay SDK fires onSuccess. Verifies the signature returned by
 * Razorpay and triggers the same "captured" side-effects as the webhook.
 *
 * This makes payment confirmation independent of the webhook, which can be
 * delayed, blocked by hosting firewalls, or misconfigured in the Razorpay
 * dashboard.
 */
class PaymentVerificationController extends BaseApiController
{
    public function verify(Request $request, PaymentCaptureService $captureService, RazorpayService $razorpayService): JsonResponse
    {
        $validated = $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $payment = Payment::where('razorpay_order_id', $validated['razorpay_order_id'])->first();

        if (! $payment) {
            Log::warning('Payment verify: order not found', [
                'razorpay_order_id' => $validated['razorpay_order_id'],
            ]);
            return $this->error('Payment record not found.', 404);
        }

        $signatureValid = $razorpayService->verifyPaymentSignature(
            $validated['razorpay_order_id'],
            $validated['razorpay_payment_id'],
            $validated['razorpay_signature'],
        );

        if (! $signatureValid) {
            Log::warning('Payment verify: invalid signature', [
                'razorpay_order_id' => $validated['razorpay_order_id'],
                'razorpay_payment_id' => $validated['razorpay_payment_id'],
            ]);
            return $this->error('Payment signature could not be verified.', 422);
        }

        // Ownership check — the signature proves the triple is authentic,
        // but not that THIS devotee owns the payment. Resolve the linked
        // entity's devotee and reject an explicit mismatch so one logged-in
        // devotee can never drive capture side effects (receipt, booking
        // confirmation) on another devotee's payment.
        $userId = (string) $request->user()->id;
        $ownerId = $this->resolveOwnerDevoteeId($payment);
        if ($ownerId !== null && $ownerId !== $userId) {
            Log::warning('Payment verify: ownership mismatch', [
                'payment_id' => $payment->id,
                'request_user' => $userId,
                'owner' => $ownerId,
            ]);
            return $this->error('This payment does not belong to your account.', 403);
        }

        // Amount tamper check (defence in depth). Fetch the authoritative
        // amount from Razorpay (paise) and compare to what we charged
        // (Payment.amount, rupees). If the fetch itself fails (Razorpay API
        // blip) we proceed — the signature already validated the payment and
        // the webhook path re-checks the amount — so a transient API outage
        // never blocks a legitimate confirmation.
        if ($payment->status->value !== 'captured') {
            try {
                $rzp = $razorpayService->fetchPayment($validated['razorpay_payment_id']);
                $paidPaise = isset($rzp->amount) ? (int) $rzp->amount : null;
                $expectedPaise = (int) round(((float) $payment->amount) * 100);
                if ($paidPaise !== null && $paidPaise !== $expectedPaise) {
                    Log::critical('Payment verify: amount mismatch — capture withheld', [
                        'payment_id' => $payment->id,
                        'expected_paise' => $expectedPaise,
                        'paid_paise' => $paidPaise,
                    ]);
                    return $this->error('Payment amount could not be verified.', 422);
                }
            } catch (\Throwable $e) {
                Log::warning('Payment verify: amount fetch failed, proceeding on signature', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $captureService->markCaptured(
            $payment,
            $validated['razorpay_payment_id'],
        );

        return $this->success([
            'status' => 'captured',
            'payment_id' => $payment->id,
            'razorpay_payment_id' => $validated['razorpay_payment_id'],
        ], 'Payment verified.');
    }

    /**
     * Find the devotee that owns whatever this payment is for (donation,
     * seva booking, store order, or hall booking). Returns the devotee id
     * as a string, or null if no linked entity is found (anomalous — the
     * caller then proceeds on the signature alone rather than hard-failing
     * a legitimate edge case).
     */
    private function resolveOwnerDevoteeId(Payment $payment): ?string
    {
        foreach ([
            \App\Models\Donation::class,
            \App\Models\SevaBooking::class,
            \App\Models\Order::class,
            \App\Models\HallBooking::class,
        ] as $model) {
            $owner = $model::where('payment_id', $payment->id)->value('devotee_id');
            if ($owner !== null) {
                return (string) $owner;
            }
        }

        return null;
    }
}
