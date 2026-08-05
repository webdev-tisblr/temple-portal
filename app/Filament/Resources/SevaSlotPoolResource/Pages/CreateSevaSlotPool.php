<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaSlotPoolResource\Pages;

use App\Filament\Resources\SevaSlotPoolResource;
use App\Filament\Support\SlotConfigFields;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;

class CreateSevaSlotPool extends CreateRecord
{
    protected static string $resource = SevaSlotPoolResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return SlotConfigFields::normalizeForSave($data);
    }

    protected function afterCreate(): void
    {
        Cache::forget('active_sevas');
    }
}
