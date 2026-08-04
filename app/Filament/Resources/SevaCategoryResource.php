<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SevaCategoryResource\Pages;
use App\Models\Seva;
use App\Models\SevaCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SevaCategoryResource extends Resource
{
    protected static ?string $model = SevaCategory::class;

    // No sidebar entry — reached via the "Categories" button on the
    // Sevas list page (mirrors Gallery Categories, 2026-08-04).
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Seva Management';

    protected static ?int $navigationSort = 51;

    protected static ?string $navigationLabel = 'Seva Categories';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Category')->schema([
                \App\Filament\Support\TranslatableTabs::make(function (string $locale, string $label) {
                    $name = Forms\Components\TextInput::make("name_{$locale}")
                        ->label("Name {$label}")
                        ->required($locale === 'gu')
                        ->maxLength(255);
                    if ($locale === 'en') {
                        $name->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')));
                    }

                    return [$name];
                }),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(64)
                    ->helperText('Used in URLs and the app. Auto-filled from the English name.'),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('name_en')->label('Name (English)'),
                Tables\Columns\TextColumn::make('slug'),
                Tables\Columns\TextColumn::make('sevas_count')->counts('sevas')->label('Sevas'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (SevaCategory $record, Tables\Actions\DeleteAction $action) {
                        $inUse = Seva::where('category', $record->slug)->count();
                        if ($inUse > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Category is in use')
                                ->body("{$inUse} seva(s) use this category. Move or delete them first.")
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevaCategories::route('/'),
            'create' => Pages\CreateSevaCategory::route('/create'),
            'edit' => Pages\EditSevaCategory::route('/{record}/edit'),
        ];
    }
}
