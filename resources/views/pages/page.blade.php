@extends('layouts.app')

@section('content')
<section class="bg-temple py-8">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="mb-6">
            <x-breadcrumb :items="[['label' => $page->title]]" />
        </div>

        @if($page->featured_image_path)
            <img src="{{ image_url($page->featured_image_path) }}" alt="{{ $page->title }}" class="w-full rounded-2xl mb-8 shadow-lg border border-amber-900/20">
        @endif

        <h1 class="divine-heading text-3xl sm:text-4xl mb-6">{{ $page->title }}</h1>

        @if(!empty($page->blocks))
            <div class="max-w-none">
                @include('partials.blocks', ['blocks' => $page->blocks])
            </div>
        @else
            <div class="prose prose-lg prose-invert prose-headings:text-gold prose-a:text-amber-500 max-w-none">
                {!! $page->body !!}
            </div>
        @endif
    </div>
</section>
@endsection
