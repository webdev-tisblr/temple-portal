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

            {{-- ---- Hero: featured video, else the cover image ----
                 Rules (decided 2026-08-04): an uploaded cover image is always
                 honoured. With a video it becomes the pre-play poster; without
                 one it renders as-is (natural ratio, height-capped) above the
                 gallery. The gallery never suppresses the cover. --}}
            @php $cover = $project->image_path ? image_url($project->image_path) : null; @endphp
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
                            <x-yt-clean :url="$fv" :title="$project->title" :poster="$cover" class="absolute inset-0 w-full h-full" />
                        @else
                            <video class="absolute inset-0 w-full h-full" controls src="{{ $fv }}" @if($cover) poster="{{ $cover }}" @endif></video>
                        @endif
                    </div>
                </div>
            @elseif($cover)
                {{-- Cover image as-is: natural aspect ratio, capped height --}}
                <div class="card-sacred overflow-hidden">
                    <img src="{{ $cover }}"
                         alt="{{ $project->title }}"
                         class="mx-auto w-auto max-w-full max-h-[70vh]">
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
            @elseif(! $project->featured_video_url && ! $cover)
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
                    <p class="text-stone-700 leading-relaxed">{{ $project->description }}</p>
                </div>
            @endif

            {{-- ---- Writeup (Rich HTML) ----
                 Built on the palette, NOT on `text-amber-100/X`. The
                 compatibility layer in app.css remaps those bare utility
                 classes to dark ink, but a `prose-p:` variant compiles to a
                 different selector that the remap never matched — so the
                 writeup kept rendering cream-on-cream while the plain
                 paragraph above it looked fine. `prose-invert` was the other
                 half of it: inverted prose is for a dark scaffold, and this
                 card is #FFFCF5. --}}
            @if($project->writeup)
                <div class="card-sacred p-6 sm:p-8">
                    <div class="prose max-w-none
                                prose-headings:text-maroon-500 prose-headings:font-bold
                                prose-p:text-stone-700 prose-p:leading-relaxed
                                prose-a:text-maroon-500 prose-a:underline hover:prose-a:text-maroon-600
                                prose-strong:text-stone-700
                                prose-li:text-stone-700
                                prose-ul:text-stone-700 prose-ol:text-stone-700
                                prose-blockquote:text-stone-600 prose-blockquote:border-maroon-700/30
                                prose-img:rounded-xl prose-img:border prose-img:border-maroon-700/20">
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

            {{-- ---- Donor List ----
                 Two quiet text links, not a leaderboard: "Recent" is the
                 default (individual offerings, newest first); "Top donors"
                 swaps in the 10 donors whose offerings to this project add up
                 to the most. Both lists come from App\Support\CampaignDonors,
                 which masks Gupt Daan in Recent and keeps it out of Top. --}}
            @if($project->show_donor_list)
                <div id="donors" class="card-sacred p-6 sm:p-8 scroll-mt-24" x-data="donorList()">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-2 mb-5">
                        <h2 class="text-xl font-bold text-gold">{{ __('projects.donors') }}</h2>
                        <div class="sm:ml-auto flex items-center gap-2 text-xs">
                            <button type="button" @click="mode = 'recent'"
                                    :class="mode === 'recent' ? 'text-gold font-semibold' : 'text-amber-100/40 hover:text-amber-100/70'"
                                    class="transition">{{ __('projects.donors_recent') }}</button>
                            <span class="text-amber-100/20">·</span>
                            <button type="button" @click="mode = 'top'"
                                    :class="mode === 'top' ? 'text-gold font-semibold' : 'text-amber-100/40 hover:text-amber-100/70'"
                                    class="transition">{{ __('projects.donors_top') }}</button>
                        </div>
                    </div>

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
                                            <td class="py-3 px-2 text-right whitespace-nowrap">
                                                <span class="text-gold text-sm font-semibold" x-text="'₹' + Number(donor.amount).toLocaleString('en-IN')"></span>
                                                {{-- Top rows are a donor's offerings summed; say so quietly
                                                     when there was more than one, so the figure is not read
                                                     as a single very large gift. --}}
                                                <span x-show="mode === 'top' && donor.donation_count > 1"
                                                      class="text-amber-100/30 text-xs ml-1"
                                                      x-text="'· ' + donor.donation_count + ' ' + offeringTimes"></span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>

                            {{-- Only in Top: explains what the totals include, and that a
                                 Gupt Daan offering still counts for the project even though
                                 it is not listed here. --}}
                            <p x-show="mode === 'top'" class="mt-4 text-amber-100/30 text-xs leading-relaxed">
                                {{ __('projects.top_note') }}
                            </p>

                            {{-- Load More (Recent only — Top is a fixed 10) --}}
                            <div x-show="canLoadMore" class="mt-4 text-center">
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
        // 'recent' (paginated individual offerings, newest first)
        // | 'top' (fixed 10 donors, their offerings to this project summed)
        mode: 'recent',
        recentDonors: @json($donorsJs),
        topDonors: @json($topDonorsJs),
        nextPageUrl: @json($donorsNextUrl),
        // Suffix only, appended to a number ("5 offerings" / "5 વખત").
        offeringTimes: @json(__('projects.offering_times')),
        loading: false,

        get allDonors() {
            return this.mode === 'top' ? this.topDonors : this.recentDonors;
        },

        get canLoadMore() {
            return this.mode === 'recent' && !!this.nextPageUrl;
        },

        async loadMore() {
            if (!this.canLoadMore || this.loading) return;
            this.loading = true;
            try {
                const res = await fetch(this.nextPageUrl, {
                    headers: { 'Accept': 'application/json' }
                });
                const json = await res.json();
                const newDonors = json.data || [];
                this.recentDonors = [...this.recentDonors, ...newDonors];
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
