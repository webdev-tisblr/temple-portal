<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_products', function (Blueprint $table) {
            $table->boolean('has_variants')->default(false)->after('is_featured');
            $table->json('variants')->nullable()->after('has_variants');
        });

        // Add variant_label to order items so we know which variant was purchased
        Schema::table('temple_order_items', function (Blueprint $table) {
            $table->string('variant_label', 255)->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('temple_products', function (Blueprint $table) {
            $table->dropColumn(['has_variants', 'variants']);
        });

        Schema::table('temple_order_items', function (Blueprint $table) {
            $table->dropColumn('variant_label');
        });
    }
};
