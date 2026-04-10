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
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        // Normalize default slots: trim seconds, sort, dedupe
        if (isset($data['slot_config']['weekly_schedule']['default'])) {
            $data['slot_config']['weekly_schedule']['default'] = self::cleanTimeSlots(
                $data['slot_config']['weekly_schedule']['default']
            );
        }

        // Process day overrides
        foreach ($days as $day) {
            $toggleKey = "customize_{$day}";

            if (empty($data[$toggleKey])) {
                $data['slot_config']['weekly_schedule'][$day] = null;
            } else {
                $slots = $data['slot_config']['weekly_schedule'][$day] ?? [];
                $data['slot_config']['weekly_schedule'][$day] = self::cleanTimeSlots($slots);
            }

            unset($data[$toggleKey]);
        }

        // Stamp version
        if (! empty($data['slot_config'])) {
            $data['slot_config']['version'] = 2;
        }

        // Handle product selection toggle (transient field)
        if (empty($data['enable_product_selection'])) {
            $data['linked_products'] = null;
        }
        unset($data['enable_product_selection']);

        return $data;
    }

    /**
     * Normalize time values: trim to HH:MM, remove nulls/empties, sort, dedupe.
     */
    private static function cleanTimeSlots(array $slots): array
    {
        $cleaned = collect($slots)
            ->filter()
            ->map(fn ($t) => substr((string) $t, 0, 5)) // "06:00:00" → "06:00"
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        return $cleaned;
    }
}
