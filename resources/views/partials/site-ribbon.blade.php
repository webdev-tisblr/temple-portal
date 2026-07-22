{{-- Ribbon state ($rbLive/$rbText/$rbLink/$rbKey) is computed in the header's
     @php block — the header also renders a matching flow spacer, and variables
     set inside an include don't leak back to the parent scope. --}}
@if($rbLive && $rbText !== '')
    {{-- Version the dismiss key by text hash: a NEW announcement re-appears
         even for visitors who dismissed the previous one. h-9 locks the
         ribbon to the same height as the header's ribbon spacer. --}}
    <div x-data="{ open: localStorage.getItem('ribbon_{{ $rbKey }}') !== '1' }"
         x-show="open" x-cloak
         class="ribbon-ticker relative z-[60] h-9 text-sm font-semibold py-2"
         style="background:#7A1E1E; color:#FBF5EA;">
        {{-- The track is two identical halves; the animation translates by
             -50% so the second half seamlessly takes over. Each half repeats
             the message enough times to exceed any viewport width, so short
             messages scroll without a gap. Only the first copy is read aloud. --}}
        <div class="ribbon-track">
            @for ($i = 0; $i < 8; $i++)
                <span class="ribbon-item" @if($i > 0) aria-hidden="true" @endif>
                    @if($rbLink)
                        <a href="{{ $rbLink }}" class="hover:underline">{{ $rbText }}</a>
                    @else
                        {{ $rbText }}
                    @endif
                </span>
            @endfor
        </div>
        <button type="button"
                @click="open = false; localStorage.setItem('ribbon_{{ $rbKey }}', '1'); $dispatch('ribbon-dismissed')"
                class="absolute right-0 top-0 bottom-0 px-3 opacity-70 hover:opacity-100"
                style="background:#7A1E1E;"
                aria-label="Close">✕</button>
    </div>
@endif
