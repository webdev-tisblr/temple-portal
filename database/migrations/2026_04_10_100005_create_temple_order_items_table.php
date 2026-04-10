<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name', 255);
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('temple_orders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('temple_products');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_order_items');
    }
};
