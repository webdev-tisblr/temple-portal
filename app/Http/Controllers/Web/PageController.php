<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PageController extends Controller
{
    public function show(string $slug): View
    {
        $page = Page::where('slug', $slug)->where('status', 'published')->firstOrFail();

        SEOMeta::setTitle($page->meta_title ?? $page->title);
        SEOMeta::setDescription($page->meta_description ?? Str::limit(strip_tags($page->body), 160));

        return view('pages.page', compact('page'));
    }
}
