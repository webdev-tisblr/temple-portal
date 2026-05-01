<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_product_categories', function (Blueprint $table) {
            $table->boolean('is_seva_only')
                ->default(false)
                ->after('is_active')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('temple_product_categories', function (Blueprint $table) {
            $table->dropIndex(['is_seva_only']);
            $table->dropColumn('is_seva_only');
        });
    }
};
