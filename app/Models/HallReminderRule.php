<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "remind X before this hall booking" rule, configured per hall.
 *
 * Twin of SevaReminderRule, minus the two things that model only carries for
 * history: there is no global (hall_id NULL) tier, and no `assignee` recipient
 * — halls have no assigned karyakar.
 */
class HallReminderRule extends Model
{
    protected $table = 'temple_hall_reminder_rules';

    public const RECIPIENT_DEVOTEE = 'devotee';

    public const RECIPIENT_ADMIN_ROLE = 'admin_role';

    public const RECIPIENT_CUSTOM_PHONE = 'custom_phone';

    protected $fillable = [
        'hall_id',
        'offset_minutes',
        'recipient_type',
        'recipient_value',
        'recipient_user_ids',
        'channel',
        'notification_template_id',
        'title_gu', 'title_hi', 'title_en',
        'body_gu', 'body_hi', 'body_en',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'recipient_user_ids' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class, 'hall_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** Inline copy for push/email rules, falling back to Gujarati. */
    public function titleFor(string $locale): ?string
    {
        return $this->{"title_{$locale}"} ?: $this->title_gu;
    }

    public function bodyFor(string $locale): ?string
    {
        return $this->{"body_{$locale}"} ?: $this->body_gu;
    }
}
