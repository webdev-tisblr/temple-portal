<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaReminderRuleResource\Pages;

use App\Filament\Resources\SevaReminderRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSevaReminderRule extends EditRecord
{
    protected static string $resource = SevaReminderRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
