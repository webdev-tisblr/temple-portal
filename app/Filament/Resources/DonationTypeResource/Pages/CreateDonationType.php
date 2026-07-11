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

        $config['show_on_thankyou'] = $data['_show_on_thankyou'] ?? true;

        $data['greeting_card_config'] = $config;

        unset($data['_show_on_thankyou']);

        return $data;
    }
}
