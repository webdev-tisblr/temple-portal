<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use app→web login handoff token (see the migration doc-comment).
 * Lifetime is ~2 minutes; rows are pruned daily like temple_otp_codes.
 */
class WebLoginToken extends Model
{
    use Prunable;

    protected $table = 'temple_web_login_tokens';

    public $timestamps = false;

    protected $fillable = [
        'devotee_id',
        'token_hash',
        'redirect_to',
        'expires_at',
        'used_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }
}
