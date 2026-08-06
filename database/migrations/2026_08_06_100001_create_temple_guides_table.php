<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_guides', function (Blueprint $table) {
            $table->id();
            // Category is optional — an uncategorised guide still lists.
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('temple_guide_categories')
                ->nullOnDelete();
            $table->string('title_gu');
            $table->string('title_hi')->nullable();
            $table->string('title_en')->nullable();
            $table->string('summary_gu', 500)->nullable();
            $table->string('summary_hi', 500)->nullable();
            $table->string('summary_en', 500)->nullable();
            $table->longText('body_gu')->nullable();
            $table->longText('body_hi')->nullable();
            $table->longText('body_en')->nullable();
            $table->string('cover_image', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['category_id', 'sort_order']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_guides');
    }
};
