<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;

class GalleryImage extends Model
{
    use HasManagedImages;

    protected $table = 'temple_gallery_images';

    protected function managedImages(): array
    {
        return [
            'image_path' => 'r2',
            'thumbnail_path' => 'r2',
            'medium_path' => 'r2',
        ];
    }

    protected $fillable = [
        'type',
        'title',
        'description',
        'image_path',
        'video_url',
        'thumbnail_path',
        'medium_path',
        'category',
        'is_wallpaper',
        'sort_order',
        'uploaded_by',
    ];

    protected $casts = [
        'is_wallpaper' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * A displayable thumbnail URL for any gallery item. Photos use their
     * (thumbnail → medium → full) image; videos derive a YouTube still, or
     * fall back to any uploaded image. Returns null when nothing is usable.
     */
    public function getThumbUrlAttribute(): ?string
    {
        if (($this->type ?? 'photo') === 'video') {
            $url = $this->video_url;
            $id = null;
            if ($url) {
                if (str_contains($url, 'youtu.be/')) {
                    $id = explode('?', explode('youtu.be/', $url)[1] ?? '')[0] ?: null;
                } elseif (preg_match('/[?&]v=([^&]+)/', $url, $m)) {
                    $id = $m[1];
                } elseif (preg_match('#youtube\.com/embed/([^?&/]+)#', $url, $m)) {
                    $id = $m[1];
                }
            }
            if ($id) {
                return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
            }
            return $this->image_path ? image_url($this->image_path) : null;
        }

        $key = $this->thumbnail_path ?: ($this->medium_path ?: $this->image_path);
        return $key ? image_url($key) : null;
    }
}
