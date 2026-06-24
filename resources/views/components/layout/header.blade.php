{{-- Main Navigation --}}
<header x-data="{ mobileMenu: false, scrolled: false }"
        @scroll.window="scrolled = (window.scrollY > 30)"
        :class="scrolled ? 'bg-[#FBF5EA]/95 backdrop-blur-xl shadow-[0_4px_24px_rgba(122,30,30,0.08)] border-b border-[rgba(122,30,30,0.12)]' : 'bg-transparent'"
        class="fixed top-0 left-0 right-0 z-50 transition-all duration-500">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16 lg:h-20">
            <a href="{{ route('home') }}" class="flex items-center gap-3 flex-shrink-0 group">
                <img src="{{ asset('images/shree-pataliya-hanumanji-logo.png') }}" alt="{{ __('common.temple_name') }}" class="w-12 h-12 lg:w-11 lg:h-11 rounded-full border border-amber-700/30 group-hover:border-amber-500/50 transition-all object-cover diya-glow" style="box-shadow: 0 0 15px rgba(196,154,42,0.2);">
                <div>
                    <h1 class="text-base lg:text-base font-bold text-gold leading-tight">{{ __('common.temple_name') }}</h1>
                    <p class="text-[10px] lg:text-[10px] text-amber-700/80 tracking-widest uppercase">{{ __('common.trust_subtitle') }}</p>
                </div>
            </a>

            <nav class="hidden lg:flex items-center gap-0.5">
                <div class="relative" x-data="{ open: false, timeout: null }" @mouseenter="clearTimeout(timeout); open = true" @mouseleave="timeout = setTimeout(() => open = false, 200)">
                    <button class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors flex items-center gap-1">
                        {{ __('nav.mandir') }} <svg class="w-3 h-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-cloak
                         class="absolute top-full left-0 pt-2 z-50">
                        <div class="w-52 rounded-xl py-2 border border-[rgba(122,30,30,0.12)]" style="background: #FFFCF5; box-shadow: 0 16px 40px rgba(122,30,30,0.12);">
                            <a href="/parichay" class="block px-4 py-2.5 text-sm text-amber-100/60 hover:text-gold hover:bg-amber-900/20 transition">{{ __('nav.parichay') }}</a>
                            <a href="/itihas" class="block px-4 py-2.5 text-sm text-amber-100/60 hover:text-gold hover:bg-amber-900/20 transition">{{ __('nav.itihas') }}</a>
                            <a href="/mahima" class="block px-4 py-2.5 text-sm text-amber-100/60 hover:text-gold hover:bg-amber-900/20 transition">{{ __('nav.mahima') }}</a>
                            <a href="{{ route('trustees') }}" class="block px-4 py-2.5 text-sm text-amber-100/60 hover:text-gold hover:bg-amber-900/20 transition">{{ __('nav.trustees') }}</a>
                        </div>
                    </div>
                </div>
                <a href="{{ route('seva.index') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.seva') }}</a>
                <a href="{{ route('darshan') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.darshan') }}</a>
                <a href="{{ route('events.index') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.events') }}</a>
                <a href="{{ route('projects.index') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.projects') }}</a>
                <a href="{{ route('gallery') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.gallery') }}</a>
                <a href="{{ route('store.index') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.store') }}</a>
                <a href="{{ route('halls.index') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.halls') }}</a>
                <a href="{{ route('contact') }}" class="px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition-colors">{{ __('nav.contact') }}</a>
            </nav>

            <div class="flex items-center gap-3">
                {{-- Cart sits AHEAD of dashboard per design feedback —
                     it's an in-progress order, easier to spot here. --}}
                @auth('devotee')
                    <a href="{{ route('store.cart') }}" class="relative hidden lg:flex items-center px-2 py-2 text-amber-100/60 hover:text-gold transition-colors" title="{{ __('nav.cart') }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z"/></svg>
                        @if(count(session('cart', [])) > 0)
                            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] flex items-center justify-center px-1 text-[10px] font-bold text-white bg-amber-600 rounded-full leading-none">{{ array_sum(session('cart')) }}</span>
                        @endif
                    </a>
                    <a href="{{ route('dashboard.index') }}" class="hidden lg:flex items-center gap-1.5 px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition" title="{{ __('nav.dashboard') }}">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        {{ __('nav.dashboard') }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="hidden lg:flex items-center gap-1.5 px-3 py-2 text-sm text-amber-100/60 hover:text-gold transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                        {{ __('nav.login') }}
                    </a>
                @endauth
                <div class="hidden lg:block"><x-layout.language-switcher /></div>
                <a href="{{ route('donate') }}" class="hidden sm:inline-flex btn-divine text-xs px-5 py-2">{{ __('nav.donate') }}</a>
                <button @click="mobileMenu = true" class="lg:hidden p-2 text-amber-100/60 hover:text-gold transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>
    </div>

    {{-- Mobile Menu --}}
    <div x-show="mobileMenu" x-cloak class="fixed inset-0 z-[100] lg:hidden" @keydown.escape.window="mobileMenu = false">
        <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="absolute inset-0 bg-[rgba(42,24,16,0.35)] backdrop-blur-sm" @click="mobileMenu = false"></div>
        <div x-show="mobileMenu" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-200" class="absolute right-0 top-0 bottom-0 w-80 max-w-[85vw] overflow-y-auto border-l border-[rgba(122,30,30,0.12)] shadow-2xl" style="background: linear-gradient(180deg, #FFFCF5, #FBF5EA);">
            <div class="flex items-center justify-between p-5 border-b border-amber-900/20">
                <span class="font-bold text-gold">{{ __('nav.menu') }}</span>
                <button @click="mobileMenu = false" class="p-1.5 text-amber-100/40 hover:text-gold"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
            </div>
            <nav class="p-4 space-y-1">
                <a href="{{ route('home') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.home') }}</a>
                <a href="{{ route('seva.index') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.seva') }}</a>
                <a href="{{ route('darshan') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.darshan') }}</a>
                <a href="{{ route('events.index') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.events') }}</a>
                <a href="{{ route('projects.index') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.projects') }}</a>
                <a href="{{ route('gallery') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.gallery') }}</a>
                <a href="{{ route('store.index') }}" class="flex items-center justify-between px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">
                    <span>{{ __('nav.store') }}</span>
                    @auth('devotee')
                        @if(count(session('cart', [])) > 0)
                            <span class="min-w-[20px] h-[20px] flex items-center justify-center px-1.5 text-[10px] font-bold text-white bg-amber-600 rounded-full leading-none">{{ array_sum(session('cart')) }}</span>
                        @endif
                    @endauth
                </a>
                <a href="{{ route('halls.index') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.halls') }}</a>
                <a href="{{ route('contact') }}" class="block px-4 py-3 text-amber-100/70 hover:text-gold hover:bg-amber-900/15 rounded-xl transition">{{ __('nav.contact') }}</a>
                <div class="pt-4 mt-2 border-t border-amber-900/20 space-y-2">
                    <a href="{{ route('donate') }}" class="block w-full text-center btn-divine py-3">{{ __('nav.donate') }}</a>
                    @auth('devotee')
                        <a href="{{ route('dashboard.index') }}" class="block w-full text-center py-2.5 text-sm text-gold border border-amber-800/40 rounded-full hover:bg-amber-900/20 transition">{{ __('nav.dashboard') }}</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="block w-full text-center py-2 text-xs text-amber-100/30 hover:text-red-400 transition">{{ __('nav.logout') }}</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="block w-full text-center py-2.5 text-sm text-gold border border-amber-800/40 rounded-full hover:bg-amber-900/20 transition">{{ __('nav.login') }}</a>
                    @endauth
                </div>
                {{-- Language switcher (mobile) --}}
                <div class="pt-4 mt-2 border-t border-amber-900/20">
                    <p class="px-4 pb-2 text-[11px] uppercase tracking-widest text-amber-700/70">{{ __('common.language') }}</p>
                    <div class="flex gap-2 px-4">
                        @foreach (['gu' => 'ગુજરાતી', 'hi' => 'हिन्दी', 'en' => 'English'] as $code => $label)
                            <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                               class="flex-1 text-center py-2 text-sm rounded-full border transition {{ app()->getLocale() === $code ? 'text-gold border-amber-600 bg-amber-900/10 font-semibold' : 'text-amber-100/60 border-amber-800/30 hover:text-gold' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            </nav>
        </div>
    </div>
</header>
<div class="h-16 lg:h-20"></div>
