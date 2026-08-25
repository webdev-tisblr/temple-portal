<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FBF5EA">

    {{-- The site has its own language switcher (gu/hi/en), so suppress the
         browser's "Translate this page?" prompt to avoid a redundant offer. --}}
    <meta name="google" content="notranslate">

    {!! SEOMeta::generate() !!}

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Marcellus&family=Noto+Serif+Gujarati:wght@400;500;600;700;900&family=Hind+Vadodara:wght@400;500;600;700&family=Noto+Sans+Gujarati:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')

    <x-analytics />
</head>
<body class="font-sans antialiased">
    <x-layout.header />

    <main>
        @yield('content')
    </main>

    <x-layout.footer />

    {{-- Sessions arriving from the app get the return-to-app bar instead
         of the install banner — offering an install to someone who came
         FROM the app would be contradictory. --}}
    @if(session()->has('from_app') && auth('devotee')->check())
        <x-return-to-app-banner />
    @else
        <x-app-install-banner />
    @endif

    @include('partials.site-popup')
    @include('partials.social-links')
    @include('partials.payment-overlay')

    @stack('scripts')
</body>
</html>
