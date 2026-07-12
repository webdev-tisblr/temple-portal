<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seva Reminder Rules — one rule = WHEN (offset before the seva moment)
 * + WHO (devotee / admin role / seva assignee / custom phone) + HOW
 * (channel) + WHAT (inline gu/hi/en message, or a WhatsApp template ref).
 *
 * seva_id NULL      → global default rule (applies to every seva whose
 *                     reminder_mode is 'global').
 * seva_id set       → custom rule for that seva (reminder_mode 'custom').
 *
 * temple_sevas.reminder_mode: global | custom | none.
 * temple_seva_reminder_schedules.rule_id: which rule produced the row —
 * NULL for legacy offset-based rows (old path keeps working).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_seva_reminder_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('seva_id')->nullable()
                ->constrained('temple_sevas')->cascadeOnDelete();
            $t->unsignedInteger('offset_minutes');
            // devotee | admin_role | assignee | custom_phone
            $t->string('recipient_type', 20)->default('devotee');
            // role name for admin_role; comma-separated numbers for custom_phone
            $t->string('recipient_value', 500)->nullable();
            // whatsapp | push | email
            $t->string('channel', 16)->default('whatsapp');
            // WhatsApp rules reference an approved template row.
            $t->foreignId('notification_template_id')->nullable()
                ->constrained('temple_notification_templates')->nullOnDelete();
            // Inline message for push (title+body) / email (subject+body).
            $t->string('title_gu', 500)->nullable();
            $t->string('title_hi', 500)->nullable();
            $t->string('title_en', 500)->nullable();
            $t->text('body_gu')->nullable();
            $t->text('body_hi')->nullable();
            $t->text('body_en')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();

            $t->index(['seva_id', 'is_active']);
        });

        Schema::table('temple_sevas', function (Blueprint $t) {
            $t->string('reminder_mode', 10)->default('global')->after('reminder_offsets');
        });

        Schema::table('temple_seva_reminder_schedules', function (Blueprint $t) {
            $t->foreignId('rule_id')->nullable()->after('offset')
                ->constrained('temple_seva_reminder_rules')->cascadeOnDelete();
            // One schedule row per (booking, rule). NULL rule_id (legacy
            // offset rows) is exempt — MySQL treats NULLs as distinct.
            $t->unique(['seva_booking_id', 'rule_id'], 'uniq_booking_rule');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_reminder_schedules', function (Blueprint $t) {
            $t->dropUnique('uniq_booking_rule');
            $t->dropConstrainedForeignId('rule_id');
        });
        Schema::table('temple_sevas', function (Blueprint $t) {
            $t->dropColumn('reminder_mode');
        });
        Schema::dropIfExists('temple_seva_reminder_rules');
    }
};
