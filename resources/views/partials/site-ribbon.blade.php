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
         class="relative z-[60] text-center text-sm font-semibold px-10 py-2"
         style="background:#7A1E1E; color:#FBF5EA;">
        @if($rbLink)
            <a href="{{ $rbLink }}" class="hover:underline">{{ $rbText }}</a>
        @else
            {{ $rbText }}
        @endif
        <button type="button"
                @click="open = false; localStorage.setItem('ribbon_{{ substr(sha1($rbText), 0, 8) }}', '1')"
                class="absolute right-3 top-1/2 -translate-y-1/2 opacity-70 hover:opacity-100"
                aria-label="Close">✕</button>
    </div>
@endif
