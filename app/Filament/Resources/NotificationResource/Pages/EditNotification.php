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
     * Pre-fill synthetic form fields from the persisted state so Edit
     * round-trips correctly: send_mode (from scheduled_at presence) and
     * intent_target (from intent_params).
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['send_mode'] = $this->record->scheduled_at ? 'schedule' : 'now';

        $params = $this->record->intent_params ?? [];
        $data['intent_target'] = $params['slug'] ?? $params['id'] ?? null;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // intent_target lands in $data directly now (no dehydrated(false)).
        // Read + unset here to convert to the persisted intent_params JSON.
        $target = $data['intent_target'] ?? null;
        $intent = $data['intent'] ?? null;
        unset($data['intent_target']);

        if ($target !== null && $target !== '' && $intent !== null) {
            $data['intent_params'] = match ($intent) {
                'seva-detail', 'campaign-detail', 'event-detail', 'guide-detail' => ['id' => $target],
                default => null,
            };
        } else {
            $data['intent_params'] = null;
        }

        return $data;
    }
}
