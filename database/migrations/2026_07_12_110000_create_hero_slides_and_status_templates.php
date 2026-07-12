<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two launch features:
 *  • temple_hero_slides — admin-managed homepage hero slider (image +
 *    localized heading/sub/CTA, alignment, theme, transition, schedule).
 *  • temple_status_templates — admin-uploaded greeting/status templates
 *    devotees personalise on demand (name + photo) and share. Columns
 *    deliberately reuse the greeting-card names so the existing drag-drop
 *    overlay editor works unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_hero_slides', function (Blueprint $t) {
            $t->id();
            $t->string('image_path', 500);
            $t->string('image_path_mobile', 500)->nullable();
            $t->string('heading_gu', 500)->nullable();
            $t->string('heading_hi', 500)->nullable();
            $t->string('heading_en', 500)->nullable();
            $t->text('sub_gu')->nullable();
            $t->text('sub_hi')->nullable();
            $t->text('sub_en')->nullable();
            $t->string('cta_label_gu', 200)->nullable();
            $t->string('cta_label_hi', 200)->nullable();
            $t->string('cta_label_en', 200)->nullable();
            $t->string('cta_url', 500)->nullable();
            $t->string('align', 10)->default('center');      // left|center|right
            $t->string('theme', 10)->default('dark');        // dark|light text
            $t->unsignedTinyInteger('overlay_opacity')->default(40); // 0–90 (%)
            $t->string('transition', 10)->default('fade');   // fade|slide|zoom
            $t->unsignedTinyInteger('duration_seconds')->default(6);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });

        Schema::create('temple_status_templates', function (Blueprint $t) {
            $t->id();
            $t->string('title_gu', 200);
            $t->string('title_hi', 200)->nullable();
            $t->string('title_en', 200)->nullable();
            $t->string('greeting_card_template', 500);   // bg image on r2 (editor expects this name)
            $t->json('greeting_card_config')->nullable(); // overlay slots (editor expects this name)
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_hero_slides');
        Schema::dropIfExists('temple_status_templates');
    }
};
