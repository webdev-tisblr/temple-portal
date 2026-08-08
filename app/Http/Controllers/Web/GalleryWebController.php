<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GalleryCategory;
use App\Models\GalleryImage;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\View\View;

class GalleryWebController extends Controller
{
    public function index(): View
    {
        // The blade serialises ALL images into an Alpine array and filters by
        // category client-side, so true pagination would break the lightbox /
        // category tabs. Cap at 200 instead to bound the payload safely.
        $images = GalleryImage::with('categories')->orderBy('sort_order')->limit(200)->get();
        $categoryNames = $this->categoryNames();
        // Tabs follow the admin's category sort; slugs not in the managed
        // list (legacy/orphaned) are appended so their images stay reachable.
        $present = $images->flatMap(fn ($img) => $img->categories->pluck('slug')->all() ?: array_filter([$img->category]))->unique()->values();
        $categories = collect(array_keys($categoryNames))
            ->intersect($present)
            ->concat($present->diff(array_keys($categoryNames)))
            ->values();

        SEOMeta::setTitle('ફોટો ગેલેરી — શ્રી પાતાળિયા હનુમાનજી');

        return view('pages.gallery', compact('images', 'categories', 'categoryNames'));
    }

    public function category(string $category): View
    {
        $images = GalleryImage::with('categories')
            ->whereHas('categories', fn ($q) => $q->where('category_slug', $category))
            ->orderBy('sort_order')
            ->limit(200)
            ->get();
        // Was GalleryImage::distinct()->pluck('category') — distinct() with no
        // selected column is a no-op, and it missed secondary categories.
        $categories = GalleryCategory::orderBy('sort_order')->pluck('slug');
        $categoryNames = $this->categoryNames();

        SEOMeta::setTitle(($categoryNames[$category] ?? ucfirst($category)).' ગેલેરી — શ્રી પાતાળિયા હનુમાનજી');

        return view('pages.gallery', compact('images', 'categories', 'category', 'categoryNames'));
    }

    /** slug => localized display name, ordered by admin sort. */
    private function categoryNames(): array
    {
        return GalleryCategory::orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (GalleryCategory $c) => [$c->slug => $c->name])
            ->all();
    }
}
