<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentCaptureService $captureService): JsonResponse
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
            'payment.captured' => $this->handlePaymentCaptured($data, $captureService),
            'payment.failed' => $this->handlePaymentFailed($data, $captureService),
            default => response()->json(['status' => 'ignored']),
        };
    }

    private function handlePaymentCaptured(array $data, PaymentCaptureService $captureService): JsonResponse
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

        if ($payment->status->value === 'captured') {
            Log::info("Razorpay webhook: payment {$razorpayOrderId} already captured (likely client-verified)");
            return response()->json(['status' => 'already_processed']);
        }

        $captureService->markCaptured(
            $payment,
            $razorpayPaymentId,
            $paymentEntity['method'] ?? null,
            $data,
        );

        return response()->json(['status' => 'captured']);
    }

    private function handlePaymentFailed(array $data, PaymentCaptureService $captureService): JsonResponse
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

        $captureService->markFailed($payment, $data);

        Log::info("Payment {$razorpayOrderId} failed", ['payment_id' => $payment->id]);

        return response()->json(['status' => 'failed_recorded']);
    }
}
