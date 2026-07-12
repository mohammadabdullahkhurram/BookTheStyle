@props([
    'radius' => 'list',
    'padding' => 'p-5',
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

{{-- A restrained surface: hairline border, flat. Reach for whitespace and
     .bts-rule dividers first — a card is for content that genuinely needs
     lifting off the page, and never nests inside another card. --}}
<div {{ $attributes->merge(['class' => "{$rounded} border border-border bg-card {$padding}"]) }}>
    {{ $slot }}
</div>
