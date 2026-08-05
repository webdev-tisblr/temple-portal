<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaResource;
use Illuminate\Support\Facades\Cache;
use Filament\Resources\Pages\CreateRecord;

class CreateSeva extends CreateRecord
{
    protected static string $resource = SevaResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return self::normalizeSlotConfigForSave($data);
    }

    protected function afterCreate(): void
    {
        Cache::forget('active_sevas');
    }

    public static function normalizeSlotConfigForSave(array $data): array
    {
        // Slot-config normalization is shared with SlotPoolResource.
        $data = \App\Filament\Support\SlotConfigFields::normalizeForSave($data);

        // Handle product selection toggle (transient field, seva-only)
        if (empty($data['enable_product_selection'])) {
            $data['linked_products'] = null;
        }
        unset($data['enable_product_selection']);

        return $data;
    }
}
