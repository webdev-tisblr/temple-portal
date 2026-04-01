<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_donations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('devotee_id');
            $table->uuid('payment_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->enum('donation_type', ['general', 'seva', 'annadan', 'construction', 'festival', 'campaign', 'other']);
            $table->string('purpose', 500)->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->uuid('seva_booking_id')->nullable();
            $table->boolean('is_80g_eligible')->default(true);
            $table->boolean('pan_verified')->default(false);
            $table->text('pan_number_encrypted')->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->boolean('receipt_generated')->default(false);
            $table->boolean('anonymous')->default(false);
            $table->text('notes')->nullable();
            $table->string('financial_year', 7);
            $table->timestamps();

            $table->foreign('devotee_id')->references('id')->on('temple_devotees');
            $table->foreign('payment_id')->references('id')->on('temple_payments');
            $table->foreign('campaign_id')->references('id')->on('temple_donation_campaigns');
            $table->foreign('seva_booking_id')->references('id')->on('temple_seva_bookings');
            $table->index('devotee_id');
            $table->index('donation_type');
            $table->index('financial_year');
            $table->index('created_at');
            $table->index(['is_80g_eligible', 'pan_verified']);
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_donations');
    }
};
