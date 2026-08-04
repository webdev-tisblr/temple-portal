<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaCategoryResource\Pages;

use App\Filament\Resources\SevaCategoryResource;
use App\Filament\Resources\SevaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSevaCategories extends ListRecords
{
    protected static string $resource = SevaCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_sevas')
                ->label('Back to Sevas')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(SevaResource::getUrl('index')),
            Actions\CreateAction::make(),
        ];
    }
}
