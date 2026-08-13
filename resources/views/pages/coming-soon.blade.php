@php
    use App\Models\SystemSetting;
    $trustName    = SystemSetting::getLocalized('trust_name', null, 'શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
    $trustPhone   = SystemSetting::getValue('trust_phone');
    $trustEmail   = SystemSetting::getValue('trust_email');
    // Passed by ComingSoonMode. Sent to the browser WITH its offset so the
    // countdown is computed against a real instant, not a wall-clock string
    // a visitor in another timezone would read differently.
    $launchIso    = ($launchAt ?? null)?->toIso8601String();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="google" content="notranslate">
    <title>{{ __('comingsoon.soon') }} — {{ $trustName }}</title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#FBF5EA">

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Gujarati:wght@400;500;600;700;900&family=Hind+Vadodara:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased" style="background: #FBF5EA;">

<main class="min-h-screen flex items-center justify-center px-4 py-16 relative overflow-hidden">

    {{-- Soft saffron glow at the center, like the home hero --}}
    <div class="absolute inset-0 pointer-events-none"
         style="background: radial-gradient(ellipse at center 35%, rgba(232,117,26,0.10) 0%, transparent 60%);"></div>

    <div class="relative z-10 max-w-2xl mx-auto text-center">

        {{-- Trust logo --}}
        <div class="flex justify-center mb-6">
            <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}"
                 alt="{{ $trustName }}"
                 class="w-28 h-28 rounded-full diya-glow object-cover"
                 style="border: 2px solid rgba(200,148,52,0.55); box-shadow: 0 6px 24px rgba(200,148,52,0.30);">
        </div>

        {{-- Sacred badge --}}
        <div class="inline-flex items-center gap-2 px-5 py-2 rounded-full border mb-6"
             style="background: rgba(232,117,26,0.10); border-color: rgba(200,148,52,0.45);">
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
            <span class="text-sm eyebrow" style="color: #7A1E1E;">{{ __('home.hero_jai') }}</span>
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
        </div>

        {{-- Main heading --}}
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black leading-[1.1] tracking-tight">
            <span style="color: #7A1E1E;">{{ __('comingsoon.soon') }}</span><br>
            <span style="color: #C45F12;">{{ __('comingsoon.arriving') }}</span>
        </h1>

        <p class="mt-5 text-lg" style="color: #5E4F3D;">
            {{ $trustName }}{{ __('comingsoon.body1_suffix') }}<br class="hidden sm:block">
            {{ __('comingsoon.body2') }}
        </p>

        @if($launchIso)
            {{-- Countdown. Server-rendered zeroes so it never flashes empty,
                 then driven by the browser clock against a fixed instant. --}}
            <div class="mt-8" id="countdown" data-launch="{{ $launchIso }}">
                <p class="eyebrow text-sm mb-3" style="color:#7A1E1E;">{{ __('comingsoon.countdown_title') }}</p>
                <div class="flex items-start justify-center gap-2 sm:gap-4">
                    @foreach(['days','hours','minutes','seconds'] as $unit)
                        <div class="flex flex-col items-center">
                            <div class="rounded-xl px-3 sm:px-5 py-3 sm:py-4 min-w-[64px] sm:min-w-[86px]"
                                 style="background: rgba(122,30,30,0.06); border: 1px solid rgba(200,148,52,0.45);">
                                <span class="block text-3xl sm:text-5xl font-black tabular-nums leading-none"
                                      style="color:#7A1E1E;" data-cd="{{ $unit }}">00</span>
                            </div>
                            <span class="mt-2 text-[11px] sm:text-xs" style="color:#5E4F3D;">{{ __('comingsoon.'.$unit) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Ornamental divider --}}
        <div class="divine-divider my-7">
            <span class="text-xs" style="color: #C89434;">✦</span>
        </div>

        {{-- Contact (optional, only if available) --}}
        @if($trustPhone || $trustEmail)
            <div class="mt-2 inline-flex flex-col sm:flex-row items-center gap-3 sm:gap-6 text-sm" style="color: #3E3226;">
                @if($trustPhone)
                    <a href="tel:{{ $trustPhone }}" class="inline-flex items-center gap-2 hover:underline">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        {{ $trustPhone }}
                    </a>
                @endif
                @if($trustEmail)
                    <a href="mailto:{{ $trustEmail }}" class="inline-flex items-center gap-2 hover:underline">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #C45F12;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        {{ $trustEmail }}
                    </a>
                @endif
            </div>
        @endif
    </div>
