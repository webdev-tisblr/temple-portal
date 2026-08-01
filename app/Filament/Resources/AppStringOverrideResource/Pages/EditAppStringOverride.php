<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppStringOverrideResource\Pages;

use App\Filament\Resources\AppStringOverrideResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAppStringOverride extends EditRecord
{
    protected static string $resource = AppStringOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
