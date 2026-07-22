{{-- Reusable photo/video gallery. Expects:
       $media   — collection of media rows (media_type, image_path, video_url)
       $title   — string used for alt/iframe title
       $heading — optional section heading (already translated)
--}}
@if(($media ?? collect())->isNotEmpty())
    <div class="mt-10 pt-8 border-t border-amber-900/20">
        @if(!empty($heading))
            <p class="text-sm font-semibold text-amber-100/50 mb-4">{{ $heading }}</p>
        @endif
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            @foreach($media as $m)
                @if($m->media_type === 'video' && $m->video_url)
                    @php
                        $vurl = $m->video_url;
                        $isYt = str_contains($vurl, 'youtube.com') || str_contains($vurl, 'youtu.be');
                        $ytId = '';
                        if ($isYt) {
                            if (str_contains($vurl, 'youtu.be/')) { $ytId = explode('?', explode('youtu.be/', $vurl)[1] ?? '')[0]; }
                            elseif (preg_match('/[?&]v=([^&]+)/', $vurl, $mm)) { $ytId = $mm[1]; }
                        }
                    @endphp
                    <div class="col-span-2 sm:col-span-3 relative aspect-video rounded-xl overflow-hidden bg-black border border-amber-900/20">
                        @if($isYt && $ytId)
                            <iframe class="absolute inset-0 w-full h-full" src="https://www.youtube-nocookie.com/embed/{{ $ytId }}"
                                    title="{{ $title }}" frameborder="0" loading="lazy" allowfullscreen></iframe>
                        @else
                            <video class="absolute inset-0 w-full h-full" controls src="{{ $vurl }}"></video>
                        @endif
                    </div>
                @elseif($m->image_path)
                    <a href="{{ image_url($m->image_path) }}" target="_blank" rel="noopener"
                       class="block aspect-[4/3] rounded-xl overflow-hidden border border-amber-900/20">
                        <img src="{{ image_url($m->image_path) }}" alt="{{ $title }}" loading="lazy"
                             class="w-full h-full object-cover hover:scale-105 transition duration-300">
                    </a>
                @endif
            @endforeach
        </div>
    </div>
@endif
