@props([
    'label',
    'value',
    'sublabel' => null,
    'tone' => 'ink',
])

@php
    // Semantic tone tokens (see app.css @theme) — never raw hexes here.
    $color = match ($tone) {
        'info' => 'var(--color-info)',
        'success' => 'var(--color-success)',
        'warning' => 'var(--color-warning)',
        'danger' => 'var(--color-danger)',
        default => 'var(--color-ink)',
    };
@endphp

{{-- Open editorial stat: a hairline rule, a small tracked label, and a
     confident Fraunces figure sitting directly on the page — no card, no
     shadow. The drama is the scale contrast, not the container. Under the
     lumen language, .bts-stat restyles this into a frosted Apple-widget
     tile (see app.css). --}}
<div {{ $attributes->merge(['class' => 'bts-stat bts-rule pt-3']) }}>
    <div class="text-[12.5px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ $label }}</div>
    <div class="mt-2 font-display text-[36px] font-semibold leading-none" style="color: {{ $color }};">{{ $value }}</div>
    @if ($sublabel)
        <div class="mt-1.5 text-[13px] text-faint">{{ $sublabel }}</div>
    @endif
</div>
