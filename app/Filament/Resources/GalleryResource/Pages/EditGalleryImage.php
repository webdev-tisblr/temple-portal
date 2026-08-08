<?php

declare(strict_types=1);

namespace App\Filament\Resources\GalleryResource\Pages;

use App\Filament\Resources\GalleryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGalleryImage extends EditRecord
{
    protected static string $resource = GalleryResource::class;

    /** @var array<int, string> */
    private array $chosenCategories = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * `categories` is a pivot rather than a column, so load it by hand.
     * Falling back to the scalar covers rows written before the pivot existed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $slugs = $this->record->categories()->pluck('slug')->all();

        $data['categories'] = $slugs !== []
            ? $slugs
            : array_values(array_filter([$this->record->category]));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->chosenCategories = array_values(array_filter((array) ($data['categories'] ?? [])));
        unset($data['categories']);

        if ($this->chosenCategories !== []) {
            $data['category'] = $this->chosenCategories[0];
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncCategories($this->chosenCategories);
    }
}
