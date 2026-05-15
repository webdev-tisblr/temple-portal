<?php

declare(strict_types=1);

namespace App\Filament\Resources\NotificationResource\Pages;

use App\Filament\Resources\NotificationResource;
use App\Jobs\SendPushNotification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateNotification extends CreateRecord
{
    protected static string $resource = NotificationResource::class;

    /**
     * Decide status based on the send_mode radio (which is dehydrated=false, so
     * we read it from the page's form state, not the $data array Filament
     * passes us). Send now → status=sending and clear scheduled_at; Schedule →
     * status=scheduled and keep scheduled_at.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $sendMode = $this->data['send_mode'] ?? 'now';

        if ($sendMode === 'now') {
            $data['status'] = 'sending';
            $data['scheduled_at'] = null;
        } else {
            $data['status'] = 'scheduled';
        }

        $data['created_by'] = Auth::id();

        return $data;
    }

    /**
     * Dispatch immediately for "Send now". The scheduler picks up "schedule"
     * rows on its 5-minute tick — no immediate dispatch needed there.
     */
    protected function afterCreate(): void
    {
        $sendMode = $this->data['send_mode'] ?? 'now';

        if ($sendMode === 'now') {
            SendPushNotification::dispatch($this->record);
        }
    }
}
