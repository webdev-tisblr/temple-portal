<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyDarshanPhotoResource\Pages;

use App\Filament\Resources\DailyDarshanPhotoResource;
use App\Jobs\NotifyBookingDayDevoteesOfDarshanPhoto;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDailyDarshanPhoto extends EditRecord
{
    protected static string $resource = DailyDarshanPhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Manual trigger for the booking-day darshan delivery — covers
            // photos created inactive and activated later (the automatic
            // hook only fires on create), or a deliberate re-send.
            // Idempotency keys make a double-press inside the dedupe
            // window harmless.
            Actions\Action::make('send_booking_day_notifications')
                ->label('Send to booked devotees')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                // G12 (2026-08-09): fan-out to every devotee booked that day
                // over WhatsApp/push. Outbound-message actions are gated on
                // `send_announcement`, which existed in the seeder but was
                // checked nowhere.
                ->visible(fn (): bool => auth('admin')->user()?->can('send_announcement') ?? false)
                ->modalDescription(fn () => 'Sends this darshan photo to every devotee with a confirmed booking on '
                    .($this->record->captured_on?->format('d M Y') ?? '—')
                    .' for sevas with the darshan toggle enabled, using the "Darshan — photo for booking-day devotees" templates.')
                ->action(function () {
                    if (! $this->record->is_active) {
                        Notification::make()
                            ->title('Photo is inactive')
                            ->body('Activate the photo first — inactive photos are never sent.')
                            ->warning()
                            ->send();

                        return;
                    }

                    NotifyBookingDayDevoteesOfDarshanPhoto::dispatch($this->record->id);

                    Notification::make()
                        ->title('Queued')
                        ->body('Darshan notifications are being sent to booked devotees in the background.')
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
