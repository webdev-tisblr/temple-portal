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
            $table->foreignId('sub_cause_id')->nullable()->after('campaign_id')
                ->constrained('temple_campaign_sub_causes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_cause_id');
        });
    }
};
