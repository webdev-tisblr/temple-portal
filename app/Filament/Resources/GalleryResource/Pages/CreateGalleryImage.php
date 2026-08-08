<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGalleryImage extends CreateRecord
{
    protected static string $resource = GalleryResource::class;

    /** @var array<int, string> */
    private array $chosenCategories = [];

    /**
     * `categories` is a pivot, not a column. Hold it aside so the insert does
     * not try to write it, and seed the scalar `category` with the first pick
     * so the row is never created without a primary.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->chosenCategories = array_values(array_filter((array) ($data['categories'] ?? [])));
        unset($data['categories']);

        $data['category'] = $this->chosenCategories[0] ?? 'temple';

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncCategories($this->chosenCategories);
    }
}
