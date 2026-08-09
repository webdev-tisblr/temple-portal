@props(['status'])
{{--
    One status chip for the whole dashboard. The seva/hall/order status
    vocabularies overlap but not exactly, so the mapping lives here instead
    of being re-typed (differently) on each page as it was before.
--}}
@php
    $value = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;

    $tone = match ($value) {
        'confirmed', 'completed', 'delivered', 'shipped' => 'dash-chip-ok',
        'cancelled', 'refunded' => 'dash-chip-bad',
        'processing' => 'dash-chip-info',
        default => 'dash-chip-warn',
    };

    // Fall back to the raw value for any status a future migration adds
    // before its label reaches the lang files — better a bare word than
    // the literal translation key on a devotee's screen.
    $key = 'dashboard.status_'.$value;
    $label = __($key);
    if ($label === $key) {
        $label = ucfirst(str_replace('_', ' ', $value));
    }
@endphp

<span class="dash-chip {{ $tone }}">
    <span class="dash-chip-dot"></span>
    {{ $label }}
</span>
