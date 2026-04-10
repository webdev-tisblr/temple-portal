@extends('layouts.app')

@section('content')
@php
    $raised = (float) $project->raised_amount;
    $goal = (float) $project->goal_amount;
    $pct = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
    $isEnded = $project->end_date && $project->end_date->isPast();
    $isGoalReached = $raised >= $goal && $goal > 0;
    $mediaItems = $project->media ?? [];
    $faqs = $project->faqs ?? [];
    $shareUrl = urlencode(request()->url());
    $shareTitle = urlencode($project->title);
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    {{-- Breadcrumb --}}
    <nav class="text-sm text-amber-100/30 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gold transition">મુખ્ય પૃષ્ઠ</a>
        <span class="mx-2">/</span>
        <a href="{{ route('projects.index') }}" class="hover:text-gold transition">સેવા પ્રોજેક્ટ્સ</a>
        <span class="mx-2">/</span>
        <span class="text-gold">{{ $project->title }}</span>
    </nav>

    {{-- Title (mobile) --}}
    <h1 class="divine-heading text-2xl sm:text-3xl mb-6 lg:hidden">{{ $project->title }}</h1>

    {{-- Two-Column Layout --}}
    <div class="lg:flex lg:gap-8">

        {{-- ========================================== --}}
        {{-- LEFT COLUMN (Content) --}}
        {{-- ========================================== --}}
        <div class="lg:w-2/3 space-y-8">

            {{-- Title (desktop) --}}
            <h1 class="divine-heading text-2xl sm:text-3xl hidden lg:block">{{ $project->title }}</h1>

            {{-- ---- Media Gallery ---- --}}
            @if(count($mediaItems) > 0)
                <div class="card-sacred overflow-hidden" x-data="projectGallery()">
                    {{-- Main Display --}}
                    <div class="relative aspect-video bg-black/40">
                        {{-- Image Slide --}}
                        <template x-if="currentItem.type === 'image'">
                            <img :src="currentItem.url" :alt="currentItem.caption || ''"
                                 class="w-full h-full object-contain">
                        </template>

                        {{-- Video Slide --}}
                        <template x-if="currentItem.type === 'video'">
                            <div class="w-full h-full flex items-center justify-center">
                                <template x-if="isYouTube(currentItem.url)">
                                    <iframe :src="getYouTubeEmbed(currentItem.url)"
                                            class="w-full h-full" frameborder="0"
                                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                            allowfullscreen></iframe>
                                </template>
                                <template x-if="!isYouTube(currentItem.url)">
                                    <video :src="currentItem.url" controls class="w-full h-full object-contain"></video>
                                </template>
                            </div>
                        </template>

                        {{-- Nav Buttons --}}
                        <template x-if="items.length > 1">
                            <div>
                                <button @click="prev()"
                                        class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/50 hover:bg-black/70 text-gold rounded-full flex items-center justify-center transition border border-amber-700/30">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                                <button @click="next()"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 bg-black/50 hover:bg-black/70 text-gold rounded-full flex items-center justify-center transition border border-amber-700/30">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                            </div>
                        </template>

                        {{-- Counter --}}
                        <div x-show="items.length > 1" class="absolute bottom-3 right-3 bg-black/60 text-amber-100/70 text-xs px-2.5 py-1 rounded-full">
                            <span x-text="currentIndex + 1"></span> / <span x-text="items.length"></span>
                        </div>
                    </div>

                    {{-- Thumbnail Strip --}}
                    <div x-show="items.length > 1" class="flex gap-2 p-3 overflow-x-auto">
                        <template x-for="(item, idx) in items" :key="idx">
                            <button @click="goTo(idx)"
                                    :class="currentIndex === idx ? 'ring-2 ring-amber-500 opacity-100' : 'opacity-50 hover:opacity-80'"
                                    class="flex-shrink-0 w-16 h-16 rounded-lg overflow-hidden bg-amber-900/20 transition">
                                <template x-if="item.type === 'image'">
                                    <img :src="item.thumbnail || item.url" class="w-full h-full object-cover">
                                </template>
                                <template x-if="item.type === 'video'">
                                    <div class="w-full h-full flex items-center justify-center bg-black/40">
                                        <svg class="w-6 h-6 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M6.3 2.841A1.5 1.5 0 004 4.11V15.89a1.5 1.5 0 002.3 1.269l9.344-5.89a1.5 1.5 0 000-2.538L6.3 2.84z"/></svg>
                                    </div>
                                </template>
                            </button>
                        </template>
                    </div>
                </div>
            @elseif($project->image_path)
                {{-- Single Image --}}
                <div class="card-sacred overflow-hidden">
                    <div class="aspect-video bg-black/40">
                        <img src="{{ asset('storage/' . $project->image_path) }}"
                             alt="{{ $project->title }}"
                             class="w-full h-full object-contain">
                    </div>
                </div>
            @else
                {{-- Placeholder --}}
                <div class="card-sacred overflow-hidden">
                    <div class="aspect-video flex items-center justify-center"
                         style="background: radial-gradient(ellipse at bottom, #2a1508, #0f0804);">
                        <svg class="w-20 h-20 text-amber-800/30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                  d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                </div>
            @endif

            {{-- ---- Progress Card (mobile only) ---- --}}
            <div class="lg:hidden">
                @include('pages.projects._sidebar', [
                    'project' => $project,
                    'raised' => $raised,
                    'goal' => $goal,
                    'pct' => $pct,
                    'isEnded' => $isEnded,
                    'isGoalReached' => $isGoalReached,
                    'shareUrl' => $shareUrl,
                    'shareTitle' => $shareTitle,
                ])
            </div>

            {{-- ---- Description ---- --}}
            @if($project->description)
                <div class="card-sacred p-6">
                    <p class="text-amber-100/60 leading-relaxed">{{ $project->description }}</p>
                </div>
            @endif

            {{-- ---- Writeup (Rich HTML) ---- --}}
            @if($project->writeup)
                <div class="card-sacred p-6 sm:p-8">
                    <div class="prose prose-invert prose-amber max-w-none
                                prose-headings:text-gold prose-headings:font-bold
                                prose-p:text-amber-100/60 prose-p:leading-relaxed
                                prose-a:text-amber-400 prose-a:underline hover:prose-a:text-amber-300
                                prose-strong:text-amber-100/80
                                prose-ul:text-amber-100/60 prose-ol:text-amber-100/60
                                prose-img:rounded-xl prose-img:border prose-img:border-amber-900/20">
                        {!! $project->writeup !!}
                    </div>
                </div>
            @endif

            {{-- ---- FAQs Accordion ---- --}}
            @if(count($faqs) > 0)
                <div class="card-sacred p-6 sm:p-8" x-data="{ openFaq: null }">
                    <h2 class="text-xl font-bold text-gold mb-5">વારંવાર પૂછાતા પ્રશ્નો</h2>
                    <div class="space-y-3">
                        @foreach($faqs as $index => $faq)
                            <div class="border border-amber-900/20 rounded-xl overflow-hidden">
                                <button @click="openFaq === {{ $index }} ? openFaq = null : openFaq = {{ $index }}"
                                        class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-amber-900/10 transition">
                                    <span class="font-semibold text-amber-100/80 pr-4">{{ $faq['question'] ?? '' }}</span>
                                    <svg class="w-5 h-5 text-amber-600 flex-shrink-0 transition-transform duration-200"
                                         :class="openFaq === {{ $index }} && 'rotate-180'"
                                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>
                                <div x-show="openFaq === {{ $index }}"
                                     x-collapse
                                     x-cloak>
                                    <div class="px-5 pb-4 text-amber-100/50 leading-relaxed text-sm border-t border-amber-900/15 pt-3">
                                        {{ $faq['answer'] ?? '' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ---- Donor List ---- --}}
            @if($project->show_donor_list)
                <div class="card-sacred p-6 sm:p-8" x-data="donorList()">
                    <h2 class="text-xl font-bold text-gold mb-5">દાનકર્તાઓ</h2>

                    <template x-if="allDonors.length === 0 && !loading">
                        <p class="text-amber-100/40 text-sm py-4">હજુ સુધી કોઈ દાન નથી</p>
                    </template>

                    <template x-if="allDonors.length > 0">
                        <div>
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-amber-900/20">
                                        <th class="text-left py-3 px-2 text-sm font-semibold text-amber-600">નામ &amp; શહેર</th>
                                        <th class="text-right py-3 px-2 text-sm font-semibold text-amber-600">રકમ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(donor, idx) in allDonors" :key="idx">
                                        <tr class="border-b border-amber-900/10 last:border-0">
                                            <td class="py-3 px-2">
                                                <span class="text-amber-100/70 text-sm font-medium" x-text="donor.name"></span>
                                                <span x-show="donor.city" class="text-amber-100/30 text-xs ml-1" x-text="'(' + donor.city + ')'"></span>
                                            </td>
                                            <td class="py-3 px-2 text-right">
                                                <span class="text-gold text-sm font-semibold" x-text="'₹' + Number(donor.amount).toLocaleString('en-IN')"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            {{-- Load More --}}
                            <div x-show="nextPageUrl" class="mt-4 text-center">
                                <button @click="loadMore()"
                                        :disabled="loading"
                                        class="px-6 py-2 border border-amber-800/30 rounded-lg text-sm font-medium text-amber-400 hover:border-amber-600 hover:text-amber-300 transition disabled:opacity-40">
                                    <span x-show="!loading">વધુ જુઓ</span>
                                    <span x-show="loading" class="inline-flex items-center">
                                        <svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                        લોડ થઈ રહ્યું છે...
                                    </span>
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            @endif

        </div>

        {{-- ========================================== --}}
        {{-- RIGHT COLUMN (Sticky Sidebar) --}}
        {{-- ========================================== --}}
        <div class="hidden lg:block lg:w-1/3">
            @include('pages.projects._sidebar', [
                'project' => $project,
                'raised' => $raised,
                'goal' => $goal,
                'pct' => $pct,
                'isEnded' => $isEnded,
                'isGoalReached' => $isGoalReached,
                'shareUrl' => $shareUrl,
                'shareTitle' => $shareTitle,
            ])
        </div>

    </div>
