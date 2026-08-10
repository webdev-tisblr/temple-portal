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

        // Keep the legacy scalar columns in step with the Gujarati ones.
        //
        // `title` / `description` are what the SHIPPED app build (1.4.8+32)
        // and any other old reader still expect, so they are maintained as
        // the Gujarati mirror rather than dropped. Only touched when the
        // Gujarati field is actually being written (or already holds a
        // value) — otherwise an unrelated `update(['is_wallpaper' => …])`
        // on a pre-migration row would blank its legacy caption.
        static::saving(function (self $model): void {
            $attributes = $model->getAttributes();

            foreach (['title', 'description'] as $field) {
                $guField = "{$field}_gu";

                if ($model->isDirty($guField) || filled($attributes[$guField] ?? null)) {
                    $model->setAttribute($field, $attributes[$guField] ?? null);
                }
            }
        });
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
        // Legacy mirrors of the Gujarati caption — still written (see
        // booted()) so old app builds keep showing a caption.
        'title',
        'description',
        'title_gu',
        'title_hi',
        'title_en',
        'description_gu',
        'description_hi',
        'description_en',
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
     * Caption in the active locale, falling back to Gujarati.
     *
     * Same shape as DonationCampaign / Seva / GalleryCategory: the bare
     * attribute name resolves `{field}_{locale}` with a `_gu` fallback, so
     * every existing `$image->title` read (web blade, API payload, Filament
     * table) becomes localized without a call-site change.
     *
     * NOTE: being an accessor, this cannot be used in a raw `pluck('title')`
     * — pluck reads the column, which is only ever the Gujarati mirror.
     */
    public function getTitleAttribute(): ?string
    {
        return $this->localizedCaption('title');
    }

    public function getDescriptionAttribute(): ?string
    {
        return $this->localizedCaption('description');
    }

    /**
     * locale → Gujarati → legacy scalar column.
     *
     * Reads `$this->attributes` directly: going through `$this->title_gu`
     * would be fine today but recurses the moment someone adds a `_gu`
     * accessor. `blank()` rather than `??` so an admin who saved an empty
     * Hindi tab still gets the Gujarati caption instead of a blank one.
     */
    private function localizedCaption(string $field): ?string
    {
        $candidates = [
            "{$field}_".app()->getLocale(),
            "{$field}_gu",
            $field,
        ];

        foreach ($candidates as $key) {
            $value = $this->attributes[$key] ?? null;

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

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
