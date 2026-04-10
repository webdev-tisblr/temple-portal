<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SevaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_gu' => $this->name_gu,
            'name_hi' => $this->name_hi,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'description_gu' => $this->description_gu,
            'description_hi' => $this->description_hi,
            'description_en' => $this->description_en,
            'category' => $this->category,
            'price' => (float) $this->price,
            'min_price' => $this->min_price ? (float) $this->min_price : null,
            'is_variable_price' => $this->is_variable_price,
            'image_url' => $this->image_path ? asset('storage/' . $this->image_path) : null,
            'requires_booking' => $this->requires_booking,
            'slot_config' => $this->getResolvedSlotConfig(),
            'slot_duration_minutes' => $this->getSlotDurationMinutes(),
        ];
    }
}
