<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (trigger key, channel) — the source of truth for every
 * notification (email / whatsapp / push) the platform fires. Edited
 * via Filament; resolved at dispatch by NotificationService.
 *
 * @property string $key
 * @property string $channel  email | whatsapp | push
 * @property bool   $is_enabled
 */
class NotificationTemplate extends Model
{
    protected $table = 'temple_notification_templates';

    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';
    public const CHANNEL_PUSH = 'push';
    public const CHANNEL_SMS = 'sms';

    public const RECIPIENT_DEVOTEE = 'devotee';
    public const RECIPIENT_TRUST_ADMIN = 'trust_admin';
    public const RECIPIENT_FIXED_EMAIL = 'fixed_email';
    public const RECIPIENT_FIXED_PHONE = 'fixed_phone';
    public const RECIPIENT_CONTEXT_PATH = 'context_path';

    protected $fillable = [
        'key',
        'label',
        'description',
        'channel',
        'is_enabled',
        'subject',
        'body',
        'from_name',
        'from_address',
        'wa_template_name',
        'wa_template_language',
        'wa_components',
        'sms_template_id',
        'push_title',
        'push_body',
        'push_deep_link',
        'recipient_strategy',
        'recipient_value',
        'placeholder_map',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'wa_components' => 'array',
        'push_title' => 'array',
        'push_body' => 'array',
        'placeholder_map' => 'array',
    ];

    /** Convenience scope used by the dispatcher. */
    public function scopeEnabledForKey($query, string $key)
    {
        return $query->where('key', $key)->where('is_enabled', true);
    }
}
