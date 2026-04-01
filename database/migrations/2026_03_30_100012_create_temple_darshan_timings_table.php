<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_darshan_timings', function (Blueprint $table) {
            $table->id();
            $table->enum('day_type', ['regular', 'sunday', 'festival', 'special'])->default('regular');
            $table->string('label', 255)->nullable();
            $table->time('morning_open');
            $table->time('morning_close');
            $table->time('afternoon_open')->nullable();
            $table->time('afternoon_close')->nullable();
            $table->time('evening_open');
            $table->time('evening_close');
            $table->time('aarti_morning')->nullable();
            $table->time('aarti_evening')->nullable();
            $table->text('special_note_gu')->nullable();
            $table->text('special_note_hi')->nullable();
            $table->text('special_note_en')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
            $table->index(['effective_from', 'effective_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_darshan_timings');
    }
};
