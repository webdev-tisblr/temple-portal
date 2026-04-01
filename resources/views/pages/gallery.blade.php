@extends('layouts.app')

@section('content')

<section class="bg-temple-light border-b border-amber-900/20">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <nav class="flex items-center gap-2 text-sm text-amber-100/30 mb-4">
            <a href="{{ route('home') }}" class="hover:text-gold transition">હોમ</a>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gold font-medium">ફોટો ગેલેરી</span>
        </nav>
        <h1 class="divine-heading text-3xl sm:text-4xl">ફોટો ગેલેરી</h1>
        <p class="mt-2 divine-subtext">શ્રી પાતળિયા હનુમાનજી ધામ — Photos ane Videos</p>
    </div>
</section>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 bg-temple"
     x-data="{
        activeCategory: 'all',
        lightboxOpen: false,
        currentIndex: 0,
        images: @js($images->map(fn($img) => ['src' => Storage::url($img->image_path), 'title' => $img->title, 'category' => $img->category])->values()),
        get filtered() {
            if (this.activeCategory === 'all') return this.images;
            return this.images.filter(i => i.category === this.activeCategory);
        },
        openLightbox(index) {
            this.currentIndex = index;
            this.lightboxOpen = true;
            document.body.style.overflow = 'hidden';
        },
        closeLightbox() {
            this.lightboxOpen = false;
            document.body.style.overflow = '';
        },
        prev() {
            this.currentIndex = (this.currentIndex - 1 + this.filtered.length) % this.filtered.length;
        },
        next() {
            this.currentIndex = (this.currentIndex + 1) % this.filtered.length;
        }
     }"
     @keydown.escape.window="closeLightbox()"
     @keydown.arrow-left.window="lightboxOpen && prev()"
     @keydown.arrow-right.window="lightboxOpen && next()">

    {{-- Category Filter Tabs --}}
    @if(isset($categories) && $categories->isNotEmpty())
    <div class="flex flex-wrap gap-2 mb-8">
        <button @click="activeCategory = 'all'"
                :class="activeCategory === 'all' ? 'bg-gold text-stone-900 font-bold' : 'bg-transparent text-amber-100/50 border border-amber-800/30 hover:border-amber-600'"
                class="px-4 py-1.5 rounded-full text-sm font-medium transition">
            Badhu
        </button>
        @foreach($categories as $cat)
        <button @click="activeCategory = '{{ $cat }}'"
                :class="activeCategory === '{{ $cat }}' ? 'bg-gold text-stone-900 font-bold' : 'bg-transparent text-amber-100/50 border border-amber-800/30 hover:border-amber-600'"
                class="px-4 py-1.5 rounded-full text-sm font-medium transition capitalize">
            {{ $cat }}
        </button>
        @endforeach
    </div>
    @endif

    {{-- Image Grid --}}
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 sm:gap-4">
        <template x-for="(img, index) in filtered" :key="index">
            <div class="relative group cursor-pointer overflow-hidden rounded-xl bg-amber-900/20 aspect-square border border-amber-900/15"
                 @click="openLightbox(index)">
                <img :src="img.src" :alt="img.title"
                     class="w-full h-full object-cover transition duration-300 group-hover:scale-105">
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition duration-300 flex items-center justify-center">
                    <svg class="w-8 h-8 text-gold opacity-0 group-hover:opacity-100 transition duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/>
                    </svg>
                </div>
                <div class="absolute bottom-0 left-0 right-0 p-2 bg-gradient-to-t from-black/70 to-transparent opacity-0 group-hover:opacity-100 transition">
                    <p class="text-amber-100/80 text-xs font-medium line-clamp-1" x-text="img.title"></p>
                </div>
            </div>
        </template>
    </div>

    {{-- Empty State --}}
    <template x-if="filtered.length === 0">
        <div class="text-center py-20">
            <svg class="w-12 h-12 text-amber-800/40 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
            <p class="text-amber-100/30">Is category ma koi photo nathi.</p>
        </div>
    </template>

    {{-- Lightbox --}}
    <div x-show="lightboxOpen"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 bg-black/95 flex items-center justify-center p-4"
         @click.self="closeLightbox()">

        {{-- Close Button --}}
        <button @click="closeLightbox()"
                class="absolute top-4 right-4 w-10 h-10 bg-amber-900/40 hover:bg-amber-800/60 text-gold rounded-full flex items-center justify-center transition z-10 border border-amber-700/40">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        {{-- Prev Button --}}
        <button @click.stop="prev()"
                x-show="filtered.length > 1"
                class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-amber-900/40 hover:bg-amber-800/60 text-gold rounded-full flex items-center justify-center transition z-10 border border-amber-700/40">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </button>

        {{-- Next Button --}}
        <button @click.stop="next()"
                x-show="filtered.length > 1"
                class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 bg-amber-900/40 hover:bg-amber-800/60 text-gold rounded-full flex items-center justify-center transition z-10 border border-amber-700/40">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>

        {{-- Image --}}
        <div class="max-w-4xl max-h-full flex flex-col items-center gap-3">
            <img :src="filtered[currentIndex]?.src"
                 :alt="filtered[currentIndex]?.title"
                 class="max-h-[80vh] max-w-full object-contain rounded-lg shadow-2xl">
            <p class="text-amber-100/70 text-sm" x-text="filtered[currentIndex]?.title"></p>
            <p class="text-amber-100/30 text-xs" x-text="(currentIndex + 1) + ' / ' + filtered.length"></p>
        </div>

    </div>

</div>

@endsection
