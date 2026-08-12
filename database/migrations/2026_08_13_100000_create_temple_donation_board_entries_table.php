<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live donor display board — the announcement log (2026-08-13).
 *
 * One row per donation the hall screen may show. Deliberately a TABLE and not
 * a Redis list: deploys rebuild caches, and a single `optimize:clear` during a
 * launch-week hotfix would empty the board with no way to recover the day.
 *
 * The row is a SNAPSHOT, not a pointer. `payload` holds the already-masked,
 * already-locale-resolved strings produced by App\Support\CampaignDonors::payload()
 * at announce time, so:
 *   • the screen never loads relations while polling,
 *   • what was shown stays auditable after the donation row is edited,
 *   • and the masking decision is made once, by the same helper the website
 *     uses, instead of being re-derived by a second code path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_donation_board_entries', function (Blueprint $table): void {
            // Auto-increment IS the board cursor. Clients poll `?since=<id>`,
            // which is why nothing here is ever hard-deleted — a gap would
            // make a client's cursor skip silently past a real donation.
            $table->id();

            // char(36): temple_devotees/temple_donations use UUIDs, so this
            // must be foreignUuid, never foreignId.
            //
            // UNIQUE is the idempotency guarantee. markCaptured() is designed
            // to be safely re-entrant (webhook + client verify can both land),
            // so a replayed capture must not announce the same gift twice.
            //
            // NULLABLE so `board:demo-announce` can put a rehearsal card on
            // screen without inventing a donation row. MySQL permits many
            // NULLs in a unique index, so demo entries never collide with each
            // other or with a real gift — and "donation_id IS NULL" is then a
            // reliable way to find and clear every synthetic row afterwards.
            $table->foreignUuid('donation_id')
                ->nullable()
                ->unique()
                ->constrained('temple_donations')
                ->cascadeOnDelete();

            $table->json('payload');

            // Wall-clock at announce time — NOT payment.paid_at and NOT
            // donation.created_at. CounterEntryService is the only caller that
            // passes $paidAt and it may be BACKDATED (cash taken on Saturday,
            // keyed in on Monday). A backdated slip must appear on screen when
            // it is entered, not sort into last week.
            $table->dateTime('announced_at');

            // Visibility lag (announced_at + board_delay_seconds).
            //
            // Load-bearing for cursor safety, not for moderation: rows become
            // VISIBLE in commit order, not id order, so two concurrent captures
            // can commit out of sequence. Without this lag a client that had
            // already advanced past the higher id would never see the lower one
            // — a donation silently missing from the screen while the donor
            // stands there watching. The lag guarantees any interleaved insert
            // is long since visible before a cursor reaches it.
            $table->dateTime('visible_from');

            // Denormalised from the donation so the "never announce Gupt Daan
            // live" policy is a WHERE clause rather than a JSON extraction.
            $table->boolean('anonymous')->default(false);

            // Retroactive takedown. Kept as a timestamp rather than a delete so
            // the cursor stays gapless and the action stays auditable.
            $table->dateTime('suppressed_at')->nullable();
            $table->unsignedBigInteger('suppressed_by')->nullable();

            $table->timestamps();

            $table->index('visible_from');
            $table->index('suppressed_at');
            $table->index(['anonymous', 'visible_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_donation_board_entries');
    }
};
