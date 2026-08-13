<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hall booking reminders (2026-08-13).
 *
 * Mirrors the seva reminder system — admin-configured rules materialised into
 * schedule rows when a booking confirms, drained by a cron — with three
 * deliberate departures from what the seva tables carry today:
 *
 * 1. NO global (hall_id NULL) rules. The seva equivalent was retired
 *    2026-07-12 along with its admin page; its scopeGlobal() is dead code and
 *    `reminder_mode` is never read. Hall rules are always per-hall.
 *
 * 2. NO legacy offsets fallback. That exists on the seva side only because
 *    reminders predate the rule system; halls have no history to preserve.
 *
 * 3. ONE unique index, not two. temple_seva_reminder_schedules still carries a
 *    redundant (booking, rule_id) unique that a later migration forgot to drop
 *    when it added the correct three-column one — which would block a second
 *    offset for the same rule. Only the right one is created here.
 *
 * Note hall_booking_id is a plain foreignId: hall bookings use bigint ids,
 * unlike seva bookings which are UUIDs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_hall_reminder_rules', function (Blueprint $table): void {
            $table->id();

            // Always set — see note 1 above.
            $table->foreignId('hall_id')->constrained('temple_halls')->cascadeOnDelete();

            // How long before the booking starts this fires. Entered in the
            // admin as a friendly picker (1 day, 2 hours…) and stored as
            // minutes so the scheduler needs no parsing.
            $table->unsignedInteger('offset_minutes');

            // devotee | admin_role | custom_phone. No `assignee`: halls have
            // no assigned karyakar, unlike sevas.
            $table->string('recipient_type', 20)->default('devotee');
            $table->string('recipient_value', 500)->nullable();

            $table->string('channel', 16)->default('whatsapp');

            // WhatsApp only: Meta owns approved copy, so a WhatsApp rule
            // points at a stored template and overrides nothing but the
            // recipient. Push/email use the inline columns below instead.
            $table->foreignId('notification_template_id')->nullable()
                ->constrained('temple_notification_templates')->nullOnDelete();

            $table->string('title_gu', 500)->nullable();
            $table->string('title_hi', 500)->nullable();
            $table->string('title_en', 500)->nullable();
            $table->text('body_gu')->nullable();
            $table->text('body_hi')->nullable();
            $table->text('body_en')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hall_id', 'is_active']);
        });

        Schema::create('temple_hall_reminder_schedules', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('hall_booking_id')
                ->constrained('temple_hall_bookings')->cascadeOnDelete();

            $table->string('offset', 16);

            $table->foreignId('rule_id')->nullable()
                ->constrained('temple_hall_reminder_rules')->cascadeOnDelete();

            $table->dateTime('fire_at');
            $table->string('status', 16)->default('pending');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            // The dedupe key the scheduler's firstOrCreate must match EXACTLY.
            // On the seva side a mismatch here raised a 1062 inside the
            // payment-capture transaction and rolled back a live capture
            // (2026-08-04). Three columns, because the trust legitimately
            // configures several rules at the same offset — devotee, admin and
            // a custom phone all "1 day before" — and a two-column key would
            // let only the first ever fire.
            $table->unique(['hall_booking_id', 'rule_id', 'offset'], 'thrs_booking_rule_offset_unique');

            // The dispatcher's only query shape.
            $table->index(['status', 'fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_hall_reminder_schedules');
        Schema::dropIfExists('temple_hall_reminder_rules');
    }
};
