<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One turn in a contact conversation. @see ContactSubmission
 *
 * The opening message is NOT a row here — it lives on the submission itself.
 * Everything after it is.
 */
class ContactMessage extends Model
{
    public const AUTHOR_DEVOTEE = 'devotee';

    public const AUTHOR_ADMIN = 'admin';

    protected $table = 'temple_contact_messages';

    protected $fillable = [
        'contact_submission_id',
        'author_type',
        'admin_user_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ContactSubmission::class, 'contact_submission_id');
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function isFromAdmin(): bool
    {
        return $this->author_type === self::AUTHOR_ADMIN;
    }

    /** Admin replies the devotee has not opened yet. */
    public function scopeUnreadByDevotee(Builder $query): Builder
    {
        return $query->where('author_type', self::AUTHOR_ADMIN)->whereNull('read_at');
    }

    /** Devotee follow-ups the trust has not opened yet. */
    public function scopeUnreadByAdmin(Builder $query): Builder
    {
        return $query->where('author_type', self::AUTHOR_DEVOTEE)->whereNull('read_at');
    }
}
