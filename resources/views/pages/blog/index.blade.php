@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">બ્લૉગ</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">બ્લૉગ</h1>
        <p class="mt-2 divine-subtext">Mandir na samachar, bhakti-lekho ane prasang-kathaao</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    @if($posts->isNotEmpty())
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            @foreach($posts as $post)
                <article class="card-sacred overflow-hidden flex flex-col">

                    {{-- Featured Image --}}
                    @if($post->featured_image_path)
                        <a href="{{ route('blog.show', $post->slug ?? $post) }}" class="block overflow-hidden">
                            <img src="{{ asset('storage/' . $post->featured_image_path) }}"
                                 alt="{{ $post->title }}"
                                 class="w-full h-48 object-cover hover:scale-105 transition duration-300">
                        </a>
                    @else
                        <div class="w-full h-48 bg-amber-900/20 flex items-center justify-center">
                            <svg class="w-10 h-10 text-amber-800/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                    @endif

                    <div class="p-5 flex flex-col flex-1">
                        {{-- Category Badge --}}
                        @if($post->category)
                            <span class="inline-block self-start px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-900/30 text-amber-400 mb-3">
                                {{ is_object($post->category) ? $post->category->name : $post->category }}
                            </span>
                        @endif

                        {{-- Title --}}
                        <h2 class="font-bold text-gold text-lg leading-tight mb-2">
                            <a href="{{ route('blog.show', $post->slug ?? $post) }}" class="hover:text-amber-300 transition">
                                {{ $post->title }}
                            </a>
                        </h2>

                        {{-- Excerpt --}}
                        <p class="text-sm text-amber-100/50 leading-relaxed flex-1 mb-4">
                            {{ $post->excerpt_gu ?? Str::limit(strip_tags($post->body ?? ''), 100) }}
                        </p>

                        {{-- Footer --}}
                        <div class="pt-3 border-t border-amber-900/15 flex items-center justify-between">
                            <span class="text-xs text-amber-100/30">
                                {{ optional($post->published_at)->format('d M Y') }}
                            </span>
                            <a href="{{ route('blog.show', $post->slug ?? $post) }}"
                               class="text-amber-500 hover:text-gold text-sm font-semibold flex items-center gap-1 transition">
                                વધુ વાંચો
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                            </a>
                        </div>
                    </div>

                </article>
            @endforeach
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $posts->links() }}
        </div>

    @else
        <div class="text-center py-20 card-sacred">
            <svg class="w-12 h-12 text-amber-800/40 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <p class="text-amber-100/30">Koi blog post available nathi.</p>
        </div>
    @endif

</div>

@endsection
