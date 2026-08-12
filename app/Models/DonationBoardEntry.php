<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One announcement on the live donor display board.
 *
 * `payload` is a frozen, already-masked snapshot (see the migration). Nothing
 * reading this model should ever reach back through `donation` to build what
 * goes on screen — that is exactly how an anonymous donor ends up named.
 */
class DonationBoardEntry extends Model
{
    protected $table = 'temple_donation_board_entries';

    protected $fillable = [
        'donation_id',
        'payload',
        'announced_at',
        'visible_from',
        'anonymous',
        'suppressed_at',
        'suppressed_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'announced_at' => 'datetime',
        'visible_from' => 'datetime',
        'anonymous' => 'boolean',
        'suppressed_at' => 'datetime',
    ];

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }

    /**
     * Rows the screen may show right now: past their visibility lag and not
     * taken down. Every board read goes through this scope so a suppressed
     * entry cannot reappear through some other query.
     */
    public function scopeShowable(Builder $query): Builder
    {
        return $query
            ->whereNull('suppressed_at')
            ->where('visible_from', '<=', now());
    }

    /**
     * Entries eligible for a full-screen takeover.
     *
     * Gupt Daan is excluded here, not merely masked. On the website "રામ ભરોસે"
     * is safe because nobody knows WHEN the row appeared; announced live in the
     * hall it lands seconds after a specific person leaves the counter, so the
     * room can link the gift to the giver. Anonymous gifts still reach the
     * screen through the honour roll (shuffled, undated) — they are honoured
     * without being traceable.
     *
     * Controlled by `board_announce_anonymous`, which ships at 0.
     */
    public function scopeAnnounceable(Builder $query, bool $includeAnonymous = false): Builder
    {
        return $query->showable()->when(
            ! $includeAnonymous,
            fn (Builder $q): Builder => $q->where('anonymous', false),
        );
    }

    public function isSuppressed(): bool
    {
        return $this->suppressed_at !== null;
    }
}
