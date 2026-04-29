<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyDarshanPhotoResource\Pages;

use App\Filament\Resources\DailyDarshanPhotoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyDarshanPhoto extends EditRecord
{
    protected static string $resource = DailyDarshanPhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
