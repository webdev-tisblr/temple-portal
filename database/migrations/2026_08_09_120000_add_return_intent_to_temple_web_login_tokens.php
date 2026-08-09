<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carries a "where should the app come back to" hint across the
     * app -> browser -> app round trip (item 3.2).
     *
     * The Flutter app names the screen it launched the handoff from
     * (POST /api/v1/auth/web-session-token, `return_intent` +
     * `return_intent_params`). Both are validated against the
     * DeepLinkRouter intent vocabulary and stored SERVER-SIDE on the
     * token row for the same reason `redirect_to` is: the value must
     * never be readable/forgeable from the /auth/app-login URL.
     *
     * appLogin() copies them into the session, and the thank-you page
     * turns them back into a `patadiyahanumanji://<intent>?...` link.
     */
    public function up(): void
    {
        Schema::table('temple_web_login_tokens', function (Blueprint $table) {
            $table->string('return_intent', 48)->nullable()->after('redirect_to');
            $table->json('return_intent_params')->nullable()->after('return_intent');
        });
    }

    public function down(): void
    {
        Schema::table('temple_web_login_tokens', function (Blueprint $table) {
            $table->dropColumn(['return_intent', 'return_intent_params']);
        });
    }
};
