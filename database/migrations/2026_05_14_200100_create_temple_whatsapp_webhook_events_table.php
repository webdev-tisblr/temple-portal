<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event audit log for inbound WhatsApp delivery webhooks.
 *
 * Same idea as temple_razorpay_webhook_events but for Meta's Cloud API:
 * every status / message / template-update event POSTed by the BSP
 * ("The Internet Store" relays Meta verbatim) gets recorded once. The
 * unique index on (message_id, event_kind) dedupes retries — the BSP
 * occasionally re-fires events when our 200 response is slow to arrive.
 *
 * Status events ("sent", "delivered", "read", "failed") all carry the
 * same Meta message_id; each STATUS for that message is one row. So one
 * outbound notification typically produces 2-3 rows: sent → delivered →
 * read OR sent → failed.
 *
 * Indexes:
 *   - (message_id, event_kind)  — unique, dedup retries
 *   - (event_kind, received_at) — admin filters: "show me today's failures"
 *   - (recipient_id, received_at) — "what happened to messages to this phone"
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();

            // Categorises Meta payload shapes:
            //   message_status  — statuses[] array (sent/delivered/read/failed)
            //   inbound_message — messages[] array (devotee replied)
            //   template_status — template approval/rejection updates
            //   account_update  — quality rating, status changes
            //   phone_update    — phone-number metadata
            //   unknown         — any field we don't yet handle (logged for forensics)
            $table->enum('event_kind', [
                'message_status', 'inbound_message', 'template_status',
                'account_update', 'phone_update', 'unknown',
            ])->index();

            // Meta's wamid for status events; null for non-message events.
            $table->string('message_id', 96)->nullable();
            // sent / delivered / read / failed — null for non-status events.
            $table->string('status', 32)->nullable();
            // Recipient phone (E.164 without +). null for non-message events.
            $table->string('recipient_id', 32)->nullable()->index();
            // For failed events — Meta's numeric error code (131047, 131026, etc).
            $table->unsignedInteger('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Full raw payload for forensic debugging. JSON column keeps
            // it queryable (e.g. find all events with a specific button
            // click), but the parsed fields above cover 95% of queries.
            $table->json('payload');

            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            // Composite unique key for dedup: same message getting the
            // same status twice is a duplicate webhook delivery, not a
            // new event. Includes status because one message progresses
            // sent → delivered → read and each is a distinct event.
            $table->unique(['message_id', 'status'], 'wa_webhook_event_dedup');
            $table->index(['event_kind', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_whatsapp_webhook_events');
    }
};
