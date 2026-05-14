<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RazorpayWebhookEvent;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound Razorpay webhook handler.
 *
 * Order of operations on every delivery:
 *   1. Verify the X-Razorpay-Signature header against the configured
 *      webhook secret. Untrusted payloads are rejected with 400 BEFORE
 *      we touch the DB.
 *   2. Look up (or insert) a temple_razorpay_webhook_events row keyed
 *      on Razorpay's event id. A duplicate delivery — Razorpay's
 *      retry queue, an attacker replaying a captured payload, or a
 *      manual resend from the dashboard — surfaces as a unique-key
 *      violation that we translate into an idempotent 200.
 *   3. Dispatch to the per-event handler. Each handler is itself
 *      idempotent (PaymentCaptureService re-checks status under a
 *      row lock), so the event log is defence in depth rather than
 *      the only guarantee.
 *
 * Earlier the only replay protection was the Payment.status check
 * inside markCaptured. That check is racy against /payments/verify
 * and gives no audit trail for events that never reach markCaptured
 * (unknown event_type, missing payment row, signature pass through
 * before our DB is even queried).
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, PaymentCaptureService $captureService): JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Razorpay-Signature', '');

        $razorpayService = app(RazorpayService::class);

        if (! $razorpayService->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Razorpay webhook: invalid signature');
            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $data = json_decode($payload, true);
        if (! is_array($data)) {
            Log::warning('Razorpay webhook: payload is not JSON');
            return response()->json(['status' => 'invalid_payload'], 400);
        }

        $eventId = $data['id'] ?? null;
        $eventType = $data['event'] ?? 'unknown';
        $paymentEntity = $data['payload']['payment']['entity'] ?? [];

        // Without a Razorpay event id we can't dedupe — log and reject.
        // Real Razorpay events always include `id`; absence implies a
        // malformed or fabricated payload (the signature check above
        // should have caught it, but belt-and-braces).
        if (empty($eventId)) {
            Log::warning('Razorpay webhook: missing event id', ['event' => $eventType]);
            return response()->json(['status' => 'missing_event_id'], 400);
        }

        // Atomic dedup: insertOrIgnore is a single statement; under the
        // unique index on event_id a second delivery is dropped silently
        // and affected-rows returns 0. We then read back the existing
        // row to honour the original outcome.
        $now = now();
        $inserted = RazorpayWebhookEvent::query()->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'razorpay_order_id' => $paymentEntity['order_id'] ?? null,
            'razorpay_payment_id' => $paymentEntity['id'] ?? null,
            'payload' => json_encode($data),
            'outcome' => 'processed',
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            // Duplicate delivery — first delivery already processed.
            Log::info("Razorpay webhook: duplicate event {$eventId}", ['event' => $eventType]);
            return response()->json(['status' => 'duplicate']);
        }

        Log::info("Razorpay webhook received: {$eventType}", ['event_id' => $eventId]);

        try {
            $response = match ($eventType) {
                'payment.captured' => $this->handlePaymentCaptured($data, $captureService),
                'payment.failed' => $this->handlePaymentFailed($data, $captureService),
                default => $this->markIgnored($eventId),
            };

            RazorpayWebhookEvent::where('event_id', $eventId)
                ->update(['processed_at' => now()]);

            return $response;
        } catch (Throwable $e) {
            RazorpayWebhookEvent::where('event_id', $eventId)->update([
                'outcome' => RazorpayWebhookEvent::OUTCOME_ERROR,
                'error_message' => substr($e->getMessage(), 0, 1000),
                'processed_at' => now(),
            ]);
            Log::error('Razorpay webhook: handler threw', [
                'event_id' => $eventId,
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);
            // Return 500 so Razorpay retries — the event log is now in
            // 'error' state, and the next delivery (same event_id)
            // hits the unique-index dedup path and returns 'duplicate'
            // without re-processing. Operators inspect the error row
            // and manually retry via a Tinker command if needed.
            return response()->json(['status' => 'handler_error'], 500);
        }
    }

    private function handlePaymentCaptured(array $data, PaymentCaptureService $captureService): JsonResponse
    {
        $paymentEntity = $data['payload']['payment']['entity'] ?? [];
        $razorpayPaymentId = $paymentEntity['id'] ?? null;
        $razorpayOrderId = $paymentEntity['order_id'] ?? null;

        if (! $razorpayOrderId) {
            Log::warning('Razorpay webhook: missing order_id in payment.captured');
            return response()->json(['status' => 'missing_order_id'], 400);
        }

        $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();

        if (! $payment) {
            // Mark this event as 'ignored' (payment row missing) so the
            // dedup log shows operators why nothing happened.
            RazorpayWebhookEvent::where('razorpay_order_id', $razorpayOrderId)
                ->where('event_type', 'payment.captured')
                ->orderByDesc('id')
                ->limit(1)
                ->update(['outcome' => RazorpayWebhookEvent::OUTCOME_IGNORED]);
            Log::warning("Razorpay webhook: payment not found for order {$razorpayOrderId}");
            return response()->json(['status' => 'payment_not_found'], 404);
        }

        // PaymentCaptureService is idempotent (Payment::lockForUpdate
        // + status re-check), so calling it after a client-verify
        // already captured is a no-op.
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

        if (! $razorpayOrderId) {
            return response()->json(['status' => 'missing_order_id'], 400);
        }

        $payment = Payment::where('razorpay_order_id', $razorpayOrderId)->first();

        if (! $payment) {
            return response()->json(['status' => 'payment_not_found'], 404);
        }

        $captureService->markFailed($payment, $data);

        Log::info("Payment {$razorpayOrderId} failed", ['payment_id' => $payment->id]);

        return response()->json(['status' => 'failed_recorded']);
    }

    /**
     * Update the event log row's outcome to 'ignored' for event types
     * we don't currently handle (refunds, subscription events, etc).
     * Keeps the row so support can see what came in.
     */
    private function markIgnored(string $eventId): JsonResponse
    {
        RazorpayWebhookEvent::where('event_id', $eventId)
            ->update(['outcome' => RazorpayWebhookEvent::OUTCOME_IGNORED]);
        return response()->json(['status' => 'ignored']);
    }
}
