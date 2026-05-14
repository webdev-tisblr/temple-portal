<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-attempt audit log for every notification the platform tries to send.
 *
 * One row per (trigger × channel × recipient × dispatch). NotificationService
 * inserts a `pending` row before invoking the driver and updates it to
 * `sent` / `failed` / `skipped` once the driver returns. Without this,
 * admins have zero visibility into "did the donor actually get their 80G
 * receipt email?" beyond grepping Laravel logs.
 *
 * Indexes:
 *   - (template_key, created_at)        — admin filters by trigger over a date range
 *   - (recipient_hash, created_at)      — "show me everything we tried to send to this phone"
 *   - (status, created_at)              — failure dashboards
 *   - (idempotency_key)                 — dedup short-window duplicates (e.g. webhook + verify)
 *   - (devotee_id, created_at)          — devotee-scoped history surfaced to the app
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_notification_logs', function (Blueprint $table) {
            $table->id();

            // Trigger key (e.g. donation.confirmed, auth.otp). NOT a foreign key
            // because triggers may be added/removed in code without backfilling logs.
            $table->string('template_key', 64);

            // Snapshot of which template row was used. nullable in case the template
            // was deleted after the log was written — we still want the history.
            $table->foreignId('notification_template_id')
                ->nullable()
                ->constrained('temple_notification_templates')
                ->nullOnDelete();

            $table->enum('channel', ['email', 'whatsapp', 'sms', 'push']);

            // Recipient as it was actually used. Stored masked for admin UI display
            // (avoid full email/phone leaking in CSV exports), plus a hash for joins.
            $table->string('recipient_masked', 80)->nullable();
            $table->char('recipient_hash', 64)->nullable(); // sha256 hex

            // Optional devotee tie-in. Not required (trust admin & fixed-recipient
            // strategies have no devotee), but enables a "your messages" surface
            // in the app later. NOTE: temple_devotees uses UUID primary keys —
            // foreignUuid() matches char(36); foreignId() would fail FK creation
            // with "incorrectly formed" against the UUID column.
            $table->foreignUuid('devotee_id')
                ->nullable()
                ->constrained('temple_devotees')
                ->nullOnDelete();

            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])
                ->default('pending');

            // Why was it skipped? Why did it fail? Used by the admin "Resend" action
            // and surfaced in the NotificationLogResource detail view.
            $table->string('skip_reason', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_response_code', 80)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Caller-supplied idempotency key (e.g. "payment:{payment_id}:donation.confirmed:email").
            // NotificationService skips re-sending the same key within a 5-minute window.
            // Nullable because most triggers don't need it.
            $table->string('idempotency_key', 191)->nullable();

            // The rendered context snapshot, redacted. Useful for debugging "why did
            // this email render with an empty {{ amount }}?" without re-running the
            // whole flow. Capped to ~8KB at write time.
            $table->json('context_snapshot')->nullable();

            $table->timestamps();

            $table->index(['template_key', 'created_at']);
            $table->index(['recipient_hash', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('idempotency_key');
            $table->index(['devotee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_notification_logs');
    }
};
