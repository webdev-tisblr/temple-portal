@php
    // Announcement popup — configured on Admin → Website Display. Shows once
    // per visitor per day (localStorage date stamp). Shares the ribbon's
    // cached settings bag.
    $display = \Illuminate\Support\Facades\Cache::remember('site.display.v1', 300, function () {
        return \App\Models\SystemSetting::query()
            ->where('key', 'like', 'site_%')
            ->pluck('value', 'key')
            ->all();
    });

    $ppEnabled = ($display['site_popup_enabled'] ?? '') === '1';
    $now = now();
    $ppFrom = $display['site_popup_starts_at'] ?? '';
    $ppTo = $display['site_popup_ends_at'] ?? '';
    $ppLive = $ppEnabled
        && ($ppFrom === '' || $now->greaterThanOrEqualTo($ppFrom))
        && ($ppTo === '' || $now->lessThanOrEqualTo($ppTo));

    $locale = app()->getLocale();
    $pick = fn (string $base) => ($display["site_{$base}_{$locale}"] ?? '') !== ''
        ? $display["site_{$base}_{$locale}"]
        : ($display["site_{$base}_gu"] ?? '');

    $ppImage = $display['site_popup_image'] ?? '';
    $ppTitle = $pick('popup_title');
    $ppBody = $pick('popup_body');
    $ppCta = $pick('popup_cta_label');
    $ppCtaUrl = $display['site_popup_cta_url'] ?? '';
    $hasContent = $ppImage !== '' || $ppTitle !== '' || $ppBody !== '';
@endphp
@if($ppLive && $hasContent)
    <div x-data="{
            open: false,
            key: 'popup_{{ substr(sha1($ppImage . $ppTitle . $ppBody), 0, 8) }}',
            init() {
                const today = new Date().toISOString().slice(0, 10);
                if (localStorage.getItem(this.key) !== today) {
                    setTimeout(() => this.open = true, 1200);
                }
            },
            dismiss() {
                this.open = false;
                localStorage.setItem(this.key, new Date().toISOString().slice(0, 10));
            },
        }"
         x-show="open" x-cloak
         class="fixed inset-0 z-[90] flex items-center justify-center p-4"
         @keydown.escape.window="dismiss()">
        <div class="absolute inset-0 bg-black/60" @click="dismiss()"></div>
        <div class="relative max-w-md w-full rounded-2xl overflow-hidden shadow-2xl"
             style="background:#FBF5EA;"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 scale-90"
             x-transition:enter-end="opacity-100 scale-100">
            <button type="button" @click="dismiss()"
                    class="absolute right-3 top-3 z-10 w-8 h-8 rounded-full flex items-center justify-center bg-black/40 text-white hover:bg-black/60"
                    aria-label="Close">✕</button>
            @if($ppImage !== '')
                <img src="{{ image_url($ppImage) }}" alt="{{ $ppTitle ?: 'Announcement' }}" class="w-full h-auto">
            @endif
            @if($ppTitle !== '' || $ppBody !== '')
                <div class="p-6 text-center">
                    @if($ppTitle !== '')
                        <h3 class="text-xl font-bold" style="color:#7A1E1E;">{{ $ppTitle }}</h3>
                    @endif
                    @if($ppBody !== '')
                        <p class="mt-2 text-sm leading-relaxed" style="color:#5E4F3D;">{{ $ppBody }}</p>
                    @endif
                    @if($ppCta !== '' && $ppCtaUrl !== '')
                        <a href="{{ $ppCtaUrl }}"
                           class="inline-block mt-4 px-6 py-2.5 rounded-full text-sm font-bold text-white transition hover:opacity-90"
                           style="background:linear-gradient(90deg,#E8751A,#C89434);">{{ $ppCta }}</a>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