</main>

@if($launchIso)
{{-- Devotional curtain reveal. Two velvet panels sit OFF-SCREEN until the
     countdown reaches zero, then sweep in and part — so nothing covers the
     page during the wait, and the reveal is the first thing a devotee sees
     at the moment of launch. Pure CSS transforms; no library. --}}
<div id="curtains" class="fixed inset-0 z-50 pointer-events-none" style="display:none;">
    <div class="curtain curtain-left"></div>
    <div class="curtain curtain-right"></div>
    <div id="curtain-word" class="absolute inset-0 flex items-center justify-center opacity-0">
        <span class="text-2xl sm:text-4xl font-black tracking-wide"
              style="color:#F5D98B; text-shadow:0 2px 18px rgba(0,0,0,.55);">
            {{ __('comingsoon.opening') }}
        </span>
    </div>
</div>

<style>
    .curtain {
        position: absolute; top: 0; bottom: 0; width: 52%;
        /* Velvet: vertical folds over a deep maroon ground. */
        background:
            repeating-linear-gradient(90deg,
                rgba(0,0,0,.34) 0px, rgba(0,0,0,0) 18px,
                rgba(255,220,150,.10) 34px, rgba(0,0,0,0) 52px, rgba(0,0,0,.34) 70px),
            linear-gradient(180deg, #7A1E1E 0%, #5C1414 55%, #3E0D0D 100%);
        box-shadow: 0 0 60px rgba(0,0,0,.55);
        transition: transform 2.6s cubic-bezier(.65,.02,.28,1);
    }
    .curtain::after {
        /* Gold trim on the parting edge. */
        content: ''; position: absolute; top: 0; bottom: 0; width: 6px;
        background: linear-gradient(180deg,#F0CE7A,#C89434 45%,#8A5F16);
    }
    .curtain-left  { left: 0;  transform: translateX(-101%); }
    .curtain-right { right: 0; transform: translateX(101%); }
    .curtain-left::after  { right: 0; }
    .curtain-right::after { left: 0; }

    /* Closed: both panels meet in the middle. */
    #curtains.is-closing .curtain-left,
    #curtains.is-closing .curtain-right { transform: translateX(0); }

    /* Open: they sweep back out and the site is revealed behind them. */
    #curtains.is-opening .curtain-left  { transform: translateX(-101%); }
    #curtains.is-opening .curtain-right { transform: translateX(101%); }

    #curtain-word { transition: opacity .8s ease; }
    #curtains.is-closing #curtain-word { opacity: 1; }
    #curtains.is-opening #curtain-word { opacity: 0; }

    @media (prefers-reduced-motion: reduce) {
        .curtain, #curtain-word { transition-duration: .01ms !important; }
    }
</style>

<script>
(function () {
    var el = document.getElementById('countdown');
    if (!el) return;

    var target = new Date(el.dataset.launch).getTime();
    if (isNaN(target)) return;

    var out = {};
    ['days','hours','minutes','seconds'].forEach(function (u) {
        out[u] = el.querySelector('[data-cd="' + u + '"]');
    });

    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    var fired = false;

    function reveal() {
        if (fired) return;
        fired = true;

        var curtains = document.getElementById('curtains');
        if (!curtains) { window.location.reload(); return; }

        // Close, hold, part, then reload into the live site. By this point
        // the server is already letting traffic through — the middleware
        // opens on the launch instant regardless of this animation, so the
        // reload lands on the real page and never on this one again.
        curtains.style.display = 'block';
        requestAnimationFrame(function () { curtains.classList.add('is-closing'); });

        setTimeout(function () {
            curtains.classList.remove('is-closing');
            curtains.classList.add('is-opening');
        }, 3200);

        setTimeout(function () { window.location.reload(); }, 5600);
    }

    function tick() {
        var left = target - Date.now();

        if (left <= 0) {
            ['days','hours','minutes','seconds'].forEach(function (u) {
                if (out[u]) out[u].textContent = '00';
            });
            reveal();
            return;
        }

        var s = Math.floor(left / 1000);
        if (out.days)    out.days.textContent    = pad(Math.floor(s / 86400));
        if (out.hours)   out.hours.textContent   = pad(Math.floor(s / 3600) % 24);
        if (out.minutes) out.minutes.textContent = pad(Math.floor(s / 60) % 60);
        if (out.seconds) out.seconds.textContent = pad(s % 60);
    }

    tick();
    setInterval(tick, 1000);
}());
</script>
@endif

</body>
</html>
