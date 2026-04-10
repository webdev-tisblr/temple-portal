<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationCampaignResource\Pages;

use App\Filament\Resources\DonationCampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDonationCampaign extends CreateRecord
{
    protected static string $resource = DonationCampaignResource::class;
}
