@php
    // Top announcement ribbon — configured on Admin → Website Display.
    $display = \Illuminate\Support\Facades\Cache::remember('site.display.v1', 300, function () {
        $keys = \App\Models\SystemSetting::query()
            ->where('key', 'like', 'site_%')
            ->pluck('value', 'key');

        return $keys->all();
    });

    $rbEnabled = ($display['site_ribbon_enabled'] ?? '') === '1';
    $now = now();
    $rbFrom = $display['site_ribbon_starts_at'] ?? '';
    $rbTo = $display['site_ribbon_ends_at'] ?? '';
    $rbLive = $rbEnabled
        && ($rbFrom === '' || $now->greaterThanOrEqualTo($rbFrom))
        && ($rbTo === '' || $now->lessThanOrEqualTo($rbTo));

    $locale = app()->getLocale();
    $rbText = $display["site_ribbon_text_{$locale}"] ?? '';
    $rbText = $rbText !== '' ? $rbText : ($display['site_ribbon_text_gu'] ?? '');
    $rbLink = $display['site_ribbon_link'] ?? '';
@endphp
@if($rbLive && $rbText !== '')
    {{-- Version the dismiss key by text hash: a NEW announcement re-appears
         even for visitors who dismissed the previous one. --}}
    <div x-data="{ open: localStorage.getItem('ribbon_{{ substr(sha1($rbText), 0, 8) }}') !== '1' }"
         x-show="open" x-cloak
         class="ribbon-ticker relative z-[60] text-sm font-semibold py-2"
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
                @click="open = false; localStorage.setItem('ribbon_{{ substr(sha1($rbText), 0, 8) }}', '1')"
                class="absolute right-0 top-0 bottom-0 px-3 opacity-70 hover:opacity-100"
                style="background:#7A1E1E;"
                aria-label="Close">✕</button>
    </div>
@endif
