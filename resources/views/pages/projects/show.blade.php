@extends('layouts.app')

@section('content')
@php
    $raised = (float) $project->raised_amount;
    $goal = (float) $project->goal_amount;
    $pct = $goal > 0 ? min(100, round(($raised / $goal) * 100)) : 0;
    $isEnded = false; // Campaigns no longer have an end date — never auto-expire.
    $isGoalReached = $raised >= $goal && $goal > 0;
    $faqs = $project->faqs ?? [];
    $shareUrl = urlencode(request()->url());
    $shareTitle = urlencode($project->title);
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 bg-temple">

    <x-breadcrumb
        :items="[
            ['label' => __('footer.seva_projects'), 'url' => route('projects.index')],
            ['label' => $project->title],
        ]"
        class="mb-6" />

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

            {{-- ---- Featured Video ---- --}}
            @if($project->featured_video_url)
                @php $fv = $project->featured_video_url; @endphp
                <div class="card-sacred overflow-hidden">
                    {{-- Height-capped, ratio-driven box (see partials/media-gallery):
                         a vertical featured video renders portrait and centred. --}}
                    <div @if(youtube_video_id($fv)) data-yt-fit
                             style="aspect-ratio:var(--yt-ratio,{{ youtube_aspect_hint($fv) }});width:min(100%,calc(70vh*var(--yt-ratio,{{ youtube_aspect_hint($fv) }})))"
                         @endif
                         class="relative mx-auto @if(!youtube_video_id($fv)) aspect-video @endif bg-black">
                        @if(youtube_video_id($fv))
                            <x-yt-clean :url="$fv" :title="$project->title" class="absolute inset-0 w-full h-full" />
                        @else
                            <video class="absolute inset-0 w-full h-full" controls src="{{ $fv }}"></video>
                        @endif
                    </div>
                </div>
            @endif

            {{-- ---- Media Gallery — shared uniform-height slider partial ---- --}}
            @if(count($project->media ?? []) > 0)
                <div class="card-sacred overflow-hidden p-4">
                    @include('partials.media-gallery', [
                        'media' => $project->media,
                        'title' => $project->title,
                        'heading' => null,
                        'bare' => true,
                    ])
                </div>
            @elseif($project->image_path)
                {{-- Single Image --}}
                <div class="card-sacred overflow-hidden">
                    <div class="aspect-[4/3] bg-black/40">
                        <img src="{{ image_url($project->image_path) }}"
                             alt="{{ $project->title }}"
                             class="w-full h-full object-cover">
                    </div>
                </div>
            @else
                {{-- Placeholder --}}
                <div class="card-sacred overflow-hidden">
                    <div class="aspect-[4/3] flex items-center justify-center"
                         style="background: radial-gradient(ellipse at bottom, #F4EAD5, #FBF5EA);">
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
                    <h2 class="text-xl font-bold text-gold mb-5">{{ __('projects.faqs') }}</h2>
                    <div class="space-y-3">
                        @foreach($faqs as $index => $faq)
                            <div class="border border-amber-900/20 rounded-xl overflow-hidden">
                                <button @click="openFaq === {{ $index }} ? openFaq = null : openFaq = {{ $index }}"
                                        class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-amber-900/10 transition">
                                    <span class="font-semibold text-amber-100/80 pr-4">{{ $faq['question_'.app()->getLocale()] ?? ($faq['question_gu'] ?? ($faq['question'] ?? '')) }}</span>
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
                                        {{ $faq['answer_'.app()->getLocale()] ?? ($faq['answer_gu'] ?? ($faq['answer'] ?? '')) }}
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
                    <h2 class="text-xl font-bold text-gold mb-5">{{ __('projects.donors') }}</h2>

                    <template x-if="allDonors.length === 0 && !loading">
                        <p class="text-amber-100/40 text-sm py-4">{{ __('projects.no_donations') }}</p>
                    </template>

                    <template x-if="allDonors.length > 0">
                        <div>
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-amber-900/20">
                                        <th class="text-left py-3 px-2 text-sm font-semibold text-amber-600">{{ __('projects.name_city') }}</th>
                                        <th class="text-right py-3 px-2 text-sm font-semibold text-amber-600">{{ __('common.amount') }}</th>
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
                                    <span x-show="!loading">{{ __('projects.view_more') }}</span>
                                    <span x-show="loading" class="inline-flex items-center">
                                        <svg class="animate-spin h-4 w-4 mr-2" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                        </svg>
                                        {{ __('seva.loading') }}
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
