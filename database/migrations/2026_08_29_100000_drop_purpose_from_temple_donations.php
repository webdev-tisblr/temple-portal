<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the donation "purpose / message" free-text field (2026-08-29).
 *
 * It duplicated the donation TYPE picker sitting directly above it — the
 * label for that dropdown is literally "Donation purpose" — so donors either
 * left it blank or restated the type they had just chosen. It was collected on
 * the web form, the app form and the counter-entry screen, printed on exports
 * and offered as a `{{ purpose }}` placeholder, and none of it earned its
 * place.
 *
 * The column goes for real, not just the UI. What survives:
 *
 *   • `temple_receipts_80g.donation_purpose` — a statutory snapshot taken at
 *     the moment a receipt was issued. Those are already-issued documents and
 *     must keep reading exactly as they were signed, so that column stays and
 *     keeps its historical values. ReceiptService simply stops writing new
 *     ones.
 *   • `temple_hall_bookings.purpose` — a different field on a different
 *     module ("what is the hall being booked for"), still required and still
 *     printed on the hall invoice. Untouched.
 *
 * down() restores the column but NOT its data: the values are gone. That is
 * accepted — the field held donor free text of no reporting value, and the
 * pre-migration DB snapshot the deploy takes is the real recovery path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('temple_donations', 'purpose')) {
            return;
        }

        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('temple_donations', 'purpose')) {
            return;
        }

        Schema::table('temple_donations', function (Blueprint $table) {
            $table->string('purpose', 500)->nullable()->after('donation_type');
        });
    }
};
