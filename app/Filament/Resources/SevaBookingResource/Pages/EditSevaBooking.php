<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaBookingResource\Pages;

use App\Filament\Resources\SevaBookingResource;
use App\Support\SevaReceiptDelivery;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSevaBooking extends EditRecord
{
    protected static string $resource = SevaBookingResource::class;

    protected function getHeaderActions(): array
    {
        // ONE button for both kinds of receipt (2026-08-31).
        //
        // History: a "Download 80G Receipt" action was removed on
        // 2026-05-13 when seva bookings stopped synthesizing Donation
        // rows, because seva payments were not 80G-eligible then. They can
        // be again — per booking, when the devotee ticked the box and holds
        // a valid PAN — but a booking has exactly ONE receipt, so this
        // stays a single button whose label says which document it hands
        // over. Legacy pre-2026-05-13 rows are still under /admin/donations
        // filtered by seva_booking_id.
        return [
            Actions\Action::make('download_receipt')
                ->label(fn () => $this->record->receipt80G !== null
                    ? 'Download 80G Receipt'
                    : 'Download Receipt')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->visible(fn () => in_array(
                    $this->record->status->value,
                    ['confirmed', 'completed'],
                    true,
                ))
                ->action(function () {
                    // Regenerates when absent OR (for the plain receipt)
                    // when the cached PDF is in a language the devotee no
                    // longer uses — it is always rendered in the DEVOTEE's
                    // language, not the admin's. The 80G receipt is
                    // English-only by statute and carries no locale.
                    //
                    // ⚠ ALLOCATION-FREE: this can refresh an issued 80G
                    // PDF but never mints a receipt number. Downloading
                    // must never burn a statutory serial.
                    $resolved = SevaReceiptDelivery::resolve($this->record);

                    if ($resolved === null) {
                        Notification::make()
                            ->title('Receipt PDF could not be generated')
                            ->body('Try again shortly. If it keeps failing, check the R2 credentials and the logs.')
                            ->danger()
                            ->send();

                        return null;
                    }

                    [$path, $filename] = $resolved;

                    // Redirect to a presigned R2 URL — never return raw PDF
                    // bytes from a Livewire action (throws "Malformed UTF-8").
                    return private_file_redirect($path, $filename);
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
