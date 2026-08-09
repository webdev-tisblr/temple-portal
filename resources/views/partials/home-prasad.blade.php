{{--
    ORDER PRASAD — the homepage invitation band.

    Replaces the plain 4-up product grid that lived inline in home.blade.php
    (2026-08-05 → redesigned 2026-08-09). It reads as an invitation to
    RECEIVE prasad rather than a shop shelf: an invitation panel on the left
    (eyebrow · title · ornament · the three-step journey the prasad takes)
    and, on the right, the prasad itself presented as circular medallions on
    a gold ring — the same "soft medallion" language the hero highlight card
    already uses, so it sits naturally between the hall band and events.

    DATA CONTRACT — unchanged. Expects $prasadProducts, the array built in
    HomeController::index() under Cache key `home.prasad.v1`:
        ['id','slug','names'=>['gu','hi','en'],'image_path','display_price','in_stock']

    ⚠️ That cached payload is LOCALE-NEUTRAL and one entry serves gu/hi/en.
    Every localised string in this partial therefore comes from __() at
    render time, and the product name is resolved from the `names` bag here
    — never from the cache. Do NOT push localised copy into the controller's
    array without switching it to LocalizedCache::remember/forget first.

    ADMIN TOGGLE — unchanged. `site_prasad_enabled` makes the controller
    return [], the @if below hides the whole band; `site_prasad_product_ids`
    picks the products. Both busted by HomePageSettingsPage::save().
--}}
@if(!empty($prasadProducts))
    @php
        $prasadCount = count($prasadProducts);

        // Medallions want to stay large, so 4 products go 2×2 rather than
        // a thin 4-across row. Class strings are written out in full so
        // Tailwind's scanner sees them.
        $prasadGrid = match (true) {
            $prasadCount === 1 => 'grid grid-cols-1 max-w-xs mx-auto lg:mx-0',
            $prasadCount === 3 => 'grid grid-cols-2 sm:grid-cols-3 gap-4 sm:gap-5',
            default => 'grid grid-cols-2 gap-4 sm:gap-5',
        };

        // The journey a laddoo takes, in three beats. Localised here, never cached.
        $prasadSteps = [
            __('home.prasad_step_choose'),
            __('home.prasad_step_offered'),
            __('home.prasad_step_delivered'),
        ];
    @endphp

    <section class="relative overflow-hidden py-16 sm:py-20"
             style="background:linear-gradient(180deg,#F7F0E4 0%,#F4EAD5 48%,#F1E3C8 100%); border-top:1px solid #e9dfc8; border-bottom:1px solid #e9dfc8;">

        {{-- Two soft diya glows: warmth without an image request. Sized with
             inline styles rather than arbitrary Tailwind values so the band
             survives a stale CSS build. --}}
        <div class="absolute rounded-full pointer-events-none"
             style="top:-8rem; left:-7rem; width:420px; height:420px; background:radial-gradient(circle, rgba(232,117,26,.16), transparent 68%);"></div>
        <div class="absolute rounded-full pointer-events-none"
             style="bottom:-10rem; right:-7rem; width:460px; height:460px; background:radial-gradient(circle, rgba(200,148,52,.20), transparent 70%);"></div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            {{-- 1/3 invitation · 2/3 offerings on desktop, stacked below lg. --}}
            <div class="grid lg:grid-cols-3 gap-10 lg:gap-14 items-center">

                {{-- ── The invitation ──────────────────────────────────── --}}
                <div class="lg:col-span-1 text-center lg:text-left">
                    <div class="text-[11px] eyebrow" style="color:#C45F12;">{{ __('home.prasad_eyebrow') }}</div>
                    <h2 class="font-marcellus text-3xl sm:text-4xl mt-2.5 leading-snug" style="color:#7A1E1E;">
                        {{ __('home.prasad_title') }}
                    </h2>

                    {{-- Ornament rule (the divine-divider motif, inline so this
                         partial owns no CSS in app.css). --}}
                    <div class="flex items-center gap-2 mt-4 justify-center lg:justify-start">
                        <span class="w-10 h-px" style="background:linear-gradient(to right,transparent,#c49a2a);"></span>
                        <span class="w-1.5 h-1.5 rounded-full" style="background:#C45F12;"></span>
                        <span class="w-10 h-px" style="background:linear-gradient(to left,transparent,#c49a2a);"></span>
                    </div>

                    <p class="text-sm mt-4 leading-relaxed" style="color:#5E4F3D;">{{ __('home.prasad_sub') }}</p>

                    <ul class="mt-7 space-y-3.5 text-left inline-block lg:block">
                        @foreach($prasadSteps as $i => $stepLabel)
                            <li class="flex items-start gap-3">
                                <span class="flex-none w-7 h-7 rounded-full flex items-center justify-center text-[12px] font-extrabold mt-0.5"
                                      style="background:#FDF1E2; color:#C45F12; border:1px solid rgba(200,148,52,.45);"
                                      aria-hidden="true">{{ $i + 1 }}</span>
                                <span class="text-sm leading-relaxed" style="color:#5E4F3D;">{{ $stepLabel }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-8">
                        <a href="{{ route('store.index') }}"
                           class="inline-block px-7 py-3.5 rounded-full text-sm font-extrabold transition hover:opacity-90"
                           style="background:#E8751A; color:#FFF7EC; box-shadow:0 8px 20px rgba(196,95,18,.30);">
                            {{ __('home.prasad_cta') }}
                        </a>
                    </div>
                </div>

                {{-- ── The offerings ───────────────────────────────────── --}}
                <div class="lg:col-span-2">
                    <div class="{{ $prasadGrid }}">
                        @foreach($prasadProducts as $p)
                            @php
                                // Cached payload is locale-neutral; resolve here.
                                $pName = $p['names'][app()->getLocale()] ?? null ?: $p['names']['gu'];
                            @endphp
                            <a href="{{ route('store.product', $p['slug']) }}"
                               class="group relative flex flex-col items-center text-center rounded-3xl px-4 pt-7 pb-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl"
                               style="background:#FFFCF5; border:1px solid #ecdfc4; box-shadow:0 6px 20px rgba(90,50,15,.06);">

                                {{-- Medallion: the prasad on a gold-ringed thali. --}}
                                <span class="relative block w-28 h-28 sm:w-32 sm:h-32 rounded-full overflow-hidden"
                                      style="background:#FDF1E2; box-shadow:0 0 0 1px rgba(200,148,52,.5), 0 8px 20px rgba(90,50,15,.14);">
                                    @if($p['image_path'])
                                        <img src="{{ image_url($p['image_path']) }}" alt="{{ $pName }}" loading="lazy"
                                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105">
                                    @else
                                        <span class="block w-full h-full"
                                              style="background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px);"></span>
                                    @endif
                                </span>

                                <span class="font-marcellus text-lg sm:text-xl mt-4 leading-snug" style="color:#7A1E1E;">{{ $pName }}</span>
                                <span class="text-sm font-extrabold mt-1.5" style="color:#C45F12;">{{ $p['display_price'] }}</span>

                                @if($p['in_stock'])
                                    <span class="mt-4 inline-flex items-center gap-1.5 px-5 py-2 rounded-full text-xs font-extrabold transition group-hover:opacity-90"
                                          style="background:#E8751A; color:#FFF7EC;">{{ __('home.prasad_order') }} →</span>
                                @else
                                    <span class="mt-4 inline-flex items-center gap-1.5 px-5 py-2 rounded-full text-xs font-semibold"
                                          style="background:rgba(122,30,30,.07); color:#8B7355;">{{ __('store.out_of_stock') }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>

                    <div class="mt-6 text-center lg:text-right">
                        <a href="{{ route('store.index') }}" class="text-sm font-extrabold" style="color:#C45F12;">
                            {{ __('home.prasad_view_all') }} →
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </section>
@endif
