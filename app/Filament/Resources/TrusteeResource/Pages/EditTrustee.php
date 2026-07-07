<?php

declare(strict_types=1);

namespace App\Filament\Resources\TrusteeResource\Pages;

use App\Filament\Resources\TrusteeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTrustee extends EditRecord
{
    protected static string $resource = TrusteeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
