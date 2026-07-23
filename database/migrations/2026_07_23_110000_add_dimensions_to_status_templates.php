<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Store each status template's intrinsic pixel size so the app can lay the
 * listing out as a true-ratio masonry (and position the devotee-photo slot
 * over the preview). Backfills existing rows by reading the image bytes
 * from R2 — failures leave the columns null and the app falls back to 9:16.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_status_templates', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('greeting_card_config');
            $table->unsignedInteger('height')->nullable()->after('width');
        });

        foreach (DB::table('temple_status_templates')->get(['id', 'greeting_card_template']) as $row) {
            if (empty($row->greeting_card_template)) {
                continue;
            }
            try {
                $bytes = Storage::disk('r2')->get($row->greeting_card_template);
                $size = $bytes ? getimagesizefromstring($bytes) : false;
                if ($size !== false) {
                    DB::table('temple_status_templates')->where('id', $row->id)
                        ->update(['width' => $size[0], 'height' => $size[1]]);
                }
            } catch (\Throwable $e) {
                Log::warning('Status template dimension backfill failed', [
                    'id' => $row->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('temple_status_templates', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
