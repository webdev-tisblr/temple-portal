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
    // Send to a specific AdminUser row — recipient_value stores the
    // user's id. Email channel reads admin_users.email, WhatsApp / SMS
    // read admin_users.phone. Lets the trust route a trigger to a
    // particular staff member (eg. the pujari for seva confirmations).
    public const RECIPIENT_ADMIN_USER = 'admin_user';

    // Send to EVERY active AdminUser holding a given Spatie role —
    // recipient_value stores the role name (eg "pujari", "trustee",
    // "store_admin"). NotificationService expands one delivery per
    // role-holder, injecting `admin` into the per-dispatch context so
    // templates can render `{{ admin.name }}` etc. Lets the trust
    // create a single template that broadcasts to a whole audience
    // without naming individual users.
    public const RECIPIENT_ADMIN_ROLE = 'admin_role';

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
        'recipients',
        'placeholder_map',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'wa_components' => 'array',
        'push_title' => 'array',
        'push_body' => 'array',
        'placeholder_map' => 'array',
        // Array of { strategy: string, value: string|null } entries.
        // Each entry independently resolves at dispatch; same row can
        // therefore fan out to devotee + admin_role + fixed_phone in
        // one go. See migration 2026_05_16_130000.
        'recipients' => 'array',
    ];

    /**
     * Normalise the recipients list into a non-empty array of
     * { strategy, value } entries for the dispatcher to iterate.
     *
     * Order of precedence:
     *   1. `recipients` JSON column (new shape, multi-recipient).
     *   2. Synthesised single-item array from the legacy
     *      `recipient_strategy` + `recipient_value` columns —
     *      keeps seeded rows and any pre-migration data working
     *      transparently.
     *
     * Returns an empty array only when neither path produced a usable
     * strategy. NotificationService treats that as "skipped: no
     * recipient" and logs accordingly.
     *
     * @return array<int, array{strategy: string, value: ?string}>
     */
    public function resolveRecipients(): array
    {
        $list = $this->recipients;
        if (is_array($list) && $list !== []) {
            $out = [];
            foreach ($list as $entry) {
                if (! is_array($entry)) continue;
                $strategy = is_string($entry['strategy'] ?? null) ? trim($entry['strategy']) : '';
                if ($strategy === '') continue;
                $value = $entry['value'] ?? null;
                $out[] = [
                    'strategy' => $strategy,
                    'value' => is_string($value) ? trim($value) : $value,
                ];
            }
            if ($out !== []) {
                return $out;
            }
        }

        if (is_string($this->recipient_strategy) && trim($this->recipient_strategy) !== '') {
            return [[
                'strategy' => $this->recipient_strategy,
                'value' => $this->recipient_value,
            ]];
        }

        return [];
    }

    /** Convenience scope used by the dispatcher. */
    public function scopeEnabledForKey($query, string $key)
    {
        return $query->where('key', $key)->where('is_enabled', true);
    }
}
