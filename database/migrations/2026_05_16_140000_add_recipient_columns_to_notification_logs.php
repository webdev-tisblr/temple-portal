<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the specific (recipient_strategy, recipient_value) used for
 * each dispatch attempt.
 *
 * Pre-multi-recipient, a single notification_template row had exactly
 * one (recipient_strategy, recipient_value) pair, so reading them
 * back off the template at Resend time gave the right answer. After
 * the recipients[] JSON column landed (2026_05_16_130000), a single
 * template fans out into N deliveries — one per recipient — and the
 * log row no longer tells you which one. Resend therefore reverted
 * to the template's legacy default and failed for everyone but the
 * first configured recipient.
 *
 * Storing the per-attempt strategy/value on the log row closes that
 * gap: Resend reads exactly what was attempted and reproduces it.
 * Backfill is intentionally left null — existing rows continue to
 * fall back to the template defaults at Resend time, same as today.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('temple_notification_logs', function (Blueprint $table) {
            $table->string('recipient_strategy', 32)->nullable()->after('recipient_hash');
            $table->string('recipient_value')->nullable()->after('recipient_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_logs', function (Blueprint $table) {
            $table->dropColumn(['recipient_strategy', 'recipient_value']);
        });
    }
};
