<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('razorpay_order_id', 255)->unique();
            $table->string('razorpay_payment_id', 255)->nullable()->unique();
            $table->string('razorpay_signature', 500)->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('INR');
            $table->enum('status', ['created', 'authorized', 'captured', 'failed', 'refunded'])->default('created');
            $table->string('method', 50)->nullable();
            $table->string('description', 500)->nullable();
            $table->json('webhook_payload')->nullable();
            $table->string('refund_id', 255)->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('razorpay_order_id');
            $table->index('razorpay_payment_id');
            $table->index('status');
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_payments');
    }
};
