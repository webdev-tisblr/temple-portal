<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSevas extends ListRecords
{
    protected static string $resource = SevaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
