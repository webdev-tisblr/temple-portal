<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the unused `gotra` column. No business logic depends on it —
     * it was a presentational form field only. Removed alongside all
     * UI/API/admin references in the same change set.
     */
    public function up(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropColumn('gotra');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->string('gotra', 255)->nullable()->after('devotee_name_for_seva');
        });
    }
};
