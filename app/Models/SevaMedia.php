<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SevaMedia extends Model
{
    use HasManagedImages;

    protected $table = 'temple_seva_media';

    protected $fillable = [
        'seva_id',
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

    public function seva(): BelongsTo
    {
        return $this->belongsTo(Seva::class, 'seva_id');
    }
}
