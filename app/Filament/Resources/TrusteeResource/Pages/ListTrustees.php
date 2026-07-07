<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrusteeResource\Pages;

use App\Filament\Resources\TrusteeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTrustees extends ListRecords
{
    protected static string $resource = TrusteeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
