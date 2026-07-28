<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasManagedImages;

    protected $table = 'temple_pages';

    protected static function booted(): void
    {
        // The header/footer "Mandir" menus and the app's More menu list
        // published pages from these caches — bust both so adds/edits/
        // deletes reflect immediately.
        $bust = function (): void {
            \Illuminate\Support\Facades\Cache::forget('nav.cms_pages.v1');
            \App\Support\LocalizedCache::forget('content.pages.v1');
        };
        static::saved($bust);
        static::deleted($bust);

        // Renaming a slug used to silently 404 every existing link to the
        // page — shared WhatsApp links, and the mobile app, which addresses
        // CMS pages by slug. Remember the outgoing slug so PageController
        // can still resolve it (and redirect to the new canonical URL).
        static::updating(function (self $page) {
            if (! $page->isDirty('slug')) {
                return;
            }

            $old = (string) $page->getOriginal('slug');
            if ($old === '') {
                return;
            }

            $history = $page->previous_slugs ?? [];
            $history = is_array($history) ? $history : [];

            // Drop the incoming slug if the page is being renamed back to a
            // slug it used before, so it never aliases to itself.
            $history = array_values(array_unique(array_filter(
                [...$history, $old],
                fn ($s) => $s !== $page->slug
            )));

            $page->previous_slugs = $history;
        });
    }

    /**
     * Resolve a published page by its current slug, falling back to any slug
     * it used to have. Returns null when nothing matches.
     *
     * Callers that render a canonical URL should compare `$page->slug` with
     * the requested slug and redirect when they differ.
     */
    public static function findPublishedBySlug(string $slug): ?self
    {
        return self::query()
            ->where('status', 'published')
            ->where(fn (Builder $query) => $query
                ->where('slug', $slug)
                ->orWhereJsonContains('previous_slugs', $slug))
            ->first();
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
        'previous_slugs',
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
        'previous_slugs' => 'array',
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
