<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App version that registered the token (e.g. '1.4.6'). Null = the
     * registration predates this column, i.e. a build ≤ v1.4.5 — treated
     * as NOT custom-tone-capable by FirebaseService. Old builds lack the
     * tone sound files and Android notification channels, and a push
     * aimed at an unknown channel_id is silently dropped on Android.
     */
    public function up(): void
    {
        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->string('app_version', 20)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->dropColumn('app_version');
        });
    }
};
