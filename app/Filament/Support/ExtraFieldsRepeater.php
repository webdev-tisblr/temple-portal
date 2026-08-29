<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\ProfilePrefill;
use Filament\Forms;

/**
 * The admin form builder for dynamic extra fields.
 *
 * Extracted from DonationTypeResource 2026-08-13 when sevas and campaigns
 * gained the same feature — one definition so the three screens cannot drift
 * in what a field is or how it is labelled, matching how ReminderRuleFields
 * and CampaignDonors are shared.
 *
 * Values the devotee enters land in `extra_data` on the donation or seva
 * booking, and any field defined here becomes placeable on that model's
 * greeting card.
 */
class ExtraFieldsRepeater
{
    public static function make(string $helper = ''): Forms\Components\Repeater
    {
        return Forms\Components\Repeater::make('extra_fields')
            ->label('Extra fields')
            ->helperText($helper !== '' ? $helper : 'Questions asked at checkout. Each field can also be placed on the greeting card below.')
            ->schema([
                Forms\Components\TextInput::make('key')
                    ->required()
                    ->placeholder('e.g. birthday_person_name')
                    // The key is the join between the answer, the card overlay
                    // and the stored extra_data, so it must stay machine-safe.
                    ->rules(['alpha_dash'])
                    ->helperText('Internal name. Do not change it after donors have used it.'),
                Forms\Components\TextInput::make('label_gu')
                    ->required()
                    ->placeholder('ગુજરાતી લેબલ'),
                Forms\Components\TextInput::make('label_hi')
                    ->placeholder('हिन्दी लेबल')
                    ->helperText('Optional — falls back to Gujarati.'),
                Forms\Components\TextInput::make('label_en')
                    ->required()
                    ->placeholder('English Label'),
                Forms\Components\Select::make('type')
                    ->options([
                        'text' => 'Text',
                        'number' => 'Number',
                        'date' => 'Date',
                        'image' => 'Photo upload',
                        'textarea' => 'Long text',
                    ])
                    ->default('text')
                    ->required(),
                Forms\Components\Toggle::make('required')
                    ->default(false)
                    ->helperText('A photo left optional falls back to the trust logo on the card.'),
                // Stop asking devotees for what the trust already knows
                // (2026-08-29). The filled value stays EDITABLE — someone
                // booking in a relative's name types over it.
                Forms\Components\Select::make('prefill_from')
                    ->label('Fill from profile')
                    ->options(ProfilePrefill::sources())
                    ->placeholder('Ask every time')
                    ->visible(fn (Forms\Get $get): bool => ($get('type') ?? 'text') !== 'image')
                    ->helperText('Pre-fills this field from the logged-in devotee\'s profile. Guests, and devotees who have not saved that detail, still get a blank box.'),
            ])
            ->columns(3)
            ->defaultItems(0)
            ->addActionLabel('Add field')
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['label_en'] ?? $state['label_gu'] ?? $state['key'] ?? null)
            ->columnSpanFull();
    }

    /**
     * Extra fields as greeting-card editor variables, appended to a model's
     * built-in ones. Image fields are marked so the editor renders an image
     * box rather than a text box.
     */
    public static function asCardVariables(?array $extraFields): array
    {
        return collect($extraFields ?? [])
            ->filter(fn ($f) => is_array($f) && filled($f['key'] ?? null))
            ->map(fn (array $f): array => [
                'key' => $f['key'],
                'label' => $f['label_en'] ?? $f['label_gu'] ?? $f['key'],
                'type' => ($f['type'] ?? 'text') === 'image' ? 'image' : 'text',
                'auto' => false,
            ])
            ->values()
            ->all();
    }
}
