<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaBookingResource\Pages;

use App\Filament\Resources\SevaBookingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSevaBooking extends EditRecord
{
    protected static string $resource = SevaBookingResource::class;

    protected function getHeaderActions(): array
    {
        // The "Download 80G Receipt" action was removed on 2026-05-13
        // when seva bookings stopped synthesizing Donation rows. Seva
        // payments are not 80G-eligible; the button only ever applied
        // to a few legacy rows from before that change. If you need to
        // export a legacy receipt, find the row directly in
        // /admin/donations filtered by the seva_booking_id.
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
