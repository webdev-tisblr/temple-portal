<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_devotees', function (Blueprint $table) {
            // Single-active-login: bumped on every fresh login (app or
            // web). Web sessions store the epoch they were created with;
            // a mismatch means the devotee logged in elsewhere and the
            // old session is force-logged-out by EnsureSingleDevoteeSession.
            // App tokens don't need it — they are hard-deleted on login.
            $table->unsignedInteger('auth_epoch')->default(0)->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('temple_devotees', function (Blueprint $table) {
            $table->dropColumn('auth_epoch');
        });
    }
};
