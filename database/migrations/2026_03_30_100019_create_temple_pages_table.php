<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 255)->unique();
            $table->string('title_gu', 500);
            $table->string('title_hi', 500)->nullable();
            $table->string('title_en', 500)->nullable();
            $table->longText('body_gu');
            $table->longText('body_hi')->nullable();
            $table->longText('body_en')->nullable();
            $table->string('featured_image_path', 500)->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('parent_slug', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('template', 100)->default('default');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('slug');
            $table->index('status');
            $table->index('parent_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_pages');
    }
};
