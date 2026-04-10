<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_gu', 255);
            $table->string('name_hi', 255);
            $table->string('name_en', 255);
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_product_categories');
    }
};
