<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number', 50)->unique();
            $table->uuid('devotee_id');
            $table->uuid('payment_id')->nullable();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_charge', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->enum('status', ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'])->default('pending');
            $table->string('shipping_name', 255);
            $table->string('shipping_phone', 20);
            $table->text('shipping_address');
            $table->string('shipping_city', 100);
            $table->string('shipping_state', 100);
            $table->string('shipping_pincode', 10);
            $table->text('notes')->nullable();
            $table->string('invoice_path', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('devotee_id')->references('id')->on('temple_devotees');
            $table->foreign('payment_id')->references('id')->on('temple_payments');
            $table->index('devotee_id');
            $table->index('status');
            $table->index('order_number');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_orders');
    }
};
