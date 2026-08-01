<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppStringOverrideResource\Pages;

use App\Filament\Resources\AppStringOverrideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAppStringOverride extends CreateRecord
{
    protected static string $resource = AppStringOverrideResource::class;
}
