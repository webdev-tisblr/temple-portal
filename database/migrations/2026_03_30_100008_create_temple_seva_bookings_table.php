<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_seva_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('devotee_id');
            $table->unsignedBigInteger('seva_id');
            $table->date('booking_date');
            $table->time('slot_time')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled', 'refunded'])->default('pending');
            $table->uuid('payment_id')->nullable();
            $table->string('devotee_name_for_seva', 255)->nullable();
            $table->string('gotra', 255)->nullable();
            $table->text('sankalp')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->foreign('devotee_id')->references('id')->on('temple_devotees');
            $table->foreign('seva_id')->references('id')->on('temple_sevas');
            $table->foreign('payment_id')->references('id')->on('temple_payments');
            $table->index('devotee_id');
            $table->index('booking_date');
            $table->index('status');
            $table->index(['seva_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_seva_bookings');
    }
};
