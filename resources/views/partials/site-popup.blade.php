@php
    // Announcement popup — configured on Admin → Home Page Settings → Popup.
    // One or more slides show as an auto-advancing carousel in a single modal.
    // Re-shows after the admin-set cooldown (0 days = every visit).
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

    $cooldownDays = (int) ($display['site_popup_cooldown_days'] ?? 1);

    $locale = app()->getLocale();
    $rawSlides = json_decode($display['site_popup_slides'] ?? '[]', true);
    $rawSlides = is_array($rawSlides) ? $rawSlides : [];

    // Resolve each slide to the active locale (fall back to Gujarati) and
    // drop any slide with no visible content.
    $pick = function (array $s, string $base) use ($locale) {
        return ($s["{$base}_{$locale}"] ?? '') !== '' ? $s["{$base}_{$locale}"] : ($s["{$base}_gu"] ?? '');
    };
    $slides = [];
    foreach ($rawSlides as $s) {
        if (! is_array($s)) continue;
        $image = $s['image'] ?? '';
        $title = $pick($s, 'title');
        $body = $pick($s, 'body');
        $cta = $pick($s, 'cta_label');
        $ctaUrl = $s['cta_url'] ?? '';
        if ($image === '' && $title === '' && $body === '') continue;
        $slides[] = [
            'image' => $image !== '' ? image_url($image) : '',
            'title' => $title,
            'body' => $body,
            'cta' => $cta,
            'cta_url' => $ctaUrl,
        ];
    }

    // Version key so editing the popup re-shows it even within a cooldown.
    $ppKey = 'sph_popup_' . substr(sha1(json_encode($slides)), 0, 8);
@endphp
@if($ppLive && count($slides) > 0)
    <div x-data="{
            open: false,
            i: 0,
            n: {{ count($slides) }},
            timer: null,
            cooldownDays: {{ $cooldownDays }},
            key: '{{ $ppKey }}',
            init() {
                if (this.cooldownDays > 0) {
                    const until = parseInt(localStorage.getItem(this.key) || '0', 10);
                    if (Date.now() < until) return;
                }
                setTimeout(() => { this.open = true; this.arm(); }, 1200);
            },
            arm() {
                clearTimeout(this.timer);
                if (this.n > 1) this.timer = setTimeout(() => this.go(this.i + 1), 5000);
            },
            go(k) { this.i = (k + this.n) % this.n; this.arm(); },
            dismiss() {
                this.open = false;
                clearTimeout(this.timer);
                if (this.cooldownDays > 0) {
                    localStorage.setItem(this.key, String(Date.now() + this.cooldownDays * 24 * 60 * 60 * 1000));
                }
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
                    class="absolute right-3 top-3 z-20 w-8 h-8 rounded-full flex items-center justify-center bg-black/40 text-white hover:bg-black/60"
                    aria-label="Close">✕</button>

            {{-- Slides track --}}
            <div class="relative overflow-hidden">
                <div class="flex transition-transform duration-500 ease-out"
                     :style="'transform: translateX(-' + (i * 100) + '%)'">
                    @foreach($slides as $slide)
                        <div class="w-full flex-shrink-0">
                            @if($slide['image'] !== '')
                                <img src="{{ $slide['image'] }}" alt="{{ $slide['title'] ?: 'Announcement' }}" class="w-full h-auto">
                            @endif
                            @if($slide['title'] !== '' || $slide['body'] !== '')
                                <div class="p-6 text-center">
                                    @if($slide['title'] !== '')
                                        <h3 class="text-xl font-bold" style="color:#7A1E1E;">{{ $slide['title'] }}</h3>
                                    @endif
                                    @if($slide['body'] !== '')
                                        <p class="mt-2 text-sm leading-relaxed" style="color:#5E4F3D;">{{ $slide['body'] }}</p>
                                    @endif
                                    @if($slide['cta'] !== '' && $slide['cta_url'] !== '')
                                        <a href="{{ $slide['cta_url'] }}"
                                           class="inline-block mt-4 px-6 py-2.5 rounded-full text-sm font-bold text-white transition hover:opacity-90"
                                           style="background:linear-gradient(90deg,#E8751A,#C89434);">{{ $slide['cta'] }}</a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            @if(count($slides) > 1)
                {{-- Prev / next --}}
                <button type="button" @click="go(i - 1)"
                        class="absolute left-2 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-black/40 hover:bg-black/60 text-white flex items-center justify-center"
                        aria-label="Previous">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button type="button" @click="go(i + 1)"
                        class="absolute right-2 top-1/2 -translate-y-1/2 z-10 w-9 h-9 rounded-full bg-black/40 hover:bg-black/60 text-white flex items-center justify-center"
                        aria-label="Next">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
                {{-- Dots --}}
                <div class="absolute bottom-3 left-0 right-0 flex justify-center gap-2 z-10">
                    @foreach($slides as $k => $slide)
                        <button type="button" @click="go({{ $k }})"
                                class="w-2 h-2 rounded-full transition-all"
                                :class="i === {{ $k }} ? 'w-5 bg-white' : 'bg-white/50'"></button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
