<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideCategoryResource\Pages;

use App\Filament\Resources\GuideCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditGuideCategory extends EditRecord
{
    protected static string $resource = GuideCategoryResource::class;
}
