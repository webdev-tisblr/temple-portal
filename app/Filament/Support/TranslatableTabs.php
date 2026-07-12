<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Tabs;

/**
 * Standard admin UI for gu/hi/en content entry: one Tabs component with a
 * tab per language instead of three stacked fields. Usage:
 *
 *   TranslatableTabs::make(fn (string $locale, string $label) => [
 *       Forms\Components\TextInput::make("name_{$locale}")
 *           ->label("Name {$label}")
 *           ->required($locale === 'gu'),
 *   ]),
 *
 * The closure runs once per locale and returns that tab's fields.
 * $label is a parenthesised human suffix, e.g. "(Gujarati)".
 */
final class TranslatableTabs
{
    /** Locale => [tab title, field-label suffix] */
    private const LOCALES = [
        'gu' => ['ગુજરાતી', '(Gujarati)'],
        'hi' => ['हिन्दी', '(Hindi)'],
        'en' => ['English', '(English)'],
    ];

    /**
     * @param  \Closure(string $locale, string $label): array  $fields
     */
    public static function make(\Closure $fields, string $id = 'translations'): Tabs
    {
        $tabs = [];
        foreach (self::LOCALES as $locale => [$title, $label]) {
            $tabs[] = Tabs\Tab::make($title)->schema($fields($locale, $label));
        }

        return Tabs::make($id)->tabs($tabs)->columnSpanFull();
    }
}
