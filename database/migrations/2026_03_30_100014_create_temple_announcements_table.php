<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title_gu', 500);
            $table->string('title_hi', 500)->nullable();
            $table->string('title_en', 500)->nullable();
            $table->text('body_gu');
            $table->text('body_hi')->nullable();
            $table->text('body_en')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->boolean('is_urgent')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_announcements');
    }
};
