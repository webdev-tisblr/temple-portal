<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the live donor display board (2026-08-16).
 *
 * The board was built for the 15 August launch — a screen in the hall
 * announcing each donation as it was captured. The event is over and the
 * trust has no further use for it, so the whole feature goes: service,
 * controller, model, kiosk view, routes, rate limiter, the "Hide from board"
 * row action on donations, and the settings section.
 *
 * This drops what the code left behind. The entries were only ever SNAPSHOTS
 * of donations that remain in temple_donations in full — nothing about the
 * donation record depends on them — and the nightly 02:00 backup holds a copy
 * of both taken before this runs.
 *
 * The create migration is deliberately left in place: migration history is a
 * log of what happened, and rewriting it would leave any database that has
 * already run it in a state the log no longer describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('temple_donation_board_entries');

        DB::table('temple_system_settings')->where('key', 'like', 'board_%')->delete();
    }

    /**
     * Recreates the table as it was, empty. The rows are not recoverable from
     * here — restore them from the nightly backup if they are ever wanted.
     */
    public function down(): void
    {
        if (Schema::hasTable('temple_donation_board_entries')) {
            return;
        }

        // Mirrors the 2026_08_13_100000 create migration column for column.
        Schema::create('temple_donation_board_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('donation_id')
                ->nullable()
                ->unique()
                ->constrained('temple_donations')
                ->cascadeOnDelete();
            $table->json('payload');
            $table->dateTime('announced_at');
            $table->dateTime('visible_from');
            $table->boolean('anonymous')->default(false);
            $table->dateTime('suppressed_at')->nullable();
            $table->unsignedBigInteger('suppressed_by')->nullable();
            $table->timestamps();

            $table->index('visible_from');
            $table->index('suppressed_at');
            $table->index(['anonymous', 'visible_from']);
        });
    }
};
