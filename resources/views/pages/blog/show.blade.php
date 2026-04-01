@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <a href="{{ route('blog.index') }}" class="hover:text-gold transition">બ્લૉગ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium line-clamp-1">{{ $post->title }}</span>
        </nav>
    </div>
</section>

<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple">

    {{-- Featured Image --}}
    @if($post->featured_image)
        <div class="rounded-2xl overflow-hidden mb-8 border border-amber-900/20">
            <img src="{{ Storage::url($post->featured_image) }}"
                 alt="{{ $post->title }}"
                 class="w-full h-72 sm:h-96 object-cover">
        </div>
    @endif

    <div class="card-sacred p-6 sm:p-10">

        {{-- Category + Date --}}
        <div class="flex flex-wrap items-center gap-3 mb-4">
            @if($post->category)
                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-amber-900/30 text-amber-400">
                    {{ is_object($post->category) ? $post->category->name : $post->category }}
                </span>
            @endif
            @if($post->published_at)
                <span class="text-xs text-amber-100/30">{{ $post->published_at->format('d M Y') }}</span>
            @endif
        </div>

        {{-- Title --}}
        <h1 class="divine-heading text-2xl sm:text-3xl mb-8 leading-tight">{{ $post->title }}</h1>

        {{-- Body --}}
        <div class="prose prose-invert prose-headings:text-gold prose-a:text-amber-500 max-w-none leading-relaxed text-amber-100/60">
            {!! $post->body !!}
        </div>

        {{-- Share Section --}}
        <div class="mt-10 pt-8 border-t border-amber-900/20">
            <p class="text-sm font-semibold text-amber-100/50 mb-3">શેર કરો:</p>
            <div class="flex flex-wrap gap-3">

                {{-- WhatsApp --}}
                <a href="https://api.whatsapp.com/send?text={{ urlencode($post->title . ' - ' . request()->url()) }}"
                   target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 px-4 py-2 bg-green-500 text-white text-sm font-medium rounded-lg hover:bg-green-600 transition">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.890-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    WhatsApp
                </a>

                {{-- Copy Link --}}
                <button onclick="navigator.clipboard.writeText(window.location.href).then(function(){ var b=this; b.textContent='Copied!'; setTimeout(function(){ b.textContent='Link copy karo'; },2000); }.bind(this))"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-amber-900/30 text-amber-100/60 text-sm font-medium rounded-lg hover:bg-amber-900/50 border border-amber-800/30 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    Link copy karo
                </button>

            </div>
        </div>

    </div>

    {{-- Back --}}
    <div class="mt-6">
        <a href="{{ route('blog.index') }}" class="inline-flex items-center gap-2 text-amber-500 hover:text-gold font-semibold transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16l-4-4m0 0l4-4m-4 4h18"/></svg>
            Badha blog posts
        </a>
    </div>

</div>

@endsection
