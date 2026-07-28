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
            // receipt_number is deterministic (SEVA-YYYYMMDD-<uuid prefix>)
            // and permanent once set; receipt_path is the cached, regenerable
            // PDF on r2_private (swept by invoices:clean-generated, rebuilt
            // on download by SevaReceiptService).
            $table->string('receipt_number', 40)->nullable()->unique()->after('notes');
            $table->string('receipt_path')->nullable()->after('receipt_number');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['receipt_number', 'receipt_path']);
        });
    }
};
