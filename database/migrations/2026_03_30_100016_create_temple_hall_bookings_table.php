<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_hall_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid('devotee_id');
            $table->unsignedBigInteger('hall_id');
            $table->date('booking_date');
            $table->enum('booking_type', ['full_day', 'half_day_morning', 'half_day_evening'])->default('full_day');
            $table->string('purpose', 500);
            $table->integer('expected_guests')->nullable();
            $table->string('contact_name', 255);
            $table->string('contact_phone', 15);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');
            $table->uuid('payment_id')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();

            $table->foreign('devotee_id')->references('id')->on('temple_devotees');
            $table->foreign('hall_id')->references('id')->on('temple_halls');
            $table->index('booking_date');
            $table->index(['hall_id', 'booking_date']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_hall_bookings');
    }
};
