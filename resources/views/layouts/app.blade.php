<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#FBF5EA">

    {{-- Apply theme class BEFORE first paint to prevent flash of dark.
         Default is 'light' to match the mobile app. --}}
    <script>
    (function(){
        try {
            var t = localStorage.getItem('theme') || 'light';
            var dark = t === 'system'
                ? window.matchMedia('(prefers-color-scheme:dark)').matches
                : t === 'dark';
            document.documentElement.classList.toggle('light-mode', !dark);
        } catch (e) {
            document.documentElement.classList.add('light-mode');
        }
    })();
    </script>

    {!! SEOMeta::generate() !!}

    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+Gujarati:wght@400;500;600;700;900&family=Hind+Vadodara:wght@400;500;600;700&family=Noto+Sans+Gujarati:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="font-sans antialiased">
    <x-layout.header />

    <main>
        @yield('content')
    </main>

    <x-layout.footer />

    {{-- Theme Toggle (cycle: light → dark → system; default light to match app) --}}
    <div id="theme-toggle" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
        <button id="theme-btn" onclick="(function(){
            var modes=['light','dark','system'];
            var cur=localStorage.getItem('theme')||'light';
            var i=modes.indexOf(cur);
            var next=modes[(i+1)%3];
            localStorage.setItem('theme',next);
            var dark=next==='system'?window.matchMedia('(prefers-color-scheme:dark)').matches:next==='dark';
            document.documentElement.classList.toggle('light-mode',!dark);
            var icons={dark:'🌙',light:'☀️',system:'💻'};
            document.getElementById('theme-icon').textContent=icons[next];
            document.getElementById('theme-label').textContent=next==='system'?'Auto':next.charAt(0).toUpperCase()+next.slice(1);
        })()"
        class="theme-toggle-btn"
        style="width:48px;height:48px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;border:2px solid rgba(200,148,52,0.55);background:rgba(255,252,245,0.95);box-shadow:0 4px 20px rgba(122,30,30,0.10);color:#7A1E1E;font-size:18px;line-height:1;">
            <span id="theme-icon">☀️</span>
            <span id="theme-label" style="font-size:7px;font-weight:bold;letter-spacing:0.5px;opacity:0.7;margin-top:1px;">Light</span>
        </button>
    </div>
    <script>
    (function(){
        var t=localStorage.getItem('theme')||'light';
        var icons={dark:'🌙',light:'☀️',system:'💻'};
        var el=document.getElementById('theme-icon');
        if(el)el.textContent=icons[t];
        var lb=document.getElementById('theme-label');
        if(lb)lb.textContent=t==='system'?'Auto':t.charAt(0).toUpperCase()+t.slice(1);
        window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change',function(){
            if(localStorage.getItem('theme')==='system'){
                document.documentElement.classList.toggle('light-mode',!this.matches);
            }
        });
    })();
    </script>

    @stack('scripts')
</body>
</html>
