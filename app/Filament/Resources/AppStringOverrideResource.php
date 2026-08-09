<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AppStringOverrideResource\Pages;
use App\Models\AppStringOverride;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Emergency wording hotfixes for the mobile app — NOT a wording CMS.
 * Keep this list empty in the steady state: add a row only to fix a
 * live mistake between store builds, bake the fix into the app's
 * bundled l10n files in the next release, then remove the row.
 *
 * REFERENCE SNAPSHOT — READ THIS BEFORE TOUCHING bundled()/keyOptions().
 * The key picker and the "what the app shows today" panel are built from
 * `resources/app-l10n/{gu,hi,en}.json`, a generated copy of the app's
 * `assets/l10n/*.json`. When that copy goes stale the screen misbehaves
 * in two silent ways: strings shipped since the copy was taken cannot be
 * selected at all, and strings whose wording changed are shown with the
 * OLD text — which reads as "the panel doesn't reflect my updates".
 * Regenerate it (do this on every store build):
 *
 *     php artisan app:sync-l10n-snapshot
 *     php artisan app:sync-l10n-snapshot --check   # exit 1 if stale
 *
 * See App\Console\Commands\SyncAppL10nSnapshot.
 */
class AppStringOverrideResource extends Resource
{
    protected static ?string $model = AppStringOverride::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'One-Time Setup';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'App Text Fixes';

    protected static ?string $modelLabel = 'app text fix';

    protected static ?string $pluralModelLabel = 'App Text Fixes';

