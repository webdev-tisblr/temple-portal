@extends('layouts.app')

@section('content')

{{-- =================================================================
     STATUS MAKER — web teaser. The generator itself lives in the app;
     the web shows the designs and drives an app download.
     ================================================================= --}}
<section class="relative overflow-hidden" style="background:linear-gradient(180deg,#7A1E1E 0%,#5E1616 100%);">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-28 pb-16 text-center">
        <p class="text-[11px] tracking-[0.28em] font-extrabold" style="color:#F0B36A;">{{ __('status.eyebrow') }}</p>
        <h1 class="font-marcellus text-3xl sm:text-4xl lg:text-5xl mt-3" style="color:#FFF7EC;">{{ __('status.title') }}</h1>
        <p class="mt-4 text-base sm:text-lg max-w-2xl mx-auto" style="color:rgba(253,246,230,.85);">{{ __('status.subtitle') }}</p>

        {{-- Store CTAs --}}
        <div class="flex flex-wrap items-center justify-center gap-4 mt-8">
            @if($androidUrl)
                <a href="{{ $androidUrl }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition shadow-lg">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 2.3l10.8 10.8-10.8 10.8c-.4-.2-.6-.6-.6-1.1V3.4c0-.5.2-.9.6-1.1zm12 9.6L5.2 1.5l11.9 6.9-1.5 3.5zm2.9 1.7l2.6 1.5c.8.5.8 1.6 0 2.1l-2.6 1.5-2-2.6 2-2.5zm-2.9 3.4l1.5 3.5-11.9 6.9 10.4-10.4z"/></svg>
                    <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">GET IT ON</span><span class="block text-sm font-bold">Google Play</span></span>
                </a>
            @endif
            @if($iosUrl)
                <a href="{{ $iosUrl }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition shadow-lg">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8.79-.16 2.31-.93 3.9-.79 1.9.15 3.32.9 4.27 2.26-3.9 2.35-3.28 7.5.75 8.94-.61 1.42-1.38 2.83-2.61 3.76h-.39zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                    <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">Download on the</span><span class="block text-sm font-bold">App Store</span></span>
                </a>
            @endif
        </div>
        @if(!$androidUrl && !$iosUrl)
            <p class="mt-6 text-sm" style="color:rgba(253,246,230,.7);">{{ __('status.get_app') }}</p>
        @endif
    </div>
</section>

{{-- ── How it works ─────────────────────────────────────────────── --}}
<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
    <div class="text-center">
        <h2 class="font-marcellus text-2xl sm:text-3xl" style="color:#7A1E1E;">{{ __('status.how_title') }}</h2>
    </div>
    <div class="grid sm:grid-cols-3 gap-6 mt-10">
        @foreach([['1', 'step1_t', 'step1_d'], ['2', 'step2_t', 'step2_d'], ['3', 'step3_t', 'step3_d']] as [$n, $tk, $dk])
            <div class="rounded-2xl p-6 text-center" style="background:#FFFCF5; border:1px solid #ecdfc4;">
                <div class="w-11 h-11 mx-auto rounded-full flex items-center justify-center font-marcellus text-lg"
                     style="background:#FBEFE2; color:#C45F12;">{{ $n }}</div>
                <h3 class="font-marcellus text-lg mt-4" style="color:#7A1E1E;">{{ __("status.$tk") }}</h3>
                <p class="text-sm mt-2 leading-relaxed" style="color:#5E4F3D;">{{ __("status.$dk") }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ── Ready-made designs (preview gallery) ─────────────────────── --}}
@if($templates->isNotEmpty())
    <section class="py-14" style="background:linear-gradient(180deg,#FBF5EA,#F4EAD5);">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <p class="text-[11px] tracking-[0.24em] font-extrabold" style="color:#C45F12;">{{ __('status.gallery_title') }}</p>
                <h2 class="font-marcellus text-2xl sm:text-3xl mt-2" style="color:#7A1E1E;">{{ __('status.gallery_sub') }}</h2>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5 mt-10">
                @foreach($templates as $t)
                    <div class="rounded-2xl overflow-hidden shadow-md" style="background:#FFFCF5; border:1px solid #e6d7bd;">
                        <div class="aspect-[4/5] bg-cover bg-center"
                             style="@if($t->greeting_card_template) background-image:url('{{ image_url($t->greeting_card_template) }}'); @else background:repeating-linear-gradient(45deg,#e8dcc4 0 12px,#f1e8d3 12px 24px); @endif"></div>
                        <p class="px-3 py-2.5 text-sm text-center font-medium" style="color:#7A1E1E;">{{ $t->title }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Repeat CTA under the gallery --}}
            @if($androidUrl || $iosUrl)
                <div class="text-center mt-10">
                    <p class="font-bold mb-4" style="color:#7A1E1E;">{{ __('status.get_app') }}</p>
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        @if($androidUrl)
                            <a href="{{ $androidUrl }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition shadow-lg">
                                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M3.6 2.3l10.8 10.8-10.8 10.8c-.4-.2-.6-.6-.6-1.1V3.4c0-.5.2-.9.6-1.1zm12 9.6L5.2 1.5l11.9 6.9-1.5 3.5zm2.9 1.7l2.6 1.5c.8.5.8 1.6 0 2.1l-2.6 1.5-2-2.6 2-2.5zm-2.9 3.4l1.5 3.5-11.9 6.9 10.4-10.4z"/></svg>
                                <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">GET IT ON</span><span class="block text-sm font-bold">Google Play</span></span>
                            </a>
                        @endif
                        @if($iosUrl)
                            <a href="{{ $iosUrl }}" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-3 px-6 py-3 rounded-xl bg-black text-white hover:bg-stone-800 transition shadow-lg">
                                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8.79-.16 2.31-.93 3.9-.79 1.9.15 3.32.9 4.27 2.26-3.9 2.35-3.28 7.5.75 8.94-.61 1.42-1.38 2.83-2.61 3.76h-.39zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg>
                                <span class="text-left leading-tight"><span class="block text-[10px] opacity-75">Download on the</span><span class="block text-sm font-bold">App Store</span></span>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </section>
@endif

@endsection
