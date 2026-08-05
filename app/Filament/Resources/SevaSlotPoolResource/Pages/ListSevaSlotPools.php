<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaSlotPoolResource\Pages;

use App\Filament\Resources\SevaSlotPoolResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSevaSlotPools extends ListRecords
{
    protected static string $resource = SevaSlotPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
