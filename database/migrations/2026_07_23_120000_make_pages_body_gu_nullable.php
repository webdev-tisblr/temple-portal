<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Block-built CMS pages have no legacy HTML body, so the RichEditor
 * dehydrates body_gu as NULL — but the column was NOT NULL, making every
 * save of a blocks-only page 500 with "Column 'body_gu' cannot be null".
 * body_hi/body_en were already nullable; bring body_gu in line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_pages', function (Blueprint $table) {
            $table->longText('body_gu')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restore NOT NULL — backfill empties first so the change can apply.
        \Illuminate\Support\Facades\DB::table('temple_pages')
            ->whereNull('body_gu')->update(['body_gu' => '']);

        Schema::table('temple_pages', function (Blueprint $table) {
            $table->longText('body_gu')->nullable(false)->change();
        });
    }
};
