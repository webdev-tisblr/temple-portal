<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emergency wording hotfixes for the mobile app. The app bundles its
 * full gu/hi/en string files; this table holds ONLY the strings being
 * patched between store builds (post-release translation corrections,
 * typos). The app merges these over its bundled text on launch.
 * Rows are meant to be TEMPORARY — bake the fix into the app's l10n
 * files in the next store build, then delete the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_app_string_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150);          // app l10n key, e.g. home.qa_store
            $table->string('locale', 5);         // gu | hi | en
            $table->text('value');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_app_string_overrides');
    }
};
