<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the (key, channel) UNIQUE index and replace it with a plain
 * non-unique composite index.
 *
 * Original schema (2026_05_07) allowed only one row per (trigger,
 * channel). That made fan-out scenarios impossible — e.g. seva.booking.reminder
 * via PUSH needs to reach both:
 *   • the devotee ("Your seva is in 3 hours …")
 *   • an admin role ("Devotee X has a seva in 3 hours …")
 * with different bodies / recipient strategies. Same trigger, same
 * channel, different recipient + content → two rows.
 *
 * NotificationService already iterates every enabled template for the
 * key and fans out per channel, so removing the constraint is a pure
 * data-model fix — no service changes needed.
 *
 * The non-unique composite index preserves the leftmost-prefix
 * optimization for the dispatcher's `where key + is_enabled` query.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropUnique('temple_notification_templates_key_channel_unique');
            $table->index(['key', 'channel'], 'temple_notification_templates_key_channel_index');
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropIndex('temple_notification_templates_key_channel_index');
            // NB: re-adding the unique would fail if any duplicates now
            // exist. Production rollback must dedupe first.
            $table->unique(['key', 'channel']);
        });
    }
};
