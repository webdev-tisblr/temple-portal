<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryCategoryResource;
use App\Filament\Resources\GalleryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGalleryImages extends ListRecords
{
    protected static string $resource = GalleryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('categories')
                ->label('Categories')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(GalleryCategoryResource::getUrl('index'))
                ->visible(fn (): bool => auth('admin')->user()?->can('view_any_gallery::category') ?? false),
            Actions\CreateAction::make(),
        ];
    }
}
