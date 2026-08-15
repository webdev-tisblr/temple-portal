<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\SevaReminderRule;
use App\Models\WhatsAppTemplateCache;
use Filament\Forms;
use Filament\Forms\Get;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;

/**
 * The form schema for ONE reminder rule — shared by the seva repeater on
 * Edit Seva and the hall repeater on Edit Hall.
 *
 * A rule = WHEN (offset) + WHO (recipient) + HOW (channel) + WHAT
 * (inline gu/hi/en message, or an approved WhatsApp template).
 *
 * Deliberately ONE definition with a few knobs rather than two copies:
 * the seva and hall rule forms are the same form, and a second copy would
 * drift the moment either side gained a field. The differences that
 * actually exist are the trigger key the WhatsApp picker filters on, the
 * placeholder hint, whether "assignee" is an option (halls have none) and
 * the wording of the offset label.
 */
final class ReminderRuleFields
{
    /**
     * @param  string  $subject  'seva' or 'hall' — only affects wording.
     * @param  string  $triggerKey  Trigger the WhatsApp template picker filters on.
     * @param  bool  $withAssignee  Offer the "assignee" recipient type (sevas only).
     * @param  string|null  $placeholders  Hint line under the inline message box.
     */
    public static function schema(
        string $subject = 'seva',
        string $triggerKey = 'seva.booking.reminder',
        bool $withAssignee = true,
        ?string $placeholders = null,
    ): array {
        $placeholders ??= '{{devotee_name}} {{devotee_phone}} {{devotee_email}} {{seva_name}} {{booking_date}} {{slot_time}} {{hours_remaining}} {{time_remaining_label}} {{trust_name}} {{admin_name}} {{assignee_name}} {{assignee_phone}}';

        return [
            Forms\Components\Select::make('offset_minutes')
                ->label("When (before the {$subject})")
                ->options([
                    30 => '30 minutes before',
                    60 => '1 hour before',
                    120 => '2 hours before',
                    180 => '3 hours before',
                    360 => '6 hours before',
                    720 => '12 hours before',
                    1440 => '1 day before',
                    2880 => '2 days before',
                    4320 => '3 days before',
                    10080 => '1 week before',
                ])
                ->required(),

            Forms\Components\Select::make('recipient_type')
                ->label('Who receives it')
                ->options([
                    SevaReminderRule::RECIPIENT_DEVOTEE => 'Devotee (the booker)',
                    SevaReminderRule::RECIPIENT_ADMIN_ROLE => 'Admin role (pujari / staff…)',
                    ...($withAssignee ? [SevaReminderRule::RECIPIENT_ASSIGNEE => 'Seva assignee'] : []),
                    SevaReminderRule::RECIPIENT_CUSTOM_PHONE => 'Custom phone number(s)',
                ])
                ->default(SevaReminderRule::RECIPIENT_DEVOTEE)
                ->live()
                // The Role select and the Phone input below are two fields
                // sharing ONE column, so the value survives a change of
                // recipient type: picking role "staff" then switching to
                // Custom phone left "staff" sitting in the phone box, and
                // switching back put a phone number into a Role select that
                // has no such option — it renders blank while the state still
                // holds the number, so saving without re-picking stored a
                // phone number as the role. Clear it on every change.
                //
                // Custom phone numbers can only be reached over WhatsApp, so
                // a stale channel from a previous choice has to go too.
                ->afterStateUpdated(function (Forms\Set $set, ?string $state): void {
                    $set('recipient_value', null);

                    if ($state === SevaReminderRule::RECIPIENT_CUSTOM_PHONE) {
                        $set('channel', NotificationTemplate::CHANNEL_WHATSAPP);
                    }
                })
                ->required(),

            Forms\Components\Select::make('recipient_value')
                ->label('Role')
                ->options(fn () => Role::query()
                    ->where('guard_name', 'admin')
                    ->orderBy('name')->pluck('name', 'name')->all())
                ->live()
                // A different role means a different set of people, so the
                // names chosen below cannot carry over.
                ->afterStateUpdated(fn (Forms\Set $set) => $set('recipient_user_ids', []))
                ->visible(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_ADMIN_ROLE)
                ->required(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_ADMIN_ROLE),

            // Narrow an admin-role rule to named people. Empty = everyone
            // holding the role, which is what the rule meant before this
            // existed, so rules configured earlier are unaffected.
            Forms\Components\Select::make('recipient_user_ids')
                ->label('Specific people (optional)')
                ->multiple()
                ->searchable()
                ->preload()
                ->options(function (Get $get): array {
                    $role = trim((string) $get('recipient_value'));

                    if ($role === '') {
                        return [];
                    }

                    return AdminUser::query()
                        ->role($role)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->helperText(function (Get $get): string {
                    $role = trim((string) $get('recipient_value'));

                    if ($role === '') {
                        return 'Pick a role first.';
                    }

                    $count = AdminUser::query()->role($role)->where('is_active', true)->count();

                    if ($count === 0) {
                        return "No active admin currently holds the \"{$role}\" role — this rule would reach nobody.";
                    }

                    return "Leave empty to send to all {$count} active \"{$role}\" user(s). Choose names to send to only those.";
                })
                ->visible(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_ADMIN_ROLE),

            Forms\Components\TextInput::make('recipient_value')
                ->label('Phone number(s)')
                ->placeholder('9198xxxxxx, 9197xxxxxx')
                ->helperText('Comma-separated, with country code, no + sign.')
                ->visible(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_CUSTOM_PHONE)
                ->required(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_CUSTOM_PHONE),

            Forms\Components\Select::make('channel')
                ->label('Channel')
                ->options(fn (Get $get): array => $get('recipient_type') === SevaReminderRule::RECIPIENT_CUSTOM_PHONE
                    ? [NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp']
                    : [
                        NotificationTemplate::CHANNEL_WHATSAPP => 'WhatsApp',
                        NotificationTemplate::CHANNEL_PUSH => 'Push notification',
                        NotificationTemplate::CHANNEL_EMAIL => 'Email',
                    ])
                ->default(NotificationTemplate::CHANNEL_WHATSAPP)
                ->live()
                ->required(),

            Forms\Components\Select::make('notification_template_id')
                ->label('WhatsApp template')
                ->helperText('Only templates created for this reminder trigger are listed — their {{1}}, {{2}}… variables are already mapped to booking data. To change the wording or variables, edit the template under Notification Templates (or create a new one on that trigger).')
                ->options(fn () => NotificationTemplate::query()
                    ->where('channel', NotificationTemplate::CHANNEL_WHATSAPP)
                    ->where('key', $triggerKey)
                    ->orderBy('label')
                    ->pluck('label', 'id')->all())
                ->searchable()
                ->live()
                ->visible(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP)
                ->required(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP),

            Forms\Components\Placeholder::make('wa_template_preview')
                ->label('Message preview')
                ->content(function (Get $get): HtmlString {
                    $id = $get('notification_template_id');
                    if (! $id) {
                        return new HtmlString('<em>Select a template above to preview its message and variables.</em>');
                    }
                    $t = NotificationTemplate::find($id);
                    if (! $t) {
                        return new HtmlString('<em>Template not found.</em>');
                    }

                    // Per-language variants (wa_variants), falling back to
                    // the legacy single-template columns for rows saved
                    // before the multi-language feature.
                    $variants = is_array($t->wa_variants) ? $t->wa_variants : [];
                    if ($variants === [] && $t->wa_template_name) {
                        $variants = ['gu' => [
                            'template_name' => $t->wa_template_name,
                            'language_code' => $t->wa_template_language,
                            'components' => $t->wa_components ?? [],
                        ]];
                    }

                    $labels = ['gu' => 'ગુજરાતી (Gujarati)', 'hi' => 'हिन्दी (Hindi)', 'en' => 'English'];
                    $html = '';
                    foreach ($labels as $locale => $label) {
                        $html .= '<div style="font-weight:600;margin:.6rem 0 .25rem;">'.$label.'</div>';
                        $variant = $variants[$locale] ?? null;
                        if (! is_array($variant) || empty($variant['template_name'])) {
                            $html .= '<div style="color:#d97706;font-size:.85rem;">Not configured — devotees preferring this language get the Gujarati version.</div>';

                            continue;
                        }
                        $html .= self::renderWaVariantPreview($variant);
                    }

                    return new HtmlString($html);
                })
                ->visible(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP
                    && filled($get('notification_template_id'))),

            Forms\Components\Group::make([
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")
                        ->label("Title / Subject {$label}")
                        ->maxLength(500),
                    Forms\Components\Textarea::make("body_{$locale}")
                        ->label("Message {$label}")
                        ->rows(3)
                        ->helperText('Placeholders: '.$placeholders),
                ], id: 'rule_message'),
            ])->visible(fn (Get $get): bool => $get('channel') !== NotificationTemplate::CHANNEL_WHATSAPP),

            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];
    }

    /**
     * Approved body + variable mapping for one wa_variants entry.
     * The body text comes from the synced Meta template cache row
     * (name + language); the mapping walks the variant's components
     * blueprint — each param is {'value_token': 'devotee_name'} (a
     * registry token) or {'value': 'literal'}, never a "{{token}}"
     * string, so we iterate parameters rather than regex the JSON.
     */
    private static function renderWaVariantPreview(array $variant): string
    {
        $cache = WhatsAppTemplateCache::query()
            ->where('name', $variant['template_name'])
            ->when($variant['language_code'] ?? null, fn ($q, $lang) => $q->where('language', $lang))
            ->first();

        $body = null;
        foreach ((array) ($cache->components ?? []) as $component) {
            if (strtolower((string) ($component['type'] ?? '')) === 'body') {
                $body = (string) ($component['text'] ?? '');
                break;
            }
        }

        $tokens = [];
        foreach ((array) ($variant['components'] ?? []) as $component) {
            foreach ((array) ($component['parameters'] ?? []) as $param) {
                if (isset($param['value_token']) && $param['value_token'] !== '') {
                    $tokens[] = (string) $param['value_token'];
                } elseif (array_key_exists('value', $param) && (string) $param['value'] !== '') {
                    $tokens[] = '"'.(string) $param['value'].'"';
                }
            }
        }

        $html = '';
        if ($body) {
            $html .= '<div style="white-space:pre-wrap;border:1px solid rgba(120,120,120,.3);border-radius:8px;padding:10px;margin-bottom:6px;">'.e($body).'</div>';
        }
        if ($tokens !== []) {
            $vars = [];
            foreach ($tokens as $i => $token) {
                $vars[] = '{{'.($i + 1).'}} → <strong>'.e($token).'</strong>';
            }
            $html .= '<div>Variables: '.implode(' &nbsp; ', $vars).'</div>';
        } else {
            $html .= '<div style="color:#d97706;">⚠ This template has no variables mapped yet — open it in Notification Templates and map each {{n}} to booking data, or the send may be rejected.</div>';
        }

        return $html;
    }
}
