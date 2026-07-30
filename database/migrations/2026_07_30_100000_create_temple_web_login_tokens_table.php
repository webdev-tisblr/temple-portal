<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-use app→web login handoff tokens. The Flutter app requests
     * one via POST /api/v1/auth/web-session-token and opens the returned
     * /auth/app-login URL in the system browser so the devotee lands on
     * the website (donate page) already logged in. Required because iOS
     * donations must happen on the website (App Store 3.2.2(iv)) and a
     * Sanctum bearer token can't carry over into a browser session.
     */
    public function up(): void
    {
        Schema::create('temple_web_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('devotee_id')->constrained('temple_devotees')->cascadeOnDelete();
            // sha256 hex of the plain token — the plain value only ever
            // exists in the URL handed to the app.
            $table->string('token_hash', 64)->unique();
            $table->string('redirect_to', 255)->default('/donate');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_web_login_tokens');
    }
};
