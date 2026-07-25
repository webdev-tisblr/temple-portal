{{-- Reusable photo/video gallery — single horizontal slider.

     Photos and videos ride in ONE strip in admin sort order. Every slide is
     the same height; width follows the item's natural aspect ratio (images
     and uploaded videos: h-full + w-auto; YouTube: h-full + an aspect-ratio
     that clean-youtube.js resolves from the video itself, so Shorts get a
     9:16 slot). YouTube videos mount the chromeless <x-yt-clean> player
     inline (poster + play button until tapped); uploaded files use a
     native <video>.

     Accepts BOTH shapes:
       • model rows   — media_type ('photo'|'video'), image_path, video_url
       • plain arrays — type ('image'|'video'), url   (campaign JSON media)

     Expects:
       $media   — collection/array of either shape
       $title   — string used for alt/iframe title
       $heading — optional section heading (already translated)
--}}
@php
    $galleryItems = collect($media ?? [])->map(function ($m) {
        if (is_array($m)) {
            // Campaign JSON media: {url, type: image|video}. Uploaded video
            // files and images both live in `url` (absolute or R2 key).
            $src = image_url($m['url'] ?? '');
            if (! $src) return null;
            return ($m['type'] ?? 'image') === 'video'
                ? ['kind' => 'video', 'src' => $src]
                : ['kind' => 'photo', 'src' => $src];
        }

        if (($m->media_type ?? 'photo') === 'video' && ($m->video_url ?? null)) {
            return ['kind' => 'video', 'src' => $m->video_url];
        }
        if ($m->image_path ?? null) {
            return ['kind' => 'photo', 'src' => image_url($m->image_path)];
        }

        return null;
    })->filter()->values();
@endphp

@if($galleryItems->isNotEmpty())
    {{-- $bare skips the section spacing/border for callers embedding the
         strip inside their own card (e.g. campaign detail). --}}
    <div class="{{ ($bare ?? false) ? '' : 'mt-10 pt-8 border-t border-amber-900/20' }}">
        @if(!empty($heading))
            <p class="text-sm font-semibold text-amber-100/50 mb-4">{{ $heading }}</p>
        @endif

        <div class="relative" x-data="{
                scroll(dir) { this.$refs.strip.scrollBy({ left: dir * this.$refs.strip.clientWidth * 0.8, behavior: 'smooth' }); }
             }">
            {{-- The strip: uniform height, natural widths, snap scrolling. --}}
            <div x-ref="strip"
                 class="media-strip flex gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory pb-2 h-52 sm:h-64">
                @foreach($galleryItems as $item)
                    @if($item['kind'] === 'video' && youtube_video_id($item['src']))
                        {{-- YouTube iframes have no intrinsic ratio, so the box starts on
                             the URL hint (9:16 for /shorts/, else 16:9) and data-yt-fit
                             lets clean-youtube.js correct it to the real one once it has
                             probed the thumbnail. h-full + the ratio ⇒ natural width, so
                             a vertical video sits in a vertical slot like the photos do
                             instead of being pillarboxed inside a 16:9 slot. --}}
                        <div data-yt-fit
                             style="aspect-ratio:var(--yt-ratio,{{ youtube_aspect_hint($item['src']) }})"
                             class="h-full flex-shrink-0 snap-start relative rounded-xl overflow-hidden bg-black border border-amber-900/20">
                            <x-yt-clean :url="$item['src']" :title="$title ?? ''" class="absolute inset-0 w-full h-full" />
                        </div>
                    @elseif($item['kind'] === 'video')
                        {{-- Uploaded files size to their own ratio (h-full + w-auto),
                             so portrait videos don't get 16:9 pillarboxing. --}}
                        <video class="h-full w-auto max-w-none flex-shrink-0 snap-start rounded-xl bg-black border border-amber-900/20"
                               controls preload="metadata" src="{{ $item['src'] }}"></video>
                    @else
                        <a href="{{ $item['src'] }}" target="_blank" rel="noopener"
                           class="h-full flex-shrink-0 snap-start rounded-xl overflow-hidden border border-amber-900/20 bg-black/30">
                            {{-- h-full + w-auto keeps the image's natural aspect ratio at the strip height. --}}
                            <img src="{{ $item['src'] }}" alt="{{ $title ?? '' }}" loading="lazy"
                                 class="h-full w-auto max-w-none hover:opacity-90 transition">
                        </a>
                    @endif
                @endforeach
            </div>

            {{-- Desktop arrows (hidden when everything fits / on touch). --}}
            @if($galleryItems->count() > 1)
                <button type="button" @click="scroll(-1)"
                        class="hidden sm:flex absolute left-2 top-1/2 -translate-y-1/2 w-9 h-9 bg-black/55 hover:bg-black/75 text-gold rounded-full items-center justify-center border border-amber-700/30 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="scroll(1)"
                        class="hidden sm:flex absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 bg-black/55 hover:bg-black/75 text-gold rounded-full items-center justify-center border border-amber-700/30 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            @endif
        </div>
    </div>
@endif
