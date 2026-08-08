<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GalleryCategoryResource\Pages;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class GalleryCategoryResource extends Resource
{
    protected static ?string $model = GalleryCategory::class;

    // No sidebar entry — reached via the "Categories" button on the
    // Gallery list page (user request 2026-08-04).
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Content Management';

    protected static ?int $navigationSort = 51;

    protected static ?string $navigationLabel = 'Gallery Categories';

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
                    ->maxLength(255)
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
                Tables\Columns\TextColumn::make('images_count')->counts('images')->label('Images'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->before(function (GalleryCategory $record, Tables\Actions\DeleteAction $action) {
                        // Count via the pivot, not the primary column: a photo
                        // can sit in this category as a secondary and would
                        // otherwise be missed, silently orphaning the link.
                        $inUse = $record->images()->count();
                        if ($inUse > 0) {
                            Notification::make()
                                ->danger()
                                ->title('Category is in use')
                                ->body("{$inUse} gallery item(s) use this category. Move or delete them first.")
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
            'index' => Pages\ListGalleryCategories::route('/'),
            'create' => Pages\CreateGalleryCategory::route('/create'),
            'edit' => Pages\EditGalleryCategory::route('/{record}/edit'),
        ];
    }
}
