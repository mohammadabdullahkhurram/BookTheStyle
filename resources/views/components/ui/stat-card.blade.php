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

<div {{ $attributes->merge(['class' => 'rounded-[var(--radius-stat)] border border-border bg-card p-[18px] shadow-card']) }}>
    <div class="text-[14px] font-medium text-secondary">{{ $label }}</div>
    {{-- Stat numbers speak in the display serif — Fraunces 34/600. --}}
    <div class="mt-2.5 font-display text-[34px] font-semibold leading-none" style="color: {{ $color }};">{{ $value }}</div>
    @if ($sublabel)
        <div class="mt-2 text-[13.5px] text-faint">{{ $sublabel }}</div>
    @endif
</div>