    /** The three locales the app ships, in admin display order. */
    private const LOCALES = ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'];

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description('Patches ONE app wording without a store release. Pick the string from the list — it is a snapshot of the strings in the current app build. Phones pick the fix up on next app open (builds v1.4.6+). Remove the row once the correction ships inside a store build.')
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label('App string')
                        ->options(fn (): array => self::keyOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        // Scoped to the locale being edited: (key, locale)
                        // is UNIQUE in the DB, so without this a second row
                        // for the same pair died on SQLSTATE 23000 instead
                        // of telling the admin to edit the existing fix.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Forms\Get $get): Unique => $rule->where('locale', $get('locale')),
                        )
                        ->validationMessages([
                            'unique' => 'This string already has a fix in that language — edit or remove the existing row instead of adding a second one.',
                        ])
                        ->helperText('Search by the ENGLISH wording you see in the app.'),
                    Forms\Components\Select::make('locale')
                        ->label('Language to fix')
                        ->options(self::LOCALES)
                        ->required()
                        ->live(),
                    Forms\Components\Placeholder::make('current_text')
                        ->label('What the app shows today')
                        ->columnSpanFull()
                        ->visible(fn (Forms\Get $get): bool => filled($get('key')))
                        ->content(fn (Forms\Get $get, ?AppStringOverride $record): HtmlString => self::effectiveTextPanel($get, $record)),
                    Forms\Components\Textarea::make('value')
                        ->label('Corrected text')
                        ->rows(2)
                        ->required()
                        // Feeds the panel above, so the admin can see the
                        // before/after before committing.
                        ->live(onBlur: true),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->helperText('Off = the app falls back to its built-in text, without removing the row.')
                        ->default(true)
                        ->live(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('key')->searchable()->sortable(),
                Tables\Columns\BadgeColumn::make('locale')
                    ->colors(['success' => 'gu', 'warning' => 'hi', 'primary' => 'en']),
                Tables\Columns\TextColumn::make('bundled')
                    ->label('App build text')
                    ->state(fn (AppStringOverride $record): string => self::bundled($record->locale)[$record->key] ?? '— not in this app build —')
                    ->limit(50)
                    ->wrap()
                    ->color(fn (AppStringOverride $record): ?string => isset(self::bundled($record->locale)[$record->key]) ? null : 'danger')
                    ->tooltip(fn (AppStringOverride $record): ?string => isset(self::bundled($record->locale)[$record->key])
                        ? null
                        : 'The app ignores keys it does not bundle — this fix does nothing. Check the key, or refresh the snapshot with: php artisan app:sync-l10n-snapshot'),
                Tables\Columns\TextColumn::make('value')->label('Showing instead')->limit(50)->wrap(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('On'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('locale')
                    ->label('Language')
                    ->options(self::LOCALES),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->emptyStateHeading('No text fixes active')
            ->emptyStateDescription('This is the normal state — add a row only to hot-patch a wording mistake between app releases.')
            ->actions([
                Tables\Actions\EditAction::make()->button()->outlined(),
                // Labelled + rendered as a real button rather than a bare
                // icon: removing a fix is the routine end of its life once
                // the wording ships in a store build, so it has to be
                // obvious rather than discoverable-by-hover.
                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->button()
                    ->modalHeading('Remove this text fix?')
                    ->modalDescription('The app goes back to the wording built into the installed build. Phones pick that up on their next open.')
                    ->modalSubmitActionLabel('Remove fix'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Remove selected'),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * Per-locale "built-in → fix → what the app shows" panel.
     *
     * The old version rendered only the bundled text, identically before
     * and after saving, so an admin editing a row got no confirmation
     * that their fix was in force — the single loudest reason this screen
     * felt like it wasn't saving.
     */
    private static function effectiveTextPanel(Forms\Get $get, ?AppStringOverride $record): HtmlString
    {
        $key = (string) $get('key');
        $draftLocale = (string) $get('locale');
        $draftValue = (string) $get('value');
        $draftActive = (bool) $get('is_active');

        // Fixes already saved for this key, in any language. Excludes the
        // row being edited: its unsaved form state is authoritative here.
        $saved = AppStringOverride::query()
            ->where('key', $key)
            ->when(
                (bool) $record?->exists,
                fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()),
            )
            ->get()
            ->keyBy('locale');

        $rows = [];

        if (! array_key_exists($key, self::bundled('en'))) {
            $rows[] = '<span style="color:#b91c1c"><strong>⚠ This key is not in the app snapshot.</strong> '
                .'The app only applies fixes to strings it bundles, so this row may do nothing. '
                .'If the app has shipped this string since the snapshot was taken, run '
                .'<code>php artisan app:sync-l10n-snapshot</code>.</span>';
        }

        foreach (self::LOCALES as $code => $label) {
            $bundled = self::bundled($code)[$key] ?? null;

            if ($code === $draftLocale) {
                $override = filled($draftValue) ? $draftValue : null;
                $active = $draftActive;
                $note = $record?->exists ? 'this fix' : 'this new fix';
            } else {
                $row = $saved->get($code);
                $override = $row?->value;
                $active = (bool) $row?->is_active;
                $note = 'another fix';
            }

            $line = '<strong>'.$label.':</strong> '.e($bundled ?? '— not in this app build —');

            if (filled($override) && $active) {
                $line .= ' <span style="color:#047857">→ showing “'.e($override).'” ('.$note.')</span>';
            } elseif (filled($override)) {
                $line .= ' <span style="color:#a16207">('.$note.' is switched off: “'.e($override).'”)</span>';
            }

            $rows[] = $line;
        }

        return new HtmlString(implode('<br>', $rows));
    }

    /**
     * Bundled app strings for one locale, from the generated snapshot in
     * resources/app-l10n/*.json. See the class doc-block: refresh it with
     * `php artisan app:sync-l10n-snapshot`, never by hand.
     *
     * @return array<string,string>
     */
    private static function bundled(string $locale): array
    {
        static $cache = [];

        if (! isset($cache[$locale])) {
            $path = resource_path("app-l10n/{$locale}.json");
            $cache[$locale] = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?: [])
                : [];
        }

        return $cache[$locale];
    }

    /**
     * "key — English text" options so admins search by visible wording.
     *
     * Keys already stored in the table are appended even when the
     * snapshot doesn't know them. Without that, editing such a row opens
     * with an EMPTY required Select — the saved key is not an option, so
     * it cannot re-render — and saving then fails validation or silently
     * rewrites the row to a different key.
     *
     * @return array<string,string>
     */
    private static function keyOptions(): array
    {
        $options = [];
        foreach (self::bundled('en') as $key => $text) {
            $options[$key] = $key.' — '.Str::limit((string) $text, 60);
        }

        foreach (AppStringOverride::query()->distinct()->pluck('key') as $key) {
            $options[$key] ??= $key.' — (not in the current app snapshot)';
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAppStringOverrides::route('/'),
            'create' => Pages\CreateAppStringOverride::route('/create'),
            'edit' => Pages\EditAppStringOverride::route('/{record}/edit'),
        ];
    }
}
