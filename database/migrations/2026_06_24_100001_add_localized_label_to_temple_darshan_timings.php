<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_darshan_timings', function (Blueprint $table) {
            $existing = Schema::getColumnListing('temple_darshan_timings');
            if (! in_array('label_gu', $existing)) {
                $table->string('label_gu', 255)->nullable()->after('label');
                $table->string('label_hi', 255)->nullable()->after('label_gu');
                $table->string('label_en', 255)->nullable()->after('label_hi');
            }
        });

        DB::table('temple_darshan_timings')->update([
            'label_gu' => DB::raw('COALESCE(label_gu, label)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('temple_darshan_timings', function (Blueprint $table) {
            $table->dropColumn(['label_gu', 'label_hi', 'label_en']);
        });
    }
};
