<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryCategoryResource\Pages;

use App\Filament\Resources\GalleryCategoryResource;
use App\Filament\Resources\GalleryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGalleryCategories extends ListRecords
{
    protected static string $resource = GalleryCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_gallery')
                ->label('Back to Gallery')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(GalleryResource::getUrl('index')),
            Actions\CreateAction::make(),
        ];
    }
}
