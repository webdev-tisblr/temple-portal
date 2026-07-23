<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>{{ $page->title }}</title>
    @vite(['resources/css/app.css', 'resources/js/clean-youtube.js'])
    <style>
        html, body { background: #17120a; color: #f2e4c4; margin: 0; }
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
        <div class="prose prose-invert prose-headings:text-gold prose-a:text-amber-500 max-w-none text-amber-100/70">
            {!! $page->body !!}
        </div>
    @endif
</body>
</html>
