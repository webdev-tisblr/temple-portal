<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyDarshanPhotoResource\Pages;

use App\Filament\Resources\DailyDarshanPhotoResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDailyDarshanPhotos extends ListRecords
{
    protected static string $resource = DailyDarshanPhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
