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

/**
 * Emergency wording hotfixes for the mobile app — NOT a wording CMS.
 * Keep this list empty in the steady state: add a row only to fix a
 * live mistake between store builds, bake the fix into the app's
 * bundled l10n files in the next release, then delete the row.
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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->description('Patches ONE app wording without a store release. The key must exactly match the app\'s l10n key (ask the developer or check assets/l10n/en.json — e.g. home.qa_store, login.phone_label). Phones pick the fix up on next app open (builds v1.4.6+). Delete the row once the correction ships inside a store build.')
                ->schema([
                    Forms\Components\Select::make('key')
                        ->label('App string')
                        ->options(fn () => self::keyOptions())
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText('Search by the ENGLISH wording you see in the app — the list holds every app string (a snapshot from the current app release).'),
                    Forms\Components\Select::make('locale')
                        ->label('Language to fix')
                        ->options(['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'])
                        ->required()
                        ->live(),
                    Forms\Components\Placeholder::make('current_text')
                        ->label('Current text in the app')
                        ->columnSpanFull()
                        ->visible(fn (Forms\Get $get) => filled($get('key')))
                        ->content(function (Forms\Get $get) {
                            $key = $get('key');
                            $rows = [];
                            foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $code => $label) {
                                $text = self::bundled($code)[$key] ?? '—';
                                $rows[] = '<strong>'.$label.':</strong> '.e($text);
                            }

                            return new \Illuminate\Support\HtmlString(implode('<br>', $rows));
                        }),
                    Forms\Components\Textarea::make('value')
                        ->label('Corrected text')
                        ->rows(2)
                        ->required(),
                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
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
                Tables\Columns\TextColumn::make('value')->limit(50)->wrap(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('On'),
                Tables\Columns\TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->emptyStateHeading('No text fixes active')
            ->emptyStateDescription('This is the normal state — add a row only to hot-patch a wording mistake between app releases.')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * Bundled app strings for one locale, from the snapshot copied out
     * of the app repo (resources/app-l10n/*.json). Refresh the snapshot
     * whenever a store build changes the app's l10n files.
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

    /** "key — English text" options so admins search by visible wording. */
    private static function keyOptions(): array
    {
        $options = [];
        foreach (self::bundled('en') as $key => $text) {
            $options[$key] = $key.' — '.\Illuminate\Support\Str::limit((string) $text, 60);
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
