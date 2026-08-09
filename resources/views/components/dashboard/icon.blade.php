@props(['path', 'class' => 'w-5 h-5'])
{{--
    One place that knows how to draw a stroked 24×24 outline icon, so the
    rest of the dashboard passes a path `d` string instead of repeating a
    full <svg> element. Decorative by default — every icon on the dashboard
    sits next to a real text label.
--}}
<svg class="{{ $class }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $path }}" />
</svg>
