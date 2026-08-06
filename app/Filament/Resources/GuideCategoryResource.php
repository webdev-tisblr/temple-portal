<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GuideCategoryResource\Pages;
use App\Models\GuideCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GuideCategoryResource extends Resource
{
    protected static ?string $model = GuideCategory::class;

    // No sidebar entry — reached via the "Categories" button on the
    // User Guides list page (same pattern as Gallery Categories).
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Content Management';

    protected static ?string $navigationLabel = 'Guide Categories';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Category')->schema([
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("name_{$locale}")
                        ->label("Name {$label}")
                        ->required($locale === 'gu')
                        ->maxLength(255),
                ]),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable(),
                Tables\Columns\TextColumn::make('name_en')->label('Name (English)'),
                Tables\Columns\TextColumn::make('guides_count')->counts('guides')->label('Guides'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGuideCategories::route('/'),
            'create' => Pages\CreateGuideCategory::route('/create'),
            'edit' => Pages\EditGuideCategory::route('/{record}/edit'),
        ];
    }
}
