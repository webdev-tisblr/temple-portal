<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GalleryResource\Pages;
use App\Filament\Support\TranslatableTabs;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GalleryResource extends Resource
{
    protected static ?string $model = GalleryImage::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Content Management';

    protected static ?string $navigationLabel = 'Gallery';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Select::make('type')
                    ->options(['photo' => 'Photo', 'video' => 'Video'])
                    ->default('photo')
                    ->live()
                    ->required(),
                // Caption in all three languages. Gujarati is the fallback the
                // other two resolve to (GalleryImage::getTitleAttribute), and
                // is mirrored back into the legacy `title` / `description`
                // columns on save for app builds that predate this.
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")
                        ->label("Title {$label}")
                        ->maxLength(255),
                    Forms\Components\Textarea::make("description_{$locale}")
                        ->label("Description {$label}")
                        ->maxLength(500)
                        ->rows(3),
                ], id: 'caption'),
                Forms\Components\FileUpload::make('image_path')->image()->directory('gallery')->maxSize(5120)
                    ->required(fn (Forms\Get $get): bool => $get('type') !== 'video')
                    ->visible(fn (Forms\Get $get): bool => $get('type') !== 'video'),
                Forms\Components\TextInput::make('video_url')->label('Video URL')->url()->maxLength(500)
                    ->placeholder('https://youtu.be/xxxxxxxxxxx')
                    ->required(fn (Forms\Get $get): bool => $get('type') === 'video')
                    ->visible(fn (Forms\Get $get): bool => $get('type') === 'video'),
                // A photo can sit in several categories. The first one chosen
                // becomes the primary and is what the mobile app reads; the
                // page classes keep the two in step via syncCategories().
                Forms\Components\Select::make('categories')
                    ->label('Categories')
                    ->multiple()
                    ->options(fn (): array => GalleryCategory::orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                        ->all())
                    ->default(['temple'])
                    ->required()
                    ->helperText('Pick one or more. The first is the main category. Manage the list under Content Management → Gallery Categories.'),
                Forms\Components\Toggle::make('is_wallpaper')->label('Available as Wallpaper'),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->square()->size(60),
                // Displays the accessor (locale-resolved, Gujarati fallback);
                // search has to name the real columns, since an accessor
                // cannot appear in a WHERE clause.
                Tables\Columns\TextColumn::make('title')
                    ->limit(30)
                    ->searchable(['title', 'title_gu', 'title_hi', 'title_en']),
                Tables\Columns\TextColumn::make('categories.slug')
                    ->label('Categories')
                    ->badge()
                    // Falls back to the scalar for rows predating the pivot.
                    ->default(fn ($record) => $record->category),
                Tables\Columns\IconColumn::make('is_wallpaper')->boolean()->label('Wallpaper'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->label('Category')
                    ->options(fn (): array => GalleryCategory::orderBy('sort_order')
                        ->get()
                        ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                        ->all())
                    // Query the pivot: filtering the plain column would hide
                    // photos that sit in this category as a secondary.
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $q, string $slug) => $q->whereHas(
                            'categories',
                            fn (Builder $c) => $c->where('category_slug', $slug),
                        ),
                    )),
                Tables\Filters\TernaryFilter::make('is_wallpaper'),
            ])
            // Drag to order. Bulk-uploaded photos land in upload order, and
            // fixing that one record at a time is painful with a few hundred.
            ->reorderable('sort_order')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\BulkAction::make('setCategory')
                    ->label('Set category')
                    ->icon('heroicon-o-tag')
                    // G15 (2026-08-09): custom bulk actions are not
                    // auto-authorized. `gallery::image` is the reconciled
                    // slug from G8 — the old `update_gallery` string checked
                    // a permission the Shield UI could not grant.
                    ->visible(fn (): bool => auth('admin')->user()?->can('update_gallery::image') ?? false)
                    ->form([
                        Forms\Components\Select::make('categories')
                            ->label('Categories')
                            ->multiple()
                            ->options(fn (): array => GalleryCategory::orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                                ->all())
                            ->required()
                            ->helperText('Replaces the categories on every selected photo. The first is the main one.'),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        // One at a time on purpose: syncCategories() rewrites the
                        // pivot, promotes the primary and busts the per-category
                        // caches — none of which a mass update() would do.
                        $slugs = array_values(array_filter((array) ($data['categories'] ?? [])));
                        $records->each(fn ($record) => $record->syncCategories($slugs));

                        Notification::make()
                            ->title($records->count().' moved to this category')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\BulkAction::make('setWallpaper')
                    ->label('Wallpaper on / off')
                    ->icon('heroicon-o-device-phone-mobile')
                    // G15 (2026-08-09): see setCategory above.
                    ->visible(fn (): bool => auth('admin')->user()?->can('update_gallery::image') ?? false)
                    ->form([
                        Forms\Components\Toggle::make('is_wallpaper')
                            ->label('Offer as wallpaper')
                            ->default(true),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $flag = (bool) ($data['is_wallpaper'] ?? false);
                        $records->each(fn ($record) => $record->update(['is_wallpaper' => $flag]));

                        Notification::make()
                            ->title($records->count().' updated')
                            ->success()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),

                Tables\Actions\DeleteBulkAction::make(),
            ])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGalleryImages::route('/'),
            'create' => Pages\CreateGalleryImage::route('/create'),
            'edit' => Pages\EditGalleryImage::route('/{record}/edit'),
        ];
    }
}
