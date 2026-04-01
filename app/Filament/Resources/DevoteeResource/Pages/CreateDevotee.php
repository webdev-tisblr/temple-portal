<?php

declare(strict_types=1);

namespace App\Filament\Resources\DevoteeResource\Pages;

use App\Filament\Resources\DevoteeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDevotee extends CreateRecord
{
    protected static string $resource = DevoteeResource::class;
}
