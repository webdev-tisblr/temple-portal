<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_sevas', function (Blueprint $table) {
            $table->id();
            $table->string('name_gu', 255);
            $table->string('name_hi', 255);
            $table->string('name_en', 255);
            $table->text('description_gu')->nullable();
            $table->text('description_hi')->nullable();
            $table->text('description_en')->nullable();
            $table->enum('category', ['shringar', 'vastra', 'annadan', 'puja', 'special', 'other']);
            $table->decimal('price', 10, 2);
            $table->decimal('min_price', 10, 2)->nullable();
            $table->boolean('is_variable_price')->default(false);
            $table->string('image_path', 500)->nullable();
            $table->json('slot_config')->nullable();
            $table->boolean('requires_booking')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_sevas');
    }
};
