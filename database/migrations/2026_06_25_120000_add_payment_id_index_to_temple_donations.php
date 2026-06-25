<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            // payment_id carries an FK but no standalone index. The hourly
            // campaign recompute and donation→payment joins scan without it.
            $table->index('payment_id', 'temple_donations_payment_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropIndex('temple_donations_payment_id_index');
        });
    }
};