</div>

@push('scripts')
<script>
function projectGallery() {
    const rawMedia = @json($mediaItems);
    const items = rawMedia.map(item => ({
        type: item.type || 'image',
        url: item.url || '',
        thumbnail: item.thumbnail || '',
        caption: item.caption || '',
    }));

    return {
        items: items,
        currentIndex: 0,
        get currentItem() {
            return this.items[this.currentIndex] || { type: 'image', url: '', caption: '' };
        },
        prev() {
            this.currentIndex = (this.currentIndex - 1 + this.items.length) % this.items.length;
        },
        next() {
            this.currentIndex = (this.currentIndex + 1) % this.items.length;
        },
        goTo(idx) {
            this.currentIndex = idx;
        },
        isYouTube(url) {
            return url && (url.includes('youtube.com') || url.includes('youtu.be'));
        },
        getYouTubeEmbed(url) {
            if (!url) return '';
            let videoId = '';
            if (url.includes('youtu.be/')) {
                videoId = url.split('youtu.be/')[1]?.split(/[?&]/)[0];
            } else {
                const match = url.match(/[?&]v=([^&]+)/);
                videoId = match ? match[1] : '';
            }
            return videoId ? `https://www.youtube-nocookie.com/embed/${videoId}` : url;
        }
    };
}

function donorList() {
    return {
        allDonors: @json($donorsJs),
        nextPageUrl: @json($donorsNextUrl),
        loading: false,

        async loadMore() {
            if (!this.nextPageUrl || this.loading) return;
            this.loading = true;
            try {
                const res = await fetch(this.nextPageUrl, {
                    headers: { 'Accept': 'application/json' }
                });
                const json = await res.json();
                const newDonors = json.data || [];
                this.allDonors = [...this.allDonors, ...newDonors];
                this.nextPageUrl = json.next_page_url || null;
            } catch (e) {
                console.error('Failed to load donors', e);
            }
            this.loading = false;
        }
    };
}
</script>
@endpush
@endsection
