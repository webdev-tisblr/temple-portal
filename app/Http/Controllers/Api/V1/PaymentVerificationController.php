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
}
