<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the seva "Sankalp" free-text field (2026-08-29).
 *
 * It was an optional box under the booking form asking for the devotee's
 * wish or intention. It is no longer wanted anywhere: not on the app form,
 * not on the website form, not on the counter-entry screen, and not as a
 * greeting-card variable.
 *
 * The column goes with it, so the values are lost. That is the decision
 * taken, not an oversight: the pre-migration DB snapshot the deploy takes is
 * the recovery path if it turns out to be wrong.
 *
 * KNOWN CONSEQUENCE, worth reading before this runs: `_sankalp` was a
 * placeable variable in the greeting-card editor. Any saved card layout that
 * already contains a _sankalp overlay keeps that overlay in its config — it
 * simply resolves to nothing now and draws nothing, which is the same rule a
 * blank text overlay has always followed. So old templates render fine, just
 * with a gap where the sankalp used to sit, and want re-laying-out. The
 * variable is gone from the editor's palette, so no NEW template can add one.
 *
 * down() restores the column but not its data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('temple_seva_bookings', 'sankalp')) {
            return;
        }

        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropColumn('sankalp');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('temple_seva_bookings', 'sankalp')) {
            return;
        }

        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->text('sankalp')->nullable()->after('devotee_name_for_seva');
        });
    }
};
