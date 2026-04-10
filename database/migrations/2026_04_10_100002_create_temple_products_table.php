<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->string('name_gu', 255);
            $table->string('name_hi', 255);
            $table->string('name_en', 255);
            $table->string('slug', 255)->unique();
            $table->text('description_gu')->nullable();
            $table->text('description_hi')->nullable();
            $table->text('description_en')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('stock_quantity')->default(0);
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('category_id')->references('id')->on('temple_product_categories');
            $table->index(['is_active', 'sort_order']);
            $table->index('slug');
            $table->index('category_id');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_products');
    }
};
