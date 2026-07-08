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
        Schema::table('temple_pages', function (Blueprint $table) {
            $table->json('blocks_gu')->nullable()->after('body_en');
            $table->json('blocks_hi')->nullable()->after('blocks_gu');
            $table->json('blocks_en')->nullable()->after('blocks_hi');
        });

        // Seed a starter "History / Etihas" page built with content blocks so
        // the builder + app WebView have something to show. The trust edits it.
        if (! DB::table('temple_pages')->where('slug', 'history')->exists()) {
            $blocksGu = json_encode([
                ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => 'ઇતિહાસ']],
                ['type' => 'paragraph', 'data' => ['html' => '<p>અહીં શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ અને મંદિરનો ઇતિહાસ ઉમેરો. (એડમિન પેનલમાંથી આ પાનું બ્લોક બિલ્ડરથી સંપાદિત કરો.)</p>']],
            ], JSON_UNESCAPED_UNICODE);

            $now = now();
            DB::table('temple_pages')->insert([
                'slug' => 'history',
                'title_gu' => 'ઇતિહાસ',
                'title_hi' => 'इतिहास',
                'title_en' => 'History',
                'body_gu' => '',
                'blocks_gu' => $blocksGu,
                'status' => 'published',
                'template' => 'default',
                'sort_order' => 0,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('temple_pages', function (Blueprint $table) {
            $table->dropColumn(['blocks_gu', 'blocks_hi', 'blocks_en']);
        });
    }
};
