<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_notifications', function (Blueprint $table) {
            $table->string('intent', 64)->nullable()->after('image_url');
            $table->json('intent_params')->nullable()->after('intent');
            $table->index('intent');
        });
    }

    public function down(): void
    {
        Schema::table('temple_notifications', function (Blueprint $table) {
            $table->dropIndex(['intent']);
            $table->dropColumn(['intent', 'intent_params']);
        });
    }
};
