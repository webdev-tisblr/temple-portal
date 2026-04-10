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
            $table->unsignedBigInteger('donation_type_id')->nullable()->after('donation_type');
            $table->json('extra_data')->nullable()->after('notes');
            $table->string('greeting_card_path', 500)->nullable()->after('extra_data');

            $table->foreign('donation_type_id')->references('id')->on('temple_donation_types')->nullOnDelete();
            $table->index('donation_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropForeign(['donation_type_id']);
            $table->dropIndex(['donation_type_id']);
            $table->dropColumn(['donation_type_id', 'extra_data', 'greeting_card_path']);
        });
    }
};
