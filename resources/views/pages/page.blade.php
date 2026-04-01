@extends('layouts.app')

@section('content')
<section class="bg-temple py-12">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="text-sm text-amber-100/30 mb-6 flex items-center justify-between">
            <div>
                <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
                <span class="mx-2">/</span>
                <span class="text-gold">{{ $page->title }}</span>
            </div>

            {{-- Language switcher — only shown on CMS pages with multilingual content --}}
            @if($page->title_hi || $page->title_en)
                @php $cl = app()->getLocale(); @endphp
                <div class="flex items-center gap-1 text-xs">
                    <a href="?lang=gu" class="px-2 py-1 rounded-md {{ $cl === 'gu' ? 'bg-amber-800/40 text-gold font-bold' : 'text-amber-100/40 hover:text-gold' }} transition">ગુજરાતી</a>
                    @if($page->title_hi)
                        <a href="?lang=hi" class="px-2 py-1 rounded-md {{ $cl === 'hi' ? 'bg-amber-800/40 text-gold font-bold' : 'text-amber-100/40 hover:text-gold' }} transition">हिन्दी</a>
                    @endif
                    @if($page->title_en)
                        <a href="?lang=en" class="px-2 py-1 rounded-md {{ $cl === 'en' ? 'bg-amber-800/40 text-gold font-bold' : 'text-amber-100/40 hover:text-gold' }} transition">English</a>
                    @endif
                </div>
            @endif
        </nav>

        @if($page->featured_image_path)
            <img src="{{ asset('storage/' . $page->featured_image_path) }}" alt="{{ $page->title }}" class="w-full rounded-2xl mb-8 shadow-lg border border-amber-900/20">
        @endif

        <h1 class="divine-heading text-3xl sm:text-4xl mb-6">{{ $page->title }}</h1>

        <div class="prose prose-lg prose-invert prose-headings:text-gold prose-a:text-amber-500 max-w-none">
            {!! $page->body !!}
        </div>
    </div>
</section>
@endsection
