<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaSlotPoolResource\Pages;

use App\Filament\Resources\SevaSlotPoolResource;
use App\Filament\Support\SlotConfigFields;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditSevaSlotPool extends EditRecord
{
    protected static string $resource = SevaSlotPoolResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SlotConfigFields::prepareForFill($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SlotConfigFields::normalizeForSave($data);
    }

    protected function afterSave(): void
    {
        Cache::forget('active_sevas');
    }
}
