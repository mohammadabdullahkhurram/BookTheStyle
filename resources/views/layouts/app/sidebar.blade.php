@php
    $user = auth()->user();
    $salon = app()->bound('currentSalon') ? app('currentSalon') : null;

    $roleLabel = null;
    if ($salon && $user) {
        $membership = $user->membershipFor($salon);
        $roleLabel = $membership?->staff_type?->label() ?? $membership?->salon_role?->label();
    }
    $roleLabel ??= $user?->email;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div
            x-data="{ collapsed: (localStorage.getItem('bts-sidebar') === '1') }"
            x-init="$watch('collapsed', v => localStorage.setItem('bts-sidebar', v ? '1' : '0'))"
            class="flex min-h-svh"
        >
            {{-- Sidebar --}}
            <aside
                :class="collapsed ? 'w-[76px]' : 'w-[244px]'"
                class="sticky top-0 z-20 flex h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200"
            >
                {{-- Logo + collapse --}}
                <div class="flex items-center gap-3 px-4 pb-3 pt-4">
                    <a href="{{ $salon ? route('salon.show', $salon) : route('dashboard') }}" wire:navigate
                       aria-label="{{ __('BookTheStyle') }}"
                       class="flex min-w-0 flex-1 items-center" :class="collapsed ? 'justify-center' : ''">
                        <x-app-logo x-show="!collapsed" x-cloak class="h-8" alt="" />
                        <x-app-logo-icon x-show="collapsed" x-cloak class="size-9" alt="" />
                    </a>
                    <button type="button" x-show="!collapsed" x-cloak @click="collapsed = true"
                            class="shrink-0 rounded-md p-1 text-fainter transition hover:bg-muted hover:text-ink" aria-label="{{ __('Collapse sidebar') }}">
                        <flux:icon.chevron-left variant="micro" />
                    </button>
                </div>

                <button type="button" x-show="collapsed" x-cloak @click="collapsed = false"
                        class="mx-4 mb-1 flex items-center justify-center rounded-md p-1.5 text-fainter transition hover:bg-muted hover:text-ink" aria-label="{{ __('Expand sidebar') }}">
                    <flux:icon.bars-3 variant="micro" />
                </button>

                {{-- Primary nav --}}
                <nav class="mt-2 flex flex-1 flex-col gap-1 px-3">
                    @if ($salon)
                        <a href="{{ route('salon.show', $salon) }}" wire:navigate
                           class="bts-nav-item {{ request()->routeIs('salon.show') ? 'bts-nav-item-active' : '' }}">
                            <flux:icon.squares-2x2 variant="micro" class="shrink-0" />
                            <span x-show="!collapsed" x-cloak>{{ __('Today') }}</span>
                        </a>
                        {{-- Calendar: managers/front-desk get the master view, a
                             stylist their own column. --}}
                        @can('accessBookings', $salon)
                            <a href="{{ route('salon.calendar', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.calendar') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.calendar variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Calendar') }}</span>
                            </a>
                            {{-- The full, searchable list of every appointment
                                 (stylists see their own; the page scopes it). --}}
                            <a href="{{ route('salon.appointments.all', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.appointments.all') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.list-bullet variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Appointments') }}</span>
                            </a>
                        @endcan
                        {{-- Check-in: front-desk level only (owner/admin/front-desk).
                             Hidden from stylists, who cannot change booking status. --}}
                        @can('accessBookings', $salon)
                            <a href="{{ route('salon.clients', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.clients') || request()->routeIs('salon.client') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.user-group variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Clients') }}</span>
                            </a>
                        @endcan
                        @can('manageBookings', $salon)
                            <a href="{{ route('salon.appointments', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.appointments') || request()->routeIs('salon.bookings.create') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.clipboard-document-check variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Check-in') }}</span>
                            </a>
                        @endcan

                        {{-- Management links. Visibility mirrors each screen's own
                             server-side authorization, so no link ever 403s. --}}
                        @can('manageServices', $salon)
                            <a href="{{ route('salon.services', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.services') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.sparkles variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Services') }}</span>
                            </a>
                        @endcan
                        @can('manageStaff', $salon)
                            <a href="{{ route('salon.staff', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.staff') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.users variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Staff') }}</span>
                            </a>
                        @endcan
                        @can('manage', $salon)
                            <a href="{{ route('salon.reports', $salon) }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('salon.reports') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.chart-bar variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Reports') }}</span>
                            </a>
                        @endcan
                        {{-- Every member may VIEW staff schedules; editing is
                             gated per stylist inside the page. --}}
                        <a href="{{ route('salon.availability', $salon) }}" wire:navigate
                           class="bts-nav-item {{ request()->routeIs('salon.availability') ? 'bts-nav-item-active' : '' }}">
                            <flux:icon.clock variant="micro" class="shrink-0" />
                            <span x-show="!collapsed" x-cloak>{{ __('Availability') }}</span>
                        </a>
                    @else
                        <a href="{{ route('dashboard') }}" wire:navigate
                           class="bts-nav-item {{ request()->routeIs('dashboard') ? 'bts-nav-item-active' : '' }}">
                            <flux:icon.squares-2x2 variant="micro" class="shrink-0" />
                            <span x-show="!collapsed" x-cloak>{{ __('Salons') }}</span>
                        </a>
                        @if ($user?->isAgencyOperator())
                            <a href="{{ route('agency.overview') }}" wire:navigate
                               class="bts-nav-item {{ request()->routeIs('agency.*') ? 'bts-nav-item-active' : '' }}">
                                <flux:icon.building-office-2 variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('Agency console') }}</span>
                            </a>
                        @endif
                    @endif
                </nav>

                {{-- New booking (salon context) --}}
                @if ($salon)
                    @can('accessBookings', $salon)
                        <div class="px-3 pb-1">
                            <a href="{{ route('salon.bookings.create', $salon) }}" wire:navigate
                               class="bts-btn bts-btn-primary w-full"
                               :class="collapsed ? '!px-0' : ''">
                                <flux:icon.plus variant="micro" class="shrink-0" />
                                <span x-show="!collapsed" x-cloak>{{ __('New booking') }}</span>
                            </a>
                        </div>
                    @endcan
                @endif

                {{-- Settings --}}
                <nav class="px-3 pb-2 pt-1">
                    @if ($salon && $user?->can('manage', $salon))
                        <a href="{{ route('salon.settings', $salon) }}" wire:navigate
                           class="bts-nav-item {{ request()->routeIs('salon.settings') ? 'bts-nav-item-active' : '' }}">
                            <flux:icon.cog-6-tooth variant="micro" class="shrink-0" />
                            <span x-show="!collapsed" x-cloak>{{ __('Settings') }}</span>
                        </a>
                    @else
                        <a href="{{ route('profile.edit') }}" wire:navigate
                           class="bts-nav-item {{ request()->routeIs('profile.*') ? 'bts-nav-item-active' : '' }}">
                            <flux:icon.cog-6-tooth variant="micro" class="shrink-0" />
                            <span x-show="!collapsed" x-cloak>{{ __('Settings') }}</span>
                        </a>
                    @endif
                </nav>

                {{-- User chip --}}
                <div class="border-t border-border p-3">
                    <flux:dropdown position="top" align="start" class="w-full">
                        <button type="button" data-test="sidebar-menu-button"
                                class="flex w-full items-center gap-3 rounded-[13px] p-1.5 text-start transition hover:bg-muted">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#1E1D2A] text-[13px] font-semibold text-white">{{ $user?->initials() }}</span>
                            <span x-show="!collapsed" x-cloak class="min-w-0 flex-1 leading-tight">
                                <span class="block truncate text-[14px] font-semibold text-ink">{{ $user?->name }}</span>
                                <span class="block truncate text-[12.5px] text-secondary">{{ $roleLabel }}</span>
                            </span>
                        </button>

                        <flux:menu>
                            <div class="flex items-center gap-2 px-1 py-1.5">
                                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#1E1D2A] text-[12px] font-semibold text-white">{{ $user?->initials() }}</span>
                                <div class="grid flex-1 text-start leading-tight">
                                    <flux:heading class="truncate">{{ $user?->name }}</flux:heading>
                                    <flux:text class="truncate text-xs">{{ $user?->email }}</flux:text>
                                </div>
                            </div>
                            <flux:menu.separator />
                            @if ($salon)
                                <flux:menu.item :href="route('dashboard')" icon="squares-2x2" wire:navigate>
                                    {{ __('All salons') }}
                                </flux:menu.item>
                            @endif
                            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                                {{ __('Account settings') }}
                            </flux:menu.item>
                            <flux:menu.separator />
                            <form method="POST" action="{{ route('logout') }}" class="w-full">
                                @csrf
                                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                                                class="w-full cursor-pointer" data-test="logout-button">
                                    {{ __('Log out') }}
                                </flux:menu.item>
                            </form>
                        </flux:menu>
                    </flux:dropdown>
                </div>
            </aside>

            {{-- Main --}}
            <div class="flex min-w-0 flex-1 flex-col">
                <main class="flex-1">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
