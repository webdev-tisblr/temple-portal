<?php

declare(strict_types=1);

namespace App\Filament\Resources\StatusTemplateResource\Pages;

use App\Filament\Resources\StatusTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListStatusTemplates extends ListRecords
{
    protected static string $resource = StatusTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
