<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a `recipients` JSON column to notification_templates.
 *
 * Original schema modelled recipients as a single (recipient_strategy,
 * recipient_value) pair. That couldn't express "fan this exact body
 * out to the devotee AND every pujari AND a fixed phone number" from
 * one template row — the admin had to duplicate the template per
 * recipient and keep three bodies in sync forever.
 *
 * New shape: `recipients` is a JSON array of {strategy, value}
 * objects. Each item independently resolves at dispatch time. The
 * NotificationService still expands admin_role into N per-admin
 * deliveries inside the recipient loop, so behaviour is unchanged
 * for single-recipient rows.
 *
 * Backwards compat: the legacy columns recipient_strategy +
 * recipient_value stay in place. NotificationService reads `recipients`
 * first; if it's null/empty it falls back to a synthesised single-item
 * array built from the legacy columns. That keeps seeded templates,
 * existing rows, and any pending data migrations all working without
 * touching the dispatch path.
 *
 * Backfill: every existing row gets its legacy pair wrapped into a
 * one-item recipients array so the new code path treats it the same
 * way regardless of which fields are populated.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->json('recipients')->nullable()->after('recipient_value');
        });

        // Backfill in a single UPDATE — JSON_OBJECT/JSON_ARRAY are
        // MySQL 5.7+ which Hostinger ships. We only touch rows where
        // recipients is still null AND the legacy strategy is set,
        // so re-running the migration after a manual edit is safe.
        DB::statement(<<<'SQL'
            UPDATE temple_notification_templates
            SET recipients = JSON_ARRAY(
                JSON_OBJECT(
                    'strategy', recipient_strategy,
                    'value', recipient_value
                )
            )
            WHERE recipients IS NULL
              AND recipient_strategy IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropColumn('recipients');
        });
    }
};
