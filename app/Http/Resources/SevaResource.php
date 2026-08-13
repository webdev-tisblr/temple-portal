<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SevaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $selection = $this->buildProductSelection();
        // When a seva has product selection, its price is driven by whichever
        // product the user chooses, so for display we surface a "starts from"
        // = the cheapest in-stock linked product (variant prices count for
        // variant products). Zero prices mean "this option doesn't set the
        // price" — they are excluded, and when NO option carries a price,
        // starts_from stays null so clients show the seva's own price.
        $startsFrom = null;
        if ($selection !== null) {
            $candidates = fn ($products) => collect($products)
                ->flatMap(fn ($p) => ! empty($p['variants'])
                    ? collect($p['variants'])->pluck('price')
                    : [$p['price']])
                ->filter(fn ($price) => (float) $price > 0);

            $inStock = collect($selection['products'])->where('in_stock', true);
            $pool = $candidates($inStock->isNotEmpty() ? $inStock : $selection['products']);
            if ($pool->isNotEmpty()) {
                $startsFrom = (float) $pool->min();
            }
        }

        return [
            'id' => $this->id,
            // Admin-defined questions asked at booking (2026-08-13). Carries a
            // resolved `label` matching the X-Locale header, exactly as
            // donation types do — clients read `label`, never label_gu.
            'extra_fields' => $this->localizedExtraFields(),
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
            'image_url' => $this->image_path ? image_url($this->image_path) : null,
            'requires_booking' => $this->requires_booking,
            'slot_config' => $this->getResolvedSlotConfig(),
            'slot_duration_minutes' => $this->getSlotDurationMinutes(),
            'product_selection' => $selection,
            'starts_from' => $startsFrom,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($m) => [
                'type' => $m->media_type,
                'url' => $m->media_type === 'video'
                    ? $m->video_url
                    : ($m->image_path ? image_url($m->image_path) : null),
            ])->filter(fn ($x) => $x['url'] !== null)->values(), []),
        ];
    }

    private function buildProductSelection(): ?array
    {
        if (! $this->resource->hasProductSelection()) {
            return null;
        }

        $config = $this->linked_products ?? [];
        $products = $this->resource->getLinkedProductsList()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'name_gu' => $p->name_gu,
                'name_hi' => $p->name_hi,
                'name_en' => $p->name_en,
                'price' => (float) $p->price,
                'image_url' => $p->image_path ? image_url($p->image_path) : null,
                'in_stock' => $p->inStock(),
                'has_variants' => (bool) $p->has_variants,
                'variants' => ($p->has_variants && ! empty($p->variants))
                    ? collect($p->variants)->map(fn ($v) => [
                        'label' => $v['label'] ?? '',
                        'price' => (float) ($v['price'] ?? 0),
                        // Untracked products: every variant available.
                        'in_stock' => ! $p->track_stock || (int) ($v['stock'] ?? 0) > 0,
                    ])->values()->all()
                    : [],
            ])
            ->values()
            ->all();

        if (empty($products)) {
            return null;
        }

        return [
            'label_gu' => $config['label_gu'] ?? null,
            'label_hi' => $config['label_hi'] ?? null,
            'label_en' => $config['label_en'] ?? null,
            'products' => $products,
        ];
    }
}
