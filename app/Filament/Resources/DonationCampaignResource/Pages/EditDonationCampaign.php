<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationCampaignResource\Pages;

use App\Filament\Resources\DonationCampaignResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDonationCampaign extends EditRecord
{
    protected static string $resource = DonationCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
