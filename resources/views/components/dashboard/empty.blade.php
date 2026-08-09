@props([
    'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    'message',
    'hint' => null,
    'ctaHref' => null,
    'ctaLabel' => null,
])
{{-- Shared empty state for every dashboard list. --}}
<div class="px-6 py-16 text-center">
    <span class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full"
          style="background: rgba(232,117,26,0.10); color: #C45F12;">
        <x-dashboard.icon :path="$icon" class="w-7 h-7" />
    </span>

    <p class="text-sm font-medium" style="color: #5E4F3D;">{{ $message }}</p>

    @if($hint)
        <p class="mx-auto mt-1.5 max-w-sm text-xs" style="color: #8A7860;">{{ $hint }}</p>
    @endif

    @if($ctaHref && $ctaLabel)
        <a href="{{ $ctaHref }}" class="btn-divine mt-6">{{ $ctaLabel }}</a>
    @endif
</div>
