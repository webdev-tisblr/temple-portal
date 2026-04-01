<?php

declare(strict_types=1);

namespace App\Filament\Resources\HallBookingResource\Pages;

use App\Filament\Resources\HallBookingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHallBooking extends EditRecord
{
    protected static string $resource = HallBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
