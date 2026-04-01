<?php

declare(strict_types=1);

namespace App\Filament\Resources\DevoteeResource\Pages;

use App\Filament\Resources\DevoteeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDevotees extends ListRecords
{
    protected static string $resource = DevoteeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
