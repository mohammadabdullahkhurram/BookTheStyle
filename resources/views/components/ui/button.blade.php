@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'size' => null,
    /*
     * Livewire loading affordance: set to the wire method/property name(s)
     * this button triggers (e.g. loading="save") and the button disables
     * itself and shows a spinner while that request is in flight — no call
     * site needs to hand-roll wire:loading.attr="disabled". `.bts-btn`
     * carries the disabled styling.
     */
    'loading' => null,
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
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}
        @if ($loading)
            wire:loading.attr="disabled" wire:target="{{ $loading }}"
        @endif
    >
        @if ($loading)
            <flux:icon.loading variant="micro" class="shrink-0" wire:loading wire:target="{{ $loading }}" />
        @endif
        {{ $slot }}
    </button>
@endif
