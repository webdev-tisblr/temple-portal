<?php

declare(strict_types=1);

namespace App\Filament\Resources\HallBookingResource\Pages;

use App\Filament\Resources\HallBookingResource;
use Filament\Resources\Pages\ListRecords;

class ListHallBookings extends ListRecords
{
    protected static string $resource = HallBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
