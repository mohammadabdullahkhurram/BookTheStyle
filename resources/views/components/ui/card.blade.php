@props([
    'radius' => 'list',
    'padding' => 'p-6',
])

@php
    $rounded = match ($radius) {
        'stat' => 'rounded-[16px]',
        'modal' => 'rounded-[20px]',
        default => 'rounded-[18px]',
    };
@endphp

<div {{ $attributes->merge(['class' => "{$rounded} border border-border bg-card shadow-card {$padding}"]) }}>
    {{ $slot }}
</div>
