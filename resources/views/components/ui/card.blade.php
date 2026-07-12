@props([
    'radius' => 'list',
    'padding' => 'p-6',
])

@php
    // Radii come from the token layer, so the whole surface system re-tunes
    // from app.css alone.
    $rounded = match ($radius) {
        'stat' => 'rounded-[var(--radius-stat)]',
        'modal' => 'rounded-[var(--radius-modal)]',
        default => 'rounded-[var(--radius-list)]',
    };
@endphp

<div {{ $attributes->merge(['class' => "{$rounded} border border-border bg-card shadow-card {$padding}"]) }}>
    {{ $slot }}
</div>
