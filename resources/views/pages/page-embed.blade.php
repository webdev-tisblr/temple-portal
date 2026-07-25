<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>{{ $page->title }}</title>
    @vite(['resources/css/app.css', 'resources/js/clean-youtube.js'])
    <style>
        /* Parchment scaffold — must match the app's screen background, and
           app.css's palette (#FBF5EA / #2A1810). This block used to pin a
           dark background from the pre-redesign theme, which left the
           now-dark body text sitting on near-black: the page rendered
           almost unreadable inside the app's cream WebView. */
        html, body { background: #FBF5EA; color: #2A1810; margin: 0; }
        body { padding: 18px 16px 48px; -webkit-text-size-adjust: 100%; -webkit-font-smoothing: antialiased; }
        img { max-width: 100%; height: auto; }
        /* Never let content force horizontal scroll inside the WebView. */
        * { max-width: 100%; }
        iframe { max-width: 100%; }
    </style>
</head>
<body>
    <h1 class="divine-heading text-2xl sm:text-3xl mb-5">{{ $page->title }}</h1>

    @if(!empty($page->blocks))
        @include('partials.blocks', ['blocks' => $page->blocks])
    @else
        {{-- Plain `prose` — app.css maps its colour vars to the parchment
             palette. prose-invert here was a leftover from the dark theme. --}}
        <div class="prose max-w-none">
            {!! $page->body !!}
        </div>
    @endif
</body>
</html>
