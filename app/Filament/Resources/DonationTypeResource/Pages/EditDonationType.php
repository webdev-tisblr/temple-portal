<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationTypeResource\Pages;

use App\Filament\Resources\DonationTypeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDonationType extends EditRecord
{
    protected static string $resource = DonationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $config = $data['greeting_card_config'] ?? [];
        $data['_show_on_thankyou'] = $config['show_on_thankyou'] ?? true;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Merge the thank-you-page toggle into greeting_card_config. Channel
        // delivery is no longer a per-type toggle — it's controlled by the
        // donation.greeting_card notification templates.
        $config = $data['greeting_card_config'] ?? [];
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        $config['show_on_thankyou'] = $data['_show_on_thankyou'] ?? true;

        $data['greeting_card_config'] = $config;

        // Remove transient fields
        unset($data['_show_on_thankyou']);

        return $data;
    }
}
