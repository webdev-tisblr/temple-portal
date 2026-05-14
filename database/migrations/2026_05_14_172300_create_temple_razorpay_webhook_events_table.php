<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event log for inbound Razorpay webhooks. Used by
 * PaymentWebhookController to guarantee idempotency: a webhook
 * delivered twice (network retry, replay attack, dashboard manual
 * resend) is recorded once and the second delivery becomes a no-op.
 *
 * Earlier the only replay protection was the Payment.status check
 * inside PaymentCaptureService::markCaptured. That check is racy under
 * webhook + /payments/verify simultaneity and doesn't help for events
 * that never reach markCaptured (e.g. an unknown event_type).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_razorpay_webhook_events', function (Blueprint $table) {
            $table->id();
            // Razorpay sends a unique `id` per event payload (e.g.
            // 'evt_xxxxxxxxxxxx'). Unique index → second-delivery
            // detection via insertOrIgnore.
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 64); // payment.captured / payment.failed / order.paid / etc
            $table->string('razorpay_order_id', 64)->nullable()->index();
            $table->string('razorpay_payment_id', 64)->nullable()->index();
            // Full payload kept for audit + replay debugging.
            $table->json('payload');
            // Outcome of the handler: 'processed' / 'ignored' / 'duplicate' / 'error'.
            $table->enum('outcome', ['processed', 'ignored', 'duplicate', 'error'])
                ->default('processed');
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['event_type', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_razorpay_webhook_events');
    }
};
