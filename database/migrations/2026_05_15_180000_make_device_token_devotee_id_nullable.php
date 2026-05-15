<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make temple_device_tokens.devotee_id nullable so anonymous installs
     * (app open, push permission granted, but no OTP login yet) can still
     * have their FCM token tracked. Admin broadcasts to the 'all' segment
     * then reach anonymous + logged-in tokens.
     *
     * Once the same device logs in later, the row's devotee_id is upgraded
     * to the devotee's UUID via updateOrCreate.
     */
    public function up(): void
    {
        Schema::table('temple_device_tokens', function (Blueprint $table) {
            // The original column is foreignUuid (char 36) with constrained
            // FK. We need to drop the FK before changing nullability, then
            // re-add the FK with nullOnDelete.
            $table->dropForeign(['devotee_id']);
        });

        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->char('devotee_id', 36)->nullable()->change();
        });

        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->foreign('devotee_id')
                ->references('id')
                ->on('temple_devotees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Best-effort reversal — null rows would block the not-null re-add,
        // so wipe them first.
        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->dropForeign(['devotee_id']);
        });

        \DB::table('temple_device_tokens')->whereNull('devotee_id')->delete();

        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->char('devotee_id', 36)->nullable(false)->change();
        });

        Schema::table('temple_device_tokens', function (Blueprint $table) {
            $table->foreign('devotee_id')
                ->references('id')
                ->on('temple_devotees')
                ->cascadeOnDelete();
        });
    }
};
