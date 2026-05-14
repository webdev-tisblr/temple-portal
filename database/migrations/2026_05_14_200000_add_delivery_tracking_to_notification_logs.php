<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds delivery-status tracking to temple_notification_logs.
 *
 * The base table records what we ATTEMPTED to send (status: sent / failed
 * / skipped / pending). It does NOT record what actually happened at the
 * recipient — a `sent` row only means Meta's Cloud API accepted our
 * request, not that the devotee's phone ever rang. The new columns
 * persist what WhatsApp tells us via webhook events:
 *
 *   provider_message_id   — Meta's wamid.XXX returned at send time.
 *                           Indexed because every inbound webhook event
 *                           matches by it.
 *   delivery_status       — accepted by the channel API → enroute →
 *                           delivered to handset → read → or failed
 *                           at any point along the way.
 *   delivery_status_at    — timestamp of the latest status update.
 *   failure_reason        — Meta's error message for failed deliveries,
 *                           e.g. "Message failed to send because more
 *                           than 24 hours have passed since the customer
 *                           last replied to this number" (code 131047).
 *
 * Email/SMS/Push drivers may use the same columns later if their
 * providers expose webhooks; for now only WhatsApp wires them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('temple_notification_logs', function (Blueprint $table) {
            // Meta's wamid is up to ~60 chars in current spec; 96 leaves
            // headroom for BSP-wrapped IDs that may prepend metadata.
            $table->string('provider_message_id', 96)->nullable()->after('provider_response_code');
            $table->index('provider_message_id');

            // Status lifecycle for messaging channels:
            //   sent       → accepted by upstream API (current status='sent' means this)
            //   delivered  → reached recipient device
            //   read       → recipient opened it (WhatsApp blue ticks)
            //   failed     → delivery permanently failed
            //   undelivered → temporarily failed (rare for WhatsApp; mainly SMS)
            // Nullable because we don't get a delivery webhook for every
            // channel (push, email currently); existing rows have no value.
            $table->enum('delivery_status', ['sent', 'delivered', 'read', 'failed', 'undelivered'])
                ->nullable()
                ->after('provider_message_id');
            $table->timestamp('delivery_status_at')->nullable()->after('delivery_status');
            $table->text('failure_reason')->nullable()->after('delivery_status_at');

            $table->index(['delivery_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_logs', function (Blueprint $table) {
            $table->dropIndex(['delivery_status', 'created_at']);
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'delivery_status', 'delivery_status_at', 'failure_reason']);
        });
    }
};
