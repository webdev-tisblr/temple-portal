<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class HeroSlide extends Model
{
    use HasManagedImages;

    protected $table = 'temple_hero_slides';

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget('home.hero_slides.v1');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['image_path' => 'r2', 'image_path_mobile' => 'r2'];
    }

    protected $fillable = [
        'image_path', 'image_path_mobile',
        'heading_gu', 'heading_hi', 'heading_en',
        'sub_gu', 'sub_hi', 'sub_en',
        'cta_label_gu', 'cta_label_hi', 'cta_label_en',
        'cta_url', 'align', 'theme', 'overlay_opacity',
        'transition', 'duration_seconds',
        'starts_at', 'ends_at', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'overlay_opacity' => 'integer',
        'duration_seconds' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Active + inside the schedule window (IST wall-clock). */
    public function scopeLive(Builder $q): Builder
    {
        $now = now();

        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function headingFor(string $locale): ?string
    {
        return $this->{"heading_{$locale}"} ?: $this->heading_gu;
    }

    public function subFor(string $locale): ?string
    {
        return $this->{"sub_{$locale}"} ?: $this->sub_gu;
    }

    public function ctaLabelFor(string $locale): ?string
    {
        return $this->{"cta_label_{$locale}"} ?: $this->cta_label_gu;
    }
}
