<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaResource;
use App\Services\SevaSlotService;
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
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        // Normalize v1 → v2 config for the form
        $config = app(SevaSlotService::class)->normalizeConfig($data['slot_config'] ?? null);
        $data['slot_config'] = $config;

        // Set toggle states based on stored day overrides
        foreach ($days as $day) {
            $dayValue = $config['weekly_schedule'][$day] ?? null;
            $data["customize_{$day}"] = is_array($dayValue); // true if explicit array (even empty)
        }

        // Set product selection toggle from linked_products
        $data['enable_product_selection'] = !empty($data['linked_products']);

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
