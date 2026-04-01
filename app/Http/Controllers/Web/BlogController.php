<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(): View
    {
        $posts = BlogPost::where('status', 'published')
            ->orderByDesc('published_at')
            ->paginate(10);

        SEOMeta::setTitle('બ્લૉગ — શ્રી પાતળિયા હનુમાનજી');

        return view('pages.blog.index', compact('posts'));
    }

    public function show(string $slug): View
    {
        $post = BlogPost::where('slug', $slug)->where('status', 'published')->firstOrFail();

        SEOMeta::setTitle($post->meta_title ?? $post->title);
        SEOMeta::setDescription($post->meta_description ?? \Illuminate\Support\Str::limit(strip_tags($post->body), 160));

        return view('pages.blog.show', compact('post'));
    }
}
