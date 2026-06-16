@props(['salon'])

@php
    $tab = 'inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium transition';
    $active = 'bg-accent-soft text-accent';
    $idle = 'text-secondary hover:text-ink';
@endphp

<nav class="flex items-center gap-1 rounded-lg border border-border bg-card p-1 shadow-xs">
    <a href="{{ route('salon.show', $salon) }}" wire:navigate
       class="{{ $tab }} {{ request()->routeIs('salon.show') ? $active : $idle }}">{{ __('Overview') }}</a>

    @can('manageStaff', $salon)
        <a href="{{ route('salon.staff', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.staff') ? $active : $idle }}">{{ __('Staff') }}</a>
    @endcan

    @can('manage', $salon)
        <a href="{{ route('salon.settings', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.settings') ? $active : $idle }}">{{ __('Settings') }}</a>
    @endcan
</nav>
