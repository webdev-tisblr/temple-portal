<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->string('devotee_phone', 20)->nullable()->after('devotee_address');
            $table->string('devotee_email', 255)->nullable()->after('devotee_phone');
        });
    }

    public function down(): void
    {
        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->dropColumn(['devotee_phone', 'devotee_email']);
        });
    }
};
