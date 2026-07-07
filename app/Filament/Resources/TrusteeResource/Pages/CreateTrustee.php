<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrusteeResource\Pages;

use App\Filament\Resources\TrusteeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTrustee extends CreateRecord
{
    protected static string $resource = TrusteeResource::class;
}
