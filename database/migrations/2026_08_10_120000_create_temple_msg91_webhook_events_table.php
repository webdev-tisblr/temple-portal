<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-event audit log for inbound MSG91 SMS delivery reports (DLR).
 *
 * Same shape as temple_razorpay_webhook_events / temple_whatsapp_webhook_events:
 * one row per delivery report, keyed by a deterministic `event_key` so a
 * retried POST collapses into a no-op instead of re-writing the matching
 * notification log.
 *
 * WHY this table exists at all
 * ---------------------------
 * MSG91's Flow API is fire-and-forget. Measured against the live trust
 * account on 2026-08-10: a POST carrying a deliberately WRONG auth key
 * still answers HTTP 200 {"type":"success"}, and the legacy balance.php
 * returns 0 for a wrong key as readily as a right one. Nothing at all is
 * validated synchronously — the real failure ("Template ID Missing or
 * Invalid Template") only ever appears in MSG91's own dashboard.
 *
 * The delivery webhook is therefore the ONLY channel through which this
 * system can learn whether an SMS actually reached a handset. Everything
 * before it is "MSG91 accepted the submission", which is a different fact.
 *
 * PII: the delivery report carries the recipient's full mobile number.
 * We never persist it. `recipient_masked` holds the same 91••••3210 form
 * the rest of the platform uses, `recipient_hash` is a sha256 of the last
 * 10 digits for joins, and Msg91WebhookEvent::redactPayload() masks the
 * number inside the stored raw JSON before it is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_msg91_webhook_events', function (Blueprint $table) {
            $table->id();

            // Deterministic dedup key: sha256(request_id|number|status|date).
            // MSG91 has no event id of its own, so we synthesise one from
            // the fields that identify a single (message, status) transition
            // — mirroring the (message_id, status) composite unique used for
            // WhatsApp. insertOrIgnore on this column is the idempotency
            // guarantee; a retried delivery is silently dropped.
            $table->char('event_key', 64)->unique();

            // MSG91's submission id, echoed back on the delivery report.
            // This is what we match against
            // temple_notification_logs.provider_message_id — captured at
            // send time by SmsService::sendTemplate().
            $table->string('request_id', 96)->nullable()->index();

            // Masked recipient only — never the full number.
            $table->string('recipient_masked', 32)->nullable();
            $table->char('recipient_hash', 64)->nullable()->index();

            // MSG91's own numeric code (1 = delivered, 8 = sent/submitted,
            // everything else in their DLR table is a non-delivery) and the
            // raw textual status/description, both kept VERBATIM. The
            // description is the single most useful field in this table:
            // it is the sentence the trust would otherwise have to log into
            // the MSG91 dashboard to read.
            $table->string('status_code', 16)->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->text('description')->nullable();

            // Our normalised bucket, matching the delivery_status enum on
            // temple_notification_logs. Null when MSG91 sent a status we
            // do not recognise — we record the event and leave the log row
            // alone rather than guessing.
            $table->string('delivery_status', 32)->nullable()->index();

            // MSG91's timestamp for the transition (their clock, their
            // format) — parsed best-effort, null when unparseable.
            $table->timestamp('reported_at')->nullable();

            // Full raw payload, phone-redacted. Kept because MSG91 delivery
            // report shapes vary by account and by route; if the parser
            // above ever guesses wrong we still hold the original.
            $table->json('payload');

            // Which notification log row this event was applied to, if any.
            // Null means "no matching send" — an SMS sent outside the
            // notification pipeline, or a report for a request_id we never
            // recorded.
            $table->foreignId('notification_log_id')
                ->nullable()
                ->constrained('temple_notification_logs')
                ->nullOnDelete();

            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index(['received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_msg91_webhook_events');
    }
};
