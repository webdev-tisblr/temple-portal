<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasImageDerivatives;
use App\Models\Concerns\HasManagedImages;
use App\Support\LocalizedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

class GalleryImage extends Model
{
    use HasImageDerivatives, HasManagedImages;

    protected $table = 'temple_gallery_images';

    protected static function booted(): void
    {
        // Bust home preview + API gallery lists (all + per-category, old and new category).
        $bust = static function (self $model): void {
            Cache::forget('homepage_gallery_preview');
            LocalizedCache::forget('gallery.all');
            foreach (array_unique(array_filter([$model->category, $model->getOriginal('category')])) as $cat) {
                LocalizedCache::forget("gallery.{$cat}");
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return [
            'image_path' => 'r2',
            'thumbnail_path' => 'r2',
            'medium_path' => 'r2',
        ];
    }

    /**
     * Renditions built by ImageDerivativeService. Both columns are also in
     * managedImages() above, so deleting a photo takes its thumbnails with
     * it instead of orphaning two objects on the bucket.
     */
    protected function imageDerivatives(): array
    {
        return [
            'image_path' => [
                'thumbnail' => 'thumbnail_path',
                'medium' => 'medium_path',
            ],
        ];
    }

    /**
     * Every category this photo appears under.
     *
     * Keyed on slug, not id, matching the loose convention the gallery already
     * used. The scalar `category` column survives alongside this as the
     * PRIMARY category — the installed mobile app parses `category` with a
     * hard `as String?` cast, so it can never become an array in the API.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            GalleryCategory::class,
            'temple_gallery_image_category',
            'gallery_image_id',
            'category_slug',
            'id',
            'slug',
        );
    }

    /**
     * Replace this photo's categories, keeping `category` pointed at the first
     * of them.
     *
     * Pivot writes fire no model events, so the cache bust in booted() never
     * runs for them — this does it by hand, for every slug on either side of
     * the change. Miss that and a photo moved between categories keeps showing
     * in the old one for 15 minutes.
     */
    public function syncCategories(array $slugs): void
    {
        $slugs = array_values(array_unique(array_filter($slugs)));

        if ($slugs === []) {
            $slugs = array_values(array_filter([$this->category]));
        }

        $before = $this->exists ? $this->categories()->pluck('slug')->all() : [];

        $this->categories()->sync($slugs);

        // Promote the first slug so the app and the web tabs never read a
        // primary that is no longer attached.
        $primary = $slugs[0] ?? $this->category;

        if ($primary !== null && $this->category !== $primary) {
            $this->forceFill(['category' => $primary])->save();
        }

        foreach (array_unique(array_merge($before, $slugs)) as $slug) {
            LocalizedCache::forget("gallery.{$slug}");
        }

        LocalizedCache::forget('gallery.all');
        Cache::forget('homepage_gallery_preview');
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
