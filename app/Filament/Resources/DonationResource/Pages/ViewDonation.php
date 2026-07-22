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
                ->visible(fn () => (bool) $this->record->receipt_generated)
                ->action(function () {
                    $donation = $this->record;
                    $receipt = $donation->receipt;

                    // Self-heal: the cached PDF is swept nightly (pdf_path
                    // nulled), so regenerate on demand before downloading.
                    if (! $receipt || ! $receipt->pdf_path) {
                        try {
                            app(ReceiptService::class)->generateReceipt($donation->fresh());
                            $donation->refresh();
                            $receipt = $donation->receipt;
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not prepare the receipt. Please try again.')
                                ->danger()->send();
                            return;
                        }
                    }

                    if (! $receipt || ! $receipt->pdf_path) {
                        Notification::make()->title('Receipt file unavailable.')->danger()->send();
                        return;
                    }

                    // Redirect to a short-lived presigned R2 URL. NEVER return
                    // raw PDF bytes from a Livewire action — Livewire tries to
                    // JSON-encode the binary body and throws "Malformed UTF-8".
                    return private_file_redirect(
                        $receipt->pdf_path,
                        'receipt-' . str_replace('/', '-', $receipt->receipt_number) . '.pdf',
                    );
                }),
        ];
    }
}
