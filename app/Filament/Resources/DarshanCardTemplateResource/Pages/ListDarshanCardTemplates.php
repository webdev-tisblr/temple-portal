<?php

declare(strict_types=1);

namespace App\Filament\Resources\DarshanCardTemplateResource\Pages;

use App\Filament\Resources\DarshanCardTemplateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDarshanCardTemplates extends ListRecords
{
    protected static string $resource = DarshanCardTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
