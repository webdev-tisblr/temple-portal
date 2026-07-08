<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->string('selected_variant_label', 255)->nullable()->after('selected_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropColumn('selected_variant_label');
        });
    }
};
