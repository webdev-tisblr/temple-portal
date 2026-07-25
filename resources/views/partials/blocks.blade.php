{{-- Renders block-based page content. Expects $blocks = array of {type, data}. --}}
@foreach($blocks as $block)
    @php
        $type = $block['type'] ?? '';
        $data = $block['data'] ?? [];
    @endphp
    @switch($type)
        @case('heading')
            @if(($data['level'] ?? 'h2') === 'h3')
                <h3 class="text-xl sm:text-2xl font-bold text-gold mt-6 mb-2">{{ $data['text'] ?? '' }}</h3>
            @else
                <h2 class="text-2xl sm:text-3xl font-bold text-gold mt-8 mb-3">{{ $data['text'] ?? '' }}</h2>
            @endif
            @break

        @case('paragraph')
            <div class="prose prose-invert prose-a:text-amber-500 max-w-none text-amber-100/70 leading-relaxed mb-4">{!! $data['html'] ?? '' !!}</div>
            @break

        @case('image')
            @if(!empty($data['path']))
                <figure class="my-6">
                    <img src="{{ image_url($data['path']) }}" alt="{{ $data['caption'] ?? '' }}" class="w-full rounded-2xl border border-amber-900/20" loading="lazy">
                    @if(!empty($data['caption']))
                        <figcaption class="text-center text-sm text-amber-100/40 mt-2">{{ $data['caption'] }}</figcaption>
                    @endif
                </figure>
            @endif
            @break

        @case('list')
            <ul class="list-disc list-inside space-y-1 text-amber-100/70 mb-4 pl-2">
                @foreach(($data['items'] ?? []) as $item)
                    <li>{{ is_array($item) ? ($item['item'] ?? '') : $item }}</li>
                @endforeach
            </ul>
            @break

        @case('quote')
            <blockquote class="border-l-4 border-amber-600/50 pl-4 italic text-amber-100/60 my-6">{{ $data['text'] ?? '' }}</blockquote>
            @break

        @case('video')
            @php $vurl = $data['url'] ?? ''; @endphp
            @if($vurl)
                {{-- Ratio comes from the video (clean-youtube.js sets --yt-ratio), and
                     the width is capped by a 70vh height so a vertical clip becomes a
                     centred portrait frame instead of a page-wide, page-tall one. --}}
                <div @if(youtube_video_id($vurl)) data-yt-fit
                         style="aspect-ratio:var(--yt-ratio,{{ youtube_aspect_hint($vurl) }});width:min(100%,calc(70vh*var(--yt-ratio,{{ youtube_aspect_hint($vurl) }})))"
                     @endif
                     class="my-6 mx-auto relative @if(!youtube_video_id($vurl)) aspect-video @endif rounded-2xl overflow-hidden bg-black border border-amber-900/20">
                    @if(youtube_video_id($vurl))
                        <x-yt-clean :url="$vurl" class="absolute inset-0 w-full h-full" />
                    @else
                        <video class="absolute inset-0 w-full h-full" controls src="{{ $vurl }}"></video>
                    @endif
                </div>
            @endif
            @break
    @endswitch
@endforeach
