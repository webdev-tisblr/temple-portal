<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Add columns without unique constraint on slug
        $columns = Schema::getColumnListing('temple_donation_campaigns');

        Schema::table('temple_donation_campaigns', function (Blueprint $table) use ($columns) {
            if (! in_array('slug', $columns)) {
                $table->string('slug', 255)->default('')->after('title_en');
            }
            if (! in_array('writeup_gu', $columns)) {
                $table->text('writeup_gu')->nullable()->after('description_en');
            }
            if (! in_array('writeup_hi', $columns)) {
                $table->text('writeup_hi')->nullable()->after('writeup_gu');
            }
            if (! in_array('writeup_en', $columns)) {
                $table->text('writeup_en')->nullable()->after('writeup_hi');
            }
            if (! in_array('media', $columns)) {
                $table->json('media')->nullable()->after('image_path');
            }
            if (! in_array('faqs', $columns)) {
                $table->json('faqs')->nullable()->after('media');
            }
            if (! in_array('show_donor_list', $columns)) {
                $table->boolean('show_donor_list')->default(true)->after('is_active');
            }
            if (! in_array('is_featured', $columns)) {
                $table->boolean('is_featured')->default(false)->after('show_donor_list');
            }
            if (! in_array('deleted_at', $columns)) {
                $table->softDeletes();
            }
        });

        // Step 2: Generate slugs for existing campaigns
        $campaigns = \Illuminate\Support\Facades\DB::table('temple_donation_campaigns')->get();
        foreach ($campaigns as $campaign) {
            $slug = Str::slug($campaign->title_en ?: $campaign->title_gu);
            // Ensure uniqueness by appending ID if slug is empty or duplicated
            if (empty($slug)) {
                $slug = 'campaign-' . $campaign->id;
            }
            $existing = \Illuminate\Support\Facades\DB::table('temple_donation_campaigns')
                ->where('slug', $slug)
                ->where('id', '!=', $campaign->id)
                ->exists();
            if ($existing) {
                $slug .= '-' . $campaign->id;
            }
            \Illuminate\Support\Facades\DB::table('temple_donation_campaigns')
                ->where('id', $campaign->id)
                ->update(['slug' => $slug]);
        }

        // Step 3: Now add the unique index
        Schema::table('temple_donation_campaigns', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('temple_donation_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'slug',
                'writeup_gu',
                'writeup_hi',
                'writeup_en',
                'media',
                'faqs',
                'show_donor_list',
                'is_featured',
            ]);
            $table->dropSoftDeletes();
        });
    }
};
