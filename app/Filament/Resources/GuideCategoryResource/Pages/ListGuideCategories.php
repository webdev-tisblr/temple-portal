<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideCategoryResource\Pages;

use App\Filament\Resources\GuideCategoryResource;
use App\Filament\Resources\GuideResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGuideCategories extends ListRecords
{
    protected static string $resource = GuideCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_guides')
                ->label('Back to Guides')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(GuideResource::getUrl('index')),
            Actions\CreateAction::make(),
        ];
    }
}
