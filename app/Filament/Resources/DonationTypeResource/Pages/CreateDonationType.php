<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationTypeResource\Pages;

use App\Filament\Resources\DonationTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDonationType extends CreateRecord
{
    protected static string $resource = DonationTypeResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $config = $data['greeting_card_config'] ?? [];
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        $config['send_via_email'] = $data['_send_via_email'] ?? true;
        $config['send_via_whatsapp'] = $data['_send_via_whatsapp'] ?? true;
        $config['show_on_thankyou'] = $data['_show_on_thankyou'] ?? true;

        $data['greeting_card_config'] = $config;

        unset($data['_send_via_email'], $data['_send_via_whatsapp'], $data['_show_on_thankyou']);

        return $data;
    }
}
