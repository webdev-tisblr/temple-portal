<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationResource\Pages;

use App\Exceptions\Donation80GNotEligibleException;
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
                // G10 (2026-08-09): minting an 80G receipt burns a receipt
                // NUMBER off the statutory sequence — it is not a read.
                // Previously gated on record state alone, so every role that
                // could open a donation could mint one. `regenerate_80g_receipt`
                // already existed in the seeder and was never checked.
                // Both conditions live in ONE closure on purpose: calling
                // ->visible() twice replaces the first condition, it does not
                // AND them together.
                //
                // ALLOCATION SITE #2 of 4 (item 5.4): the strict PAN rule is
                // ANDed in here too, so an operator is not offered a button
                // that can only fail. The service still enforces it — this
                // condition is ergonomics, not the gate.
                ->visible(fn (): bool => ! $this->record->receipt_generated
                    && (auth('admin')->user()?->can('regenerate_80g_receipt') ?? false)
                    && app(ReceiptService::class)->isEligibleFor80G($this->record))
                ->action(function () {
                    try {
                        $receipt = app(ReceiptService::class)->generateReceipt($this->record);
                    } catch (Donation80GNotEligibleException $e) {
                        // Reachable if the donor cleared their PAN between
                        // the page render and the click.
                        Notification::make()
                            ->title('No 80G receipt for this donation')
                            ->body($e->userMessage())
                            ->warning()->send();

                        return;
                    }

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
                    // ensurePdf() takes an ISSUED receipt, so this download
                    // path can never mint a new number — only the explicit
                    // "Generate" action above allocates.
                    if ($receipt && ! $receipt->pdf_path) {
                        try {
                            $receipt = app(ReceiptService::class)->ensurePdf($receipt);
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
