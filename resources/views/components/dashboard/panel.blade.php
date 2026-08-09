@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'href' => null,
    'linkLabel' => null,
])
{{--
    A titled panel on the parchment surface. `card-sacred` is deliberately
    not reused here: it lifts on hover, which is right for a clickable card
    and wrong for a table container.
--}}
<section {{ $attributes->merge(['class' => 'dash-panel']) }}>
    @if($title)
        <div class="dash-panel-head">
            <div class="flex min-w-0 items-center gap-2.5">
                @if($icon)
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                          style="background: rgba(232,117,26,0.12); color: #C45F12;">
                        <x-dashboard.icon :path="$icon" class="w-4 h-4" />
                    </span>
                @endif
                <div class="min-w-0">
                    <h2 class="dash-panel-title">{{ $title }}</h2>
                    @if($subtitle)
                        <p class="mt-0.5 text-xs" style="color: #8A7860;">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>

            @if($href && $linkLabel)
                <a href="{{ $href }}" class="dash-link shrink-0">
                    {{ $linkLabel }}
                    <span aria-hidden="true">&rarr;</span>
                </a>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
