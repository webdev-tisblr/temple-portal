<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Filament\Resources\NotificationResource;
use App\Jobs\SendPushNotification;
use Filament\Actions;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Pages\EditRecord;

class EditNotification extends EditRecord
{
    protected static string $resource = NotificationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('send_now')
                ->label('Send now')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, ['draft', 'scheduled', 'failed'], true))
                ->action(function () {
                    $this->record->update([
                        'status' => 'sending',
                        'scheduled_at' => null,
                    ]);
                    SendPushNotification::dispatch($this->record);

                    FilamentNotification::make()
                        ->title('Notification dispatched')
                        ->success()
                        ->send();

                    $this->redirect(NotificationResource::getUrl('index'));
                }),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Pre-fill the send_mode radio from the saved row so the form
     * round-trips correctly on Edit (otherwise it always shows "Send now"
     * even for a row that was originally scheduled).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['send_mode'] = $this->record->scheduled_at ? 'schedule' : 'now';
        return $data;
    }
}
