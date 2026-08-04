<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryCategoryResource\Pages;

use App\Filament\Resources\GalleryCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGalleryCategory extends CreateRecord
{
    protected static string $resource = GalleryCategoryResource::class;
}
