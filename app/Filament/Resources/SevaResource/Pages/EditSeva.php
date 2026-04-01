<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSeva extends EditRecord
{
    protected static string $resource = SevaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
