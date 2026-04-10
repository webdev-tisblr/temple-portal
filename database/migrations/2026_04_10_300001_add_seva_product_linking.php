<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Products: mark as seva-only (unlisted from store)
        Schema::table('temple_products', function (Blueprint $table) {
            $table->boolean('is_seva_only')->default(false)->after('is_featured');
        });

        // Sevas: link products/categories for devotee selection
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->json('linked_products')->nullable()->after('notification_config');
        });

        // Bookings: store devotee's product selection
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('selected_product_id')->nullable()->after('sankalp');
            $table->foreign('selected_product_id')->references('id')->on('temple_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropForeign(['selected_product_id']);
            $table->dropColumn('selected_product_id');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('linked_products');
        });

        Schema::table('temple_products', function (Blueprint $table) {
            $table->dropColumn('is_seva_only');
        });
    }
};
