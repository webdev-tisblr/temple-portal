<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Guide;
use App\Models\GuideCategory;
use App\Support\LocalizedCache;
use Illuminate\Http\JsonResponse;

/**
 * User Guides / Help Center — read-only content for the app's Guides
 * section. One cache key family ('guides'); the detail endpoint reads
 * through the cached list so a single LocalizedCache::forget('guides')
 * in the models' booted() hooks busts everything.
 */
class GuideController extends BaseApiController
{
    public function index(): JsonResponse
    {
        return $this->success($this->cachedPayload());
    }

    public function show(int $id): JsonResponse
    {
        $guide = collect($this->cachedPayload()['guides'])
            ->firstWhere('id', $id);

        if ($guide === null) {
            return $this->error('Guide not found', 404);
        }

        return $this->success($guide);
    }

    /**
     * @return array{categories: array, guides: array}
     */
    private function cachedPayload(): array
    {
        return LocalizedCache::remember('guides', 1800, function (): array {
            $categories = GuideCategory::query()
                ->where('is_active', true)
                ->whereHas('guides', fn ($q) => $q->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (GuideCategory $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'name_gu' => $c->name_gu,
                    'name_hi' => $c->name_hi,
                    'name_en' => $c->name_en,
                    'sort_order' => $c->sort_order,
                ])
                ->values()
                ->all();

            $guides = Guide::query()
                ->with('media')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Guide $g) => [
                    'id' => $g->id,
                    'category_id' => $g->category_id,
                    'title' => $g->title,
                    'title_gu' => $g->title_gu,
                    'title_hi' => $g->title_hi,
                    'title_en' => $g->title_en,
                    'summary' => $g->summary,
                    'summary_gu' => $g->summary_gu,
                    'summary_hi' => $g->summary_hi,
                    'summary_en' => $g->summary_en,
                    'body' => $g->body,
                    'body_gu' => $g->body_gu,
                    'body_hi' => $g->body_hi,
                    'body_en' => $g->body_en,
                    'cover_image' => $g->cover_image ? image_url($g->cover_image) : null,
                    'media' => $g->media
                        ->map(fn ($m) => [
                            'type' => $m->media_type,
                            'url' => $m->media_type === 'video'
                                ? $m->video_url
                                : ($m->image_path ? image_url($m->image_path) : null),
                        ])
                        ->filter(fn ($x) => $x['url'] !== null)
                        ->values(),
                ])
                ->values()
                ->all();

            return ['categories' => $categories, 'guides' => $guides];
        });
    }
}
