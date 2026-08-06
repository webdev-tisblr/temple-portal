<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideResource\Pages;

use App\Filament\Resources\GuideCategoryResource;
use App\Filament\Resources\GuideResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGuides extends ListRecords
{
    protected static string $resource = GuideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('categories')
                ->label('Categories')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(GuideCategoryResource::getUrl('index'))
                ->visible(fn (): bool => auth('admin')->user()?->can('view_any_guide::category') ?? false),
            Actions\CreateAction::make(),
        ];
    }
}
