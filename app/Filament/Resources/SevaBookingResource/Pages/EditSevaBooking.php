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
        return [
            Actions\Action::make('download_receipt')
                ->label('Download 80G Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(function () {
                    $donation = \App\Models\Donation::where('seva_booking_id', $this->record->id)
                        ->latest()->first();
                    return $donation && $donation->receipt_generated;
                })
                ->action(function () {
                    $donation = \App\Models\Donation::where('seva_booking_id', $this->record->id)
                        ->with('receipt')->latest()->first();
                    if (! $donation || ! $donation->receipt || ! $donation->receipt->pdf_path) {
                        return;
                    }
                    $disk = \Illuminate\Support\Facades\Storage::disk('r2_private');
                    if (! $disk->exists($donation->receipt->pdf_path)) {
                        return;
                    }
                    $bytes = $disk->get($donation->receipt->pdf_path);
                    return response($bytes, 200, [
                        'Content-Type' => 'application/pdf',
                        'Content-Length' => (string) strlen($bytes),
                        'Content-Disposition' => 'attachment; filename="receipt-' . str_replace('/', '-', $donation->receipt->receipt_number) . '.pdf"',
                    ]);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
