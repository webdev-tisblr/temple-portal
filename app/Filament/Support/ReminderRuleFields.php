<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\NotificationTemplate;
use App\Models\SevaReminderRule;
use Filament\Forms;
use Filament\Forms\Get;

/**
 * The form schema for one seva reminder rule, rendered by the rules
 * repeater in the Edit Seva page's "Reminders" section.
 *
 * A rule = WHEN (offset) + WHO (recipient) + HOW (channel) + WHAT
 * (inline gu/hi/en message, or an approved WhatsApp template).
 */
final class ReminderRuleFields
{
    public static function schema(): array
    {
        return [
            Forms\Components\Select::make('offset_minutes')
                ->label('When (before the seva)')
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
                    SevaReminderRule::RECIPIENT_ASSIGNEE => 'Seva assignee',
                    SevaReminderRule::RECIPIENT_CUSTOM_PHONE => 'Custom phone number(s)',
                ])
                ->default(SevaReminderRule::RECIPIENT_DEVOTEE)
                ->live()
                ->required(),

            Forms\Components\Select::make('recipient_value')
                ->label('Role')
                ->options(fn () => \Spatie\Permission\Models\Role::query()
                    ->orderBy('name')->pluck('name', 'name')->all())
                ->visible(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_ADMIN_ROLE)
                ->required(fn (Get $get): bool => $get('recipient_type') === SevaReminderRule::RECIPIENT_ADMIN_ROLE),

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
                ->helperText('Only templates created for the "Seva — reminder before booking" trigger are listed — their {{1}}, {{2}}… variables are already mapped to booking data. To change the wording or variables, edit the template under Notification Templates (or create a new one on that trigger).')
                ->options(fn () => NotificationTemplate::query()
                    ->where('channel', NotificationTemplate::CHANNEL_WHATSAPP)
                    ->where('key', 'seva.booking.reminder')
                    ->orderBy('label')
                    ->pluck('label', 'id')->all())
                ->searchable()
                ->live()
                ->visible(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP)
                ->required(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP),

            Forms\Components\Placeholder::make('wa_template_preview')
                ->label('Message preview')
                ->content(function (Get $get): \Illuminate\Support\HtmlString {
                    $id = $get('notification_template_id');
                    if (! $id) {
                        return new \Illuminate\Support\HtmlString('<em>Select a template above to preview its message and variables.</em>');
                    }
                    $t = NotificationTemplate::find($id);
                    if (! $t) {
                        return new \Illuminate\Support\HtmlString('<em>Template not found.</em>');
                    }

                    // Approved body text from the synced Meta template cache.
                    $cache = \App\Models\WhatsAppTemplateCache::query()
                        ->where('name', $t->wa_template_name)
                        ->when($t->wa_template_language, fn ($q) => $q->where('language', $t->wa_template_language))
                        ->first();
                    $body = null;
                    foreach ((array) ($cache->components ?? []) as $component) {
                        if (strtolower((string) ($component['type'] ?? '')) === 'body') {
                            $body = (string) ($component['text'] ?? '');
                            break;
                        }
                    }

                    // Which data each {{n}} gets — tokens configured on the
                    // template row's wa_components blueprint.
                    preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', json_encode($t->wa_components ?? []), $m);
                    $tokens = array_values(array_unique($m[1] ?? []));

                    $html = '';
                    if ($body) {
                        $html .= '<div style="white-space:pre-wrap;border:1px solid rgba(120,120,120,.3);border-radius:8px;padding:10px;margin-bottom:6px;">' . e($body) . '</div>';
                    }
                    if ($tokens !== []) {
                        $vars = [];
                        foreach ($tokens as $i => $token) {
                            $vars[] = '{{' . ($i + 1) . '}} → <strong>' . e($token) . '</strong>';
                        }
                        $html .= '<div>Variables: ' . implode(' &nbsp; ', $vars) . '</div>';
                    } else {
                        $html .= '<div style="color:#d97706;">⚠ This template has no variables mapped yet — open it in Notification Templates and map each {{n}} to booking data, or the send may be rejected.</div>';
                    }

                    return new \Illuminate\Support\HtmlString($html);
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
                        ->helperText('Placeholders: {{devotee_name}} {{seva_name}} {{booking_date}} {{slot_time}} {{time_remaining_label}} {{trust_name}} {{admin_name}}'),
                ], id: 'rule_message'),
            ])->visible(fn (Get $get): bool => $get('channel') !== NotificationTemplate::CHANNEL_WHATSAPP),

            Forms\Components\Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];
    }
}
