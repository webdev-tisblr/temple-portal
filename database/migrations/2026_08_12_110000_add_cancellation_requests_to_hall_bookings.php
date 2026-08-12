<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devotee-initiated hall cancellation REQUESTS (2026-08-12).
 *
 * A devotee can ask to cancel; they can never cancel. The trust decides,
 * because a hall cancellation carries a refund and a freed date that other
 * devotees are queuing for.
 *
 * Deliberately NOT a new `status` enum value. The request is a separate
 * timestamp precisely so the booking KEEPS its `confirmed` status while the
 * trust considers it — and `confirmed` is in HallBooking::OCCUPYING_STATUSES,
 * so the hall stays blocked. A `cancel_requested` status would have dropped
 * out of that list (or needed adding to it, in every one of the availability,
 * conflict and capacity queries), and a merely REQUESTED cancellation
 * releasing the date would let someone else book it out from under a request
 * the trust then declines.
 *
 * Approval is the existing status flip to `cancelled`, which is what already
 * frees the date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table): void {
            $table->timestamp('cancel_requested_at')->nullable()->after('admin_notes');
            $table->string('cancel_reason', 500)->nullable()->after('cancel_requested_at');
            // Set when the trust closes the request out, either way. Lets an
            // admin decline a request without it reappearing as pending
            // forever, and keeps the audit trail of who asked and when.
            $table->timestamp('cancel_responded_at')->nullable()->after('cancel_reason');

            // The admin queue is "open requests, oldest first".
            $table->index(['cancel_requested_at', 'cancel_responded_at'], 'hall_bookings_cancel_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table): void {
            $table->dropIndex('hall_bookings_cancel_queue_idx');
            $table->dropColumn(['cancel_requested_at', 'cancel_reason', 'cancel_responded_at']);
        });
    }
};
