<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatusTemplateResource\Pages;

use App\Filament\Resources\StatusTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditStatusTemplate extends EditRecord
{
    protected static string $resource = StatusTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
