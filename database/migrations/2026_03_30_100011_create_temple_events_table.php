<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_events', function (Blueprint $table) {
            $table->id();
            $table->string('title_gu', 500);
            $table->string('title_hi', 500)->nullable();
            $table->string('title_en', 500)->nullable();
            $table->text('description_gu')->nullable();
            $table->text('description_hi')->nullable();
            $table->text('description_en')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location', 500)->default('Shree Pataliya Hanumanji Temple, Antarjal');
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->enum('status', ['draft', 'published', 'cancelled'])->default('draft');
            $table->enum('event_type', ['festival', 'special_puja', 'satsang', 'cultural', 'other'])->default('festival');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['start_date', 'end_date']);
            $table->index('status');
            $table->index('is_featured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_events');
    }
};
