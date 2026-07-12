<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seva reminder rule: WHEN (offset before the seva moment) + WHO
 * (recipient) + HOW (channel) + WHAT (inline gu/hi/en message or a
 * WhatsApp template reference).
 *
 * seva_id NULL → a global default rule applied to every seva whose
 * reminder_mode is 'global'; otherwise a custom rule for that seva.
 */
class SevaReminderRule extends Model
{
    protected $table = 'temple_seva_reminder_rules';

    public const RECIPIENT_DEVOTEE = 'devotee';
    public const RECIPIENT_ADMIN_ROLE = 'admin_role';
    public const RECIPIENT_ASSIGNEE = 'assignee';
    public const RECIPIENT_CUSTOM_PHONE = 'custom_phone';

    protected $fillable = [
        'seva_id',
        'offset_minutes',
        'recipient_type',
        'recipient_value',
        'channel',
        'notification_template_id',
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'offset_minutes' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function seva(): BelongsTo
    {
        return $this->belongsTo(Seva::class, 'seva_id');
    }

    /** The approved WhatsApp template this rule sends (channel=whatsapp). */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeGlobal(Builder $q): Builder
    {
        return $q->whereNull('seva_id');
    }

    /** Locale-resolved inline title/body with gu fallback. */
    public function titleFor(string $locale): ?string
    {
        return $this->{"title_{$locale}"} ?: $this->title_gu;
    }

    public function bodyFor(string $locale): ?string
    {
        return $this->{"body_{$locale}"} ?: $this->body_gu;
    }
}
