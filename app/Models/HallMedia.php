<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HallMedia extends Model
{
    use HasManagedImages;

    protected $table = 'temple_hall_media';

    protected $fillable = [
        'hall_id',
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

    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class, 'hall_id');
    }
}
