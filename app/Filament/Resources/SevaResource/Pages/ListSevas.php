<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaResource\Pages;

use App\Filament\Resources\SevaCategoryResource;
use App\Filament\Resources\SevaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSevas extends ListRecords
{
    protected static string $resource = SevaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('categories')
                ->label('Categories')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(SevaCategoryResource::getUrl('index'))
                ->visible(fn (): bool => auth('admin')->user()?->can('view_any_seva::category') ?? false),
            Actions\CreateAction::make(),
        ];
    }
}
