<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasManagedImages;

    protected $table = 'temple_pages';

    protected static function booted(): void
    {
        // The header/footer "Mandir" menus list published pages from this
        // cache — bust it so adds/edits/deletes reflect immediately.
        $bust = fn () => \Illuminate\Support\Facades\Cache::forget('nav.cms_pages.v1');
        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * Published top-level pages for the site navigation (header dropdown +
     * footer column), ordered like the admin list. Cached as plain arrays;
     * the locale is resolved at render time by the caller.
     *
     * @return list<array{slug: string, title_gu: ?string, title_hi: ?string, title_en: ?string}>
     */
    public static function navPages(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('nav.cms_pages.v1', 3600, function () {
            return self::query()
                ->where('status', 'published')
                ->whereNull('parent_slug')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['slug', 'title_gu', 'title_hi', 'title_en'])
                ->map->only(['slug', 'title_gu', 'title_hi', 'title_en'])
                ->all();
        });
    }

    protected function managedImages(): array
    {
        return ['featured_image_path' => 'r2'];
    }

    protected $fillable = [
        'slug',
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'blocks_gu',
        'blocks_hi',
        'blocks_en',
        'featured_image_path',
        'meta_title',
        'meta_description',
        'parent_slug',
        'sort_order',
        'status',
        'template',
        'created_by',
        'published_at',
    ];

    protected $casts = [
        'status' => PageStatus::class,
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'blocks_gu' => 'array',
        'blocks_hi' => 'array',
        'blocks_en' => 'array',
    ];

    /**
     * Locale-resolved content blocks (falls back to Gujarati). Returns an
     * array of ['type' => ..., 'data' => [...]] entries, or [] if the page
     * still uses the legacy HTML body.
     */
    public function getBlocksAttribute(): array
    {
        $locale = app()->getLocale();
        $field = "blocks_{$locale}";

        $blocks = $this->$field ?? $this->blocks_gu;

        return is_array($blocks) ? $blocks : [];
    }

    public function getTitleAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "title_{$locale}";

        // ?: (not ??) so empty strings also fall back to Gujarati.
        return $this->$field ?: (string) $this->title_gu;
    }

    public function getBodyAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "body_{$locale}";

        // body_gu is nullable since blocks-only pages exist — never return
        // null from this string-typed accessor.
        return $this->$field ?: ($this->body_gu ?? '');
    }
}
