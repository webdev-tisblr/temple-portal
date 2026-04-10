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
            Actions\Action::make('download_invoice')
                ->label('Download Invoice')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn () => ! empty($this->record->invoice_path))
                ->action(function () {
                    return response()->download(
                        storage_path('app/private/' . $this->record->invoice_path)
                    );
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
