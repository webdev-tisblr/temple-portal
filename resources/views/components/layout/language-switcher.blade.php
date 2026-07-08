{{-- Language switcher — hits the /locale/{code} route, which persists the
     choice in a cookie and bounces back to the current page. --}}
@php
    $langs = ['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'];
    $current = app()->getLocale();
@endphp
<div class="relative" x-data="{ open: false, timeout: null }"
     @mouseenter="clearTimeout(timeout); open = true"
     @mouseleave="timeout = setTimeout(() => open = false, 200)">
    <button class="px-2.5 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors flex items-center gap-1">
        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
        <span>{{ $langs[$current] ?? $langs['gu'] }}</span>
        <svg class="w-3 h-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-cloak
         class="absolute top-full right-0 pt-2 z-50">
        <div class="w-36 rounded-xl py-2 border border-[rgba(122,30,30,0.12)]" style="background: #FFFCF5; box-shadow: 0 16px 40px rgba(122,30,30,0.12);">
            @foreach ($langs as $code => $label)
                <a href="{{ route('locale.set', $code) }}"
                   class="flex items-center justify-between px-4 py-2.5 text-sm hover:bg-amber-900/10 transition {{ $current === $code ? 'text-gold font-semibold' : 'text-amber-100/60 hover:text-gold' }}">
                    <span>{{ $label }}</span>
                    @if ($current === $code)
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
</div>
