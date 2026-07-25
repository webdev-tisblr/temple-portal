<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * Lookups go through findPublishedBySlug, which also matches slugs the
     * page used to have — renaming one in the CMS must not 404 the links
     * already in the wild (shared URLs, and the app, which addresses CMS
     * pages by slug).
     */
    public function show(string $slug): View|RedirectResponse
    {
        $page = Page::findPublishedBySlug($slug);

        abort_if($page === null, 404);

        // Reached via an old slug — send browsers to the canonical URL.
        if ($page->slug !== $slug) {
            return redirect()->route('page.show', $page->slug, 301);
        }

        SEOMeta::setTitle($page->meta_title ?? $page->title);
        SEOMeta::setDescription($page->meta_description ?? Str::limit(strip_tags($page->body), 160));

        return view('pages.page', compact('page'));
    }

    /**
     * Chrome-free version of a CMS page, styled for embedding in the mobile
     * app's WebView. Respects the ?lang query param via the locale middleware.
     *
     * Renders an old slug in place rather than redirecting — the WebView
     * would drop the ?lang query param across a redirect.
     */
    public function embed(string $slug): View
    {
        $page = Page::findPublishedBySlug($slug);

        abort_if($page === null, 404);

        return view('pages.page-embed', compact('page'));
    }
}
