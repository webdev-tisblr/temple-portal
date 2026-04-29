<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_daily_darshan_photos', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('caption_gu')->nullable();
            $table->string('caption_hi')->nullable();
            $table->string('caption_en')->nullable();
            $table->date('captured_on')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_daily_darshan_photos');
    }
};
