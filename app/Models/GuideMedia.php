<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuideMedia extends Model
{
    use HasManagedImages;

    protected $table = 'temple_guide_media';

    protected static function booted(): void
    {
        // The media Repeater saves child rows directly, so bust the guides
        // cache here too — the parent's saved hook alone can miss edits.
        $bust = static fn () => \App\Support\LocalizedCache::forget('guides');
        static::saved($bust);
        static::deleted($bust);
    }

    protected $fillable = [
        'guide_id',
        'media_type',
        'image_path',
        'video_url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(Guide::class, 'guide_id');
    }
}
