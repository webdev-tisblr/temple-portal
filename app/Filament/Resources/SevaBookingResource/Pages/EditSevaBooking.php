<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaBookingResource\Pages;

use App\Filament\Resources\SevaBookingResource;
use App\Services\SevaReceiptService;
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
        //
        // This one is the plain seva BOOKING receipt (2026-07-28),
        // regenerated on demand if the R2 sweep removed the cached PDF.
        return [
            Actions\Action::make('download_receipt')
                ->label('Download Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn () => in_array(
                    $this->record->status->value,
                    ['confirmed', 'completed'],
                    true,
                ))
                ->action(function () {
                    // Regenerates when absent OR when the cached PDF is in a
                    // language the devotee no longer uses. The receipt is
                    // always rendered in the DEVOTEE's language, not the
                    // admin's — it is their document.
                    if (app(SevaReceiptService::class)->needsRegeneration($this->record)) {
                        app(SevaReceiptService::class)
                            ->generateReceipt($this->record);
                        $this->record->refresh();
                    }

                    // Redirect to a presigned R2 URL — never return raw PDF
                    // bytes from a Livewire action (throws "Malformed UTF-8").
                    return private_file_redirect(
                        $this->record->receipt_path,
                        'Seva_Receipt_'.str_replace('/', '-', (string) $this->record->receipt_number).'.pdf',
                    );
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
