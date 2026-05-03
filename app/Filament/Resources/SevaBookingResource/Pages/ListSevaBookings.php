<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaBookingResource\Pages;

use App\Filament\Resources\SevaBookingResource;
use Filament\Resources\Pages\ListRecords;

class ListSevaBookings extends ListRecords
{
    protected static string $resource = SevaBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
