@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'size' => null,
])

@php
    $classes = 'bts-btn'
        .($size === 'sm' ? ' bts-btn-sm' : '')
        .match ($variant) {
            'secondary' => ' bts-btn-secondary',
            'danger' => ' bts-btn-danger',
            default => ' bts-btn-primary',
        };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
