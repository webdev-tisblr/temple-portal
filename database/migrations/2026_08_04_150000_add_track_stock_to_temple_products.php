<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * track_stock = false → the product (and all its variants) is always
 * purchasable: no counts shown, nothing decremented on capture, no
 * restock on cancel. Defaults TRUE so existing products keep the
 * current stock-managed behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_products', function (Blueprint $table) {
            $table->boolean('track_stock')->default(true)->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('temple_products', function (Blueprint $table) {
            $table->dropColumn('track_stock');
        });
    }
};
