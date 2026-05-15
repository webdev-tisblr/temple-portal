<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the denormalised PAN snapshot from temple_donations. The
     * canonical home for a devotee's PAN is temple_devotees.pan_encrypted
     * — this snapshot was a duplicate that drifted on every donation
     * write and was never the source of truth at receipt-generation time.
     *
     * Also dropping the dead `receipt_id` column (FK to a long-gone
     * table — Receipt80G uses donation_id reverse-lookup instead).
     *
     * Receipt PDFs continue to render the correct PAN: ReceiptService
     * now decrypts from $donation->devotee->pan_encrypted, and the
     * decrypted plaintext is snapshotted into temple_receipt_80g.pan_number
     * at receipt-generation time — so historical receipts stay immutable.
     */
    public function up(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            // The composite index (is_80g_eligible, pan_verified) must
            // go before we can drop pan_verified.
            try {
                $table->dropIndex(['is_80g_eligible', 'pan_verified']);
            } catch (\Throwable) {
                // Index name may differ across MySQL versions; harmless to skip.
            }
        });

        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropColumn(['pan_number_encrypted', 'pan_verified', 'receipt_id']);
        });
    }

    public function down(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            $table->text('pan_number_encrypted')->nullable();
            $table->boolean('pan_verified')->default(false);
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->index(['is_80g_eligible', 'pan_verified']);
        });
    }
};
