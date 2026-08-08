<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryCategoryResource;
use App\Filament\Resources\GalleryResource;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGalleryImages extends ListRecords
{
    protected static string $resource = GalleryResource::class;

    /** Upper bound per bulk run — also PHP's max_file_uploads on the server. */
    private const BULK_MAX_FILES = 20;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('categories')
                ->label('Categories')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(GalleryCategoryResource::getUrl('index'))
                ->visible(fn (): bool => auth('admin')->user()?->can('view_any_gallery::category') ?? false),

            // Bulk upload. One temple_gallery_images row per file — the API,
            // the app and the cleanup trait all assume one row = one image,
            // so this fans out rather than storing an array in image_path.
            Actions\Action::make('bulkUpload')
                ->label('Bulk upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Bulk upload photos')
                ->modalDescription('Pick several photos at once — each becomes its own gallery item. Large photos are scaled down and compressed automatically, so upload straight from the camera roll.')
                ->modalSubmitActionLabel('Upload')
                ->visible(fn (): bool => auth('admin')->user()?->can('create_gallery') ?? false)
                ->form([
                    Forms\Components\Select::make('category')
                        ->label('Category')
                        // Same shape as GalleryResource's select: admin UI stays
                        // English, and the localised `name` accessor must not be
                        // plucked straight off the query builder.
                        ->options(fn (): array => GalleryCategory::orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->slug => $c->name_en ?? $c->name_gu])
                            ->all())
                        ->default('temple')
                        ->required(),

                    Forms\Components\FileUpload::make('images')
                        ->label('Photos')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->directory('gallery')
                        ->maxFiles(self::BULK_MAX_FILES)
                        ->maxSize(12288)
                        ->required()
                        ->helperText('Up to '.self::BULK_MAX_FILES.' photos per upload. Drag to set the order they appear in.'),

                    Forms\Components\Toggle::make('is_wallpaper')
                        ->label('Offer as wallpaper')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $paths = array_values(array_filter((array) ($data['images'] ?? [])));

                    if ($paths === []) {
                        return;
                    }

                    // Continue the existing run rather than restarting at 0, so a
                    // second bulk upload lands after the first in the gallery.
                    $sortOrder = (int) GalleryImage::max('sort_order');

                    foreach ($paths as $path) {
                        GalleryImage::create([
                            'type' => 'photo',
                            'image_path' => $path,
                            'category' => $data['category'],
                            'is_wallpaper' => (bool) ($data['is_wallpaper'] ?? false),
                            'sort_order' => ++$sortOrder,
                            'uploaded_by' => auth('admin')->id(),
                        ]);
                    }

                    Notification::make()
                        ->title(count($paths).' '.str('photo')->plural(count($paths)).' added to the gallery')
                        ->body('Give them titles from the list if you want captions shown.')
                        ->success()
                        ->send();
                }),

            Actions\CreateAction::make(),
        ];
    }
}
