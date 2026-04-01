<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_receipts_80g', function (Blueprint $table) {
            $table->id();
            $table->uuid('donation_id')->unique();
            $table->string('receipt_number', 50)->unique();
            $table->string('financial_year', 7);
            $table->string('devotee_name', 255);
            $table->text('devotee_address')->nullable();
            $table->string('pan_number', 10);
            $table->decimal('amount', 10, 2);
            $table->string('amount_in_words', 500);
            $table->date('donation_date');
            $table->string('payment_mode', 50);
            $table->string('trust_name', 500)->default('Shree Pataliya Hanumanji Seva Trust');
            $table->text('trust_address')->nullable();
            $table->string('trust_pan', 10)->nullable();
            $table->string('trust_80g_registration_no', 100)->nullable();
            $table->string('trust_80g_validity_period', 100)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('whatsapp_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('donation_id')->references('id')->on('temple_donations');
            $table->index('financial_year');
            $table->index('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_receipts_80g');
    }
};
