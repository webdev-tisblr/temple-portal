<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationTypeResource\Pages;

use App\Filament\Resources\DonationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDonationTypes extends ListRecords
{
    protected static string $resource = DonationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
