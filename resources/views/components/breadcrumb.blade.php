@props(['items' => []])
{{--
    Canonical breadcrumb. Pass `items` as an array of arrays:
        [['label' => 'સેવા પ્રોજેક્ટ્સ', 'url' => route('projects.index')],
         ['label' => $project->title]]   ← last item is the current page (no url)
    The "મુખ્ય પૃષ્ઠ" home link is prepended automatically.
--}}
<nav aria-label="Breadcrumb"
     {{ $attributes->merge(['class' => 'flex items-center flex-wrap gap-x-1.5 gap-y-1 text-sm']) }}
     style="color: #5E4F3D;">
    <a href="{{ route('home') }}"
       class="hover:underline transition"
       style="color: #5E4F3D;">મુખ્ય પૃષ્ઠ</a>

    @foreach($items as $item)
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #8A7860;">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>

        @if(!empty($item['url']) && !$loop->last)
            {{-- Linked intermediate crumb --}}
            <a href="{{ $item['url'] }}"
               class="hover:underline transition"
               style="color: #5E4F3D;">{{ $item['label'] }}</a>
        @elseif($loop->last)
            {{-- Current page (always last) --}}
            <span class="font-medium" style="color: #7A1E1E;" aria-current="page">{{ $item['label'] }}</span>
        @else
            {{-- Unlinked intermediate (e.g. category label without an index page) --}}
            <span style="color: #5E4F3D;">{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
