<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationCampaignResource\Pages;

use App\Filament\Resources\DonationCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDonationCampaigns extends ListRecords
{
    protected static string $resource = DonationCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
