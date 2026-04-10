<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationResource\Pages;

use App\Filament\Resources\DonationResource;
use App\Services\ReceiptService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDonation extends ViewRecord
{
    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate_receipt')
                ->label('Generate 80G Receipt')
                ->icon('heroicon-o-document-arrow-down')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Generate 80G Receipt')
                ->modalDescription('This will generate a digital 80G receipt PDF for this donation.')
                ->visible(fn () => ! $this->record->receipt_generated)
                ->action(function () {
                    $receipt = app(ReceiptService::class)->generateReceipt($this->record);
                    $this->record->update(['receipt_generated' => true]);

                    Notification::make()
                        ->title("Receipt {$receipt->receipt_number} generated")
                        ->success()->send();
                }),

            Actions\Action::make('download_receipt')
                ->label('Download Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn () => $this->record->receipt_generated && $this->record->receipt?->pdf_path)
                ->action(function () {
                    return response()->download(
                        storage_path('app/private/' . $this->record->receipt->pdf_path)
                    );
                }),
        ];
    }
}
