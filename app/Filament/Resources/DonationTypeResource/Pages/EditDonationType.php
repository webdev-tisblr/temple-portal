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
        $data['_send_via_email'] = $config['send_via_email'] ?? true;
        $data['_send_via_whatsapp'] = $config['send_via_whatsapp'] ?? true;
        $data['_show_on_thankyou'] = $config['show_on_thankyou'] ?? true;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Merge sending toggles into greeting_card_config
        $config = $data['greeting_card_config'] ?? [];
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        $config['send_via_email'] = $data['_send_via_email'] ?? true;
        $config['send_via_whatsapp'] = $data['_send_via_whatsapp'] ?? true;
        $config['show_on_thankyou'] = $data['_show_on_thankyou'] ?? true;

        $data['greeting_card_config'] = $config;

        // Remove transient fields
        unset($data['_send_via_email'], $data['_send_via_whatsapp'], $data['_show_on_thankyou']);

        return $data;
    }
}
