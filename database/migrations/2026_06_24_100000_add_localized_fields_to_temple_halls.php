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
        Schema::table('temple_halls', function (Blueprint $table) {
            $existing = Schema::getColumnListing('temple_halls');

            if (! in_array('name_gu', $existing)) {
                $table->string('name_gu', 255)->nullable()->after('name');
                $table->string('name_hi', 255)->nullable()->after('name_gu');
                $table->string('name_en', 255)->nullable()->after('name_hi');
            }
            if (! in_array('description_gu', $existing)) {
                $table->text('description_gu')->nullable()->after('description');
                $table->text('description_hi')->nullable()->after('description_gu');
                $table->text('description_en')->nullable()->after('description_hi');
            }
            if (! in_array('rules_gu', $existing)) {
                $table->text('rules_gu')->nullable()->after('rules');
                $table->text('rules_hi')->nullable()->after('rules_gu');
                $table->text('rules_en')->nullable()->after('rules_hi');
            }
        });

        // Seed the new Gujarati columns from the existing single-language data
        // so current halls keep displaying (the accessors fall back to _gu).
        DB::table('temple_halls')->update([
            'name_gu' => DB::raw('COALESCE(name_gu, name)'),
            'description_gu' => DB::raw('COALESCE(description_gu, description)'),
            'rules_gu' => DB::raw('COALESCE(rules_gu, rules)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('temple_halls', function (Blueprint $table) {
            $table->dropColumn([
                'name_gu', 'name_hi', 'name_en',
                'description_gu', 'description_hi', 'description_en',
                'rules_gu', 'rules_hi', 'rules_en',
            ]);
        });
    }
};
