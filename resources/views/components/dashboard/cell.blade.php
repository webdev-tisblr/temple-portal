@props(['label' => null])
{{--
    A table cell that carries its own column label. Below `md` the table
    collapses to stacked cards (see .dash-table in app.css) and the label
    is what keeps the value readable; from `md` up it is hidden and the
    real <thead> takes over. This is why the dashboard tables no longer
    scroll sideways on a phone.
--}}
<td {{ $attributes->merge(['class' => 'dash-td']) }}>
    @if($label)
        <span class="dash-cell-label">{{ $label }}</span>
    @endif
    <span class="dash-cell-value">{{ $slot }}</span>
</td>
