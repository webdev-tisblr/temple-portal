<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\NotificationTemplate;
use App\Models\SevaReminderRule;
use Filament\Forms;
use Filament\Forms\Get;

/**
 * The shared form schema for one seva reminder rule — used by both the
 * central SevaReminderRuleResource and the per-seva rules repeater on
 * the Edit Seva page, so the two UIs can never drift apart.
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
                ->helperText('WhatsApp only sends Meta-approved templates — pick one. Manage them under Notification Templates.')
                ->options(fn () => NotificationTemplate::query()
                    ->where('channel', NotificationTemplate::CHANNEL_WHATSAPP)
                    ->orderBy('label')
                    ->pluck('label', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP)
                ->required(fn (Get $get): bool => $get('channel') === NotificationTemplate::CHANNEL_WHATSAPP),

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
