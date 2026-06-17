@props(['salon'])

@php
    $tab = 'inline-flex items-center rounded-md px-3 py-1.5 text-sm font-medium transition';
    $active = 'bg-accent-soft text-accent';
    $idle = 'text-secondary hover:text-ink';
@endphp

<nav class="flex items-center gap-1 rounded-lg border border-border bg-card p-1 shadow-xs">
    <a href="{{ route('salon.show', $salon) }}" wire:navigate
       class="{{ $tab }} {{ request()->routeIs('salon.show') ? $active : $idle }}">{{ __('Today') }}</a>

    @can('accessBookings', $salon)
        <a href="{{ route('salon.appointments', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.appointments') || request()->routeIs('salon.bookings.create') ? $active : $idle }}">{{ __('Appointments') }}</a>
    @endcan

    @can('manageBookings', $salon)
        <a href="{{ route('salon.clients', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.clients') ? $active : $idle }}">{{ __('Clients') }}</a>
    @endcan

    @can('manageStaff', $salon)
        <a href="{{ route('salon.staff', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.staff') ? $active : $idle }}">{{ __('Staff') }}</a>
    @endcan

    @can('manageServices', $salon)
        <a href="{{ route('salon.services', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.services') ? $active : $idle }}">{{ __('Services') }}</a>
    @endcan

    @if (auth()->user()?->can('manage', $salon) || auth()->user()?->stylistMembershipFor($salon))
        <a href="{{ route('salon.availability', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.availability') ? $active : $idle }}">{{ __('Availability') }}</a>
    @endif

    @can('manage', $salon)
        <a href="{{ route('salon.settings', $salon) }}" wire:navigate
           class="{{ $tab }} {{ request()->routeIs('salon.settings') ? $active : $idle }}">{{ __('Settings') }}</a>
    @endcan
</nav>
