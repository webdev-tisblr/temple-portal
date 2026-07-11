<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multilingual gap fill (batch 1): give Trustee.location,
 * ProductCategory.description and DonationType.description gu/hi/en
 * variants. Legacy single-language columns are kept and backfilled into
 * the _gu column so nothing is lost; the model accessors read _gu when a
 * translation is blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_trustees', function (Blueprint $t) {
            $t->string('location_gu')->nullable()->after('location');
            $t->string('location_hi')->nullable()->after('location_gu');
            $t->string('location_en')->nullable()->after('location_hi');
        });
        DB::table('temple_trustees')->whereNotNull('location')
            ->update(['location_gu' => DB::raw('location')]);

        Schema::table('temple_product_categories', function (Blueprint $t) {
            $t->text('description_gu')->nullable()->after('description');
            $t->text('description_hi')->nullable()->after('description_gu');
            $t->text('description_en')->nullable()->after('description_hi');
        });
        DB::table('temple_product_categories')->whereNotNull('description')
            ->update(['description_gu' => DB::raw('description')]);

        Schema::table('temple_donation_types', function (Blueprint $t) {
            $t->text('description_gu')->nullable()->after('description');
            $t->text('description_hi')->nullable()->after('description_gu');
            $t->text('description_en')->nullable()->after('description_hi');
        });
        DB::table('temple_donation_types')->whereNotNull('description')
            ->update(['description_gu' => DB::raw('description')]);

        // BlogPost excerpt already had _gu only — add hi/en.
        Schema::table('temple_blog_posts', function (Blueprint $t) {
            $t->text('excerpt_hi')->nullable()->after('excerpt_gu');
            $t->text('excerpt_en')->nullable()->after('excerpt_hi');
        });
    }

    public function down(): void
    {
        Schema::table('temple_trustees', function (Blueprint $t) {
            $t->dropColumn(['location_gu', 'location_hi', 'location_en']);
        });
        Schema::table('temple_product_categories', function (Blueprint $t) {
            $t->dropColumn(['description_gu', 'description_hi', 'description_en']);
        });
        Schema::table('temple_donation_types', function (Blueprint $t) {
            $t->dropColumn(['description_gu', 'description_hi', 'description_en']);
        });
        Schema::table('temple_blog_posts', function (Blueprint $t) {
            $t->dropColumn(['excerpt_hi', 'excerpt_en']);
        });
    }
};
