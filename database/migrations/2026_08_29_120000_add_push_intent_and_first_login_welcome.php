<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two halves of "welcome a devotee into the app, and land them somewhere"
 * (2026-08-29).
 *
 * 1. `temple_notification_templates.push_intent` / `push_intent_params`.
 *    Broadcast pushes have carried an intent + params since they were built,
 *    and the Flutter DeepLinkRouter routes on exactly those two keys. TRIGGER
 *    pushes never did — they sent a `deep_link` string the app has no handler
 *    for — so tapping one only ever opened the app on its home screen. Same
 *    two fields, same vocabulary, so a screen reachable from a broadcast is
 *    reachable from a trigger for free.
 *
 * 2. `temple_devotees.welcomed_at`. The existing `devotee.registered` trigger
 *    fires at the instant the OTP is verified — which is BEFORE the app has
 *    associated its FCM token with the new devotee, so a push template on that
 *    trigger has no device to send to and is silently skipped. The welcome
 *    push therefore hangs off device-token registration instead, and this
 *    column is what stops it firing twice.
 *
 *    Existing devotees are backfilled as already-welcomed. Without that, the
 *    first token registration after this deploy would push a welcome message
 *    at every devotee who has ever installed the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->string('push_intent', 50)->nullable()->after('push_deep_link');
            $table->json('push_intent_params')->nullable()->after('push_intent');
        });

        Schema::table('temple_devotees', function (Blueprint $table) {
            $table->timestamp('welcomed_at')->nullable()->after('last_login_at');
        });

        DB::table('temple_devotees')->whereNull('welcomed_at')->update([
            'welcomed_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('temple_devotees', function (Blueprint $table) {
            $table->dropColumn('welcomed_at');
        });

        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropColumn(['push_intent', 'push_intent_params']);
        });
    }
};
