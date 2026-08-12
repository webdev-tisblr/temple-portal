<?php

declare(strict_types=1);

namespace App\Filament\Resources\HallBookingResource\Pages;

use App\Filament\Resources\HallBookingResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

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
                ->visible(fn () => in_array(
                    $this->record->status,
                    ['confirmed', 'completed'],
                    true,
                ))
                ->action(function () {
                    // Self-heal like every other download surface: the PDF on
                    // r2_private is a regenerable cache, and needsRegeneration()
                    // also catches an invoice rendered in a language the
                    // devotee has since changed away from. Previously this
                    // button was simply hidden once the sweep cleared the
                    // path, leaving the admin no way to get the invoice back.
                    if (app(\App\Services\HallInvoiceService::class)->needsRegeneration($this->record)) {
                        try {
                            app(\App\Services\HallInvoiceService::class)->generateInvoice($this->record);
                            $this->record->refresh();
                        } catch (\Throwable $e) {
                            Log::error('Admin hall invoice regen failed', [
                                'booking_id' => $this->record->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        if (empty($this->record->invoice_path)) {
                            Notification::make()
                                ->title('Invoice could not be generated')
                                ->danger()
                                ->send();

                            return null;
                        }
                    }

                    // Redirect to a presigned R2 URL — never return raw PDF
                    // bytes from a Livewire action (throws "Malformed UTF-8").
                    return private_file_redirect(
                        $this->record->invoice_path,
                        'Hall_Booking_' . $this->record->id . '.pdf',
                    );
                }),
            // ── Cancellation requests (2026-08-12) ────────────────────
            // A devotee can only ASK. These two actions are the decision,
            // and they are the only way a requested cancellation resolves.
            //
            // Both are gated inside a SINGLE ->visible() closure: a second
            // ->visible() call REPLACES the first rather than AND-ing it
            // (the same trap documented on OrderResource::cancel_order).
            Actions\Action::make('approve_cancellation')
                ->label('Approve cancellation')
                ->icon('heroicon-o-check-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->hasOpenCancellationRequest())
                ->requiresConfirmation()
                ->modalHeading('Approve this cancellation request?')
                ->modalDescription(fn (): string => sprintf(
                    'The booking will be cancelled and %s frees up for other devotees. '
                    .'This does NOT issue a refund — settle the ₹%s with the devotee separately.',
                    $this->record->date_range_label,
                    number_format((float) $this->record->total_amount, 2),
                ))
                ->modalSubmitActionLabel('Yes, cancel the booking')
                ->action(function (): void {
                    $this->record->update([
                        'status' => 'cancelled',
                        'cancel_responded_at' => now(),
                    ]);
                    $this->record->refresh();
                    $this->fillForm();

                    Notification::make()
                        ->title('Booking cancelled')
                        ->body('The date is now free. Any refund must be handled separately.')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('decline_cancellation')
                ->label('Decline request')
                ->icon('heroicon-o-x-circle')
                ->color('gray')
                ->visible(fn (): bool => $this->hasOpenCancellationRequest())
                ->requiresConfirmation()
                ->modalHeading('Decline this cancellation request?')
                ->modalDescription('The booking stays confirmed and the date stays blocked. Tell the devotee why — this does not message them.')
                ->modalSubmitActionLabel('Decline request')
                ->action(function (): void {
                    // The request stays on the record as history; only the
                    // response timestamp closes it out of the queue.
                    $this->record->update(['cancel_responded_at' => now()]);
                    $this->record->refresh();
                    $this->fillForm();

                    Notification::make()
                        ->title('Cancellation request declined')
                        ->body('The booking remains confirmed.')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    /** An unanswered devotee request is pending on this booking. */
    private function hasOpenCancellationRequest(): bool
    {
        return $this->record->cancel_requested_at !== null
            && $this->record->cancel_responded_at === null;
    }
}
