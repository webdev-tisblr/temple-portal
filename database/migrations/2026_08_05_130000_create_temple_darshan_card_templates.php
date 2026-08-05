<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-designed daily-darshan share card templates. One row per format
 * (story 1080×1920 / square 1080×1080): an uploaded background per
 * language + the shared drag-drop overlay layout (same editor blade and
 * column naming trick as StatusTemplate / DonationType).
 *
 * When no active row exists for a format the API falls back to the
 * original programmatically-drawn design (DarshanShareCardService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_darshan_card_templates', function (Blueprint $table) {
            $table->id();
            $table->string('format', 20)->unique(); // story | square
            $table->string('greeting_card_template', 500)->nullable();
            $table->string('greeting_card_template_hi', 500)->nullable();
            $table->string('greeting_card_template_en', 500)->nullable();
            $table->json('greeting_card_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_darshan_card_templates');
    }
};
