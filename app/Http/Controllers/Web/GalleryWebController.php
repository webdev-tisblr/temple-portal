<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
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
        $images = GalleryImage::orderBy('sort_order')->limit(200)->get();
        $categories = $images->pluck('category')->unique()->values();

        SEOMeta::setTitle('ફોટો ગેલેરી — શ્રી પાતાળિયા હનુમાનજી');

        return view('pages.gallery', compact('images', 'categories'));
    }

    public function category(string $category): View
    {
        $images = GalleryImage::where('category', $category)->orderBy('sort_order')->limit(200)->get();
        $categories = GalleryImage::distinct()->pluck('category');

        SEOMeta::setTitle(ucfirst($category) . ' ગેલેરી — શ્રી પાતાળિયા હનુમાનજી');

        return view('pages.gallery', compact('images', 'categories', 'category'));
    }
}
