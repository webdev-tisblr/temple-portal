<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Cache;

class EditSeva extends EditRecord
{
    protected static string $resource = SevaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Slot-config fill preparation is shared with SlotPoolResource.
        $data = \App\Filament\Support\SlotConfigFields::prepareForFill($data);

        // Set product selection toggle from linked_products
        $data['enable_product_selection'] = ! empty($data['linked_products']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CreateSeva::normalizeSlotConfigForSave($data);
    }

    protected function afterSave(): void
    {
        Cache::forget('active_sevas');
    }
}
