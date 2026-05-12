@php
    use App\Models\SystemSetting;
    $trustName    = SystemSetting::getValue('trust_name', 'શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
    $trustPhone   = SystemSetting::getValue('trust_phone');
    $trustEmail   = SystemSetting::getValue('trust_email');
@endphp
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ટૂંક સમયમાં — {{ $trustName }}</title>
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
            <span class="text-sm tracking-[0.2em] uppercase font-medium" style="color: #7A1E1E;">|| જય શ્રી રામ ||</span>
            <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background: #E8751A;"></span>
        </div>

        {{-- Main heading --}}
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black leading-[1.1] tracking-tight">
            <span style="color: #7A1E1E;">ટૂંક સમયમાં</span><br>
            <span style="color: #C45F12;">પધારી રહ્યા છીએ</span>
        </h1>

        <p class="mt-5 text-lg" style="color: #5E4F3D;">
            {{ $trustName }}નું ઑનલાઈન ધામ ટૂંક સમયમાં ઉપલબ્ધ થશે.<br class="hidden sm:block">
            કૃપા કરી થોડી પ્રતિક્ષા કરો — અમે જલ્દી પાછા આવીશું.
        </p>

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

</body>
</html>
