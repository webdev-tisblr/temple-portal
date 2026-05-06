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
                    $bytes = \Illuminate\Support\Facades\Storage::disk('r2_private')->get($this->record->invoice_path);
                    return response($bytes, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Length' => (string) strlen($bytes),
                        'Content-Disposition' => 'attachment; filename="Hall_Booking_' . $this->record->id . '.pdf"',
                    ]);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
