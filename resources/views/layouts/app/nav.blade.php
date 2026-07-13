{{-- Shared navigation content: primary nav, New booking, settings nav, and
     the user chip. Rendered inside BOTH the desktop sidebar (where the Alpine
     `collapsed` flag hides labels) and the mobile off-canvas drawer (which
     nests x-data="{ collapsed: false }" so labels always show). Every link
     carries an aria-label (the accessible name survives the collapsed,
     icon-only state) and a title tooltip while collapsed; tapping a link also
     closes the mobile drawer (`mobileNav` lives on the layout root, so the
     assignment is harmless on desktop). Expects $salon / $user / $roleLabel
     from the including layout. --}}

{{-- Primary nav --}}
<nav class="mt-2 flex flex-1 flex-col gap-1 px-3" aria-label="{{ __('Primary') }}">
    @if ($salon)
        <a href="{{ route('salon.show', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Today') }}" :title="collapsed ? '{{ __('Today') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.show') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.squares-2x2 variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Today') }}</span>
        </a>
        {{-- Calendar: managers/front-desk get the master view, a
             stylist their own column. --}}
        @can('accessBookings', $salon)
            <a href="{{ route('salon.calendar', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Calendar') }}" :title="collapsed ? '{{ __('Calendar') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.calendar') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.calendar variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Calendar') }}</span>
            </a>
            {{-- The full, searchable list of every appointment
                 (stylists see their own; the page scopes it). --}}
            <a href="{{ route('salon.appointments.all', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Appointments') }}" :title="collapsed ? '{{ __('Appointments') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.appointments.all') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.list-bullet variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Appointments') }}</span>
            </a>
        @endcan
        {{-- Check-in: front-desk level only (owner/admin/front-desk).
             Hidden from stylists, who cannot change booking status. --}}
        @can('accessBookings', $salon)
            <a href="{{ route('salon.clients', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Clients') }}" :title="collapsed ? '{{ __('Clients') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.clients') || request()->routeIs('salon.client') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.user-group variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Clients') }}</span>
            </a>
        @endcan
        @can('manageBookings', $salon)
            <a href="{{ route('salon.appointments', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Check-in') }}" :title="collapsed ? '{{ __('Check-in') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.appointments') || request()->routeIs('salon.bookings.create') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.clipboard-document-check variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Check-in') }}</span>
            </a>
        @endcan

        {{-- Management links. Visibility mirrors each screen's own
             server-side authorization, so no link ever 403s. --}}
        @can('manageServices', $salon)
            <a href="{{ route('salon.services', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Services') }}" :title="collapsed ? '{{ __('Services') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.services') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.sparkles variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Services') }}</span>
            </a>
        @endcan
        @can('manageStaff', $salon)
            <a href="{{ route('salon.staff', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Staff') }}" :title="collapsed ? '{{ __('Staff') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.staff') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.users variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Staff') }}</span>
            </a>
        @endcan
        @can('manage', $salon)
            <a href="{{ route('salon.reports', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Reports') }}" :title="collapsed ? '{{ __('Reports') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.reports') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.chart-bar variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Reports') }}</span>
            </a>
            {{-- TEMPORARY: widget-design gallery — removed once a design is
                 chosen and applied to the real widget. --}}
            <a href="{{ route('salon.widgetdesigns', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Widget designs') }}" :title="collapsed ? '{{ __('Widget designs') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.widgetdesigns') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.rectangle-group variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Widget designs') }}</span>
            </a>
            {{-- TEMPORARY: design-direction gallery — removed once a
                 direction is chosen and rolled out app-wide. --}}
            <a href="{{ route('salon.uiux', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('UI/UX') }}" :title="collapsed ? '{{ __('UI/UX') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.uiux') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.swatch variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('UI/UX') }}</span>
            </a>
        @endcan
        {{-- Every member may VIEW staff schedules; editing is
             gated per stylist inside the page. --}}
        <a href="{{ route('salon.availability', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Availability') }}" :title="collapsed ? '{{ __('Availability') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.availability') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.clock variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Availability') }}</span>
        </a>
    @else
        <a href="{{ route('dashboard') }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Salons') }}" :title="collapsed ? '{{ __('Salons') }}' : null"
           class="bts-nav-item {{ request()->routeIs('dashboard') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.squares-2x2 variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Salons') }}</span>
        </a>
        @if ($user?->isAgencyOperator())
            <a href="{{ route('agency.overview') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Agency console') }}" :title="collapsed ? '{{ __('Agency console') }}' : null"
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
            <a href="{{ route('salon.bookings.create', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('New booking') }}" :title="collapsed ? '{{ __('New booking') }}' : null"
               class="bts-btn bts-btn-primary w-full"
               :class="collapsed ? '!px-0' : ''">
                <flux:icon.plus variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('New booking') }}</span>
            </a>
        </div>
    @endcan
@endif

{{-- Settings --}}
<nav class="px-3 pb-2 pt-1" aria-label="{{ __('Settings') }}">
    {{-- Setup wizard: managers, until the salon is marked live. --}}
    @if ($salon && $user?->can('manage', $salon) && $salon->onboarded_at === null)
        <a href="{{ route('salon.onboarding', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Setup') }}" :title="collapsed ? '{{ __('Setup') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.onboarding') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.rocket-launch variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Setup') }}</span>
        </a>
    @endif
    @if ($salon && $user?->can('manage', $salon))
        <a href="{{ route('salon.settings', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Settings') }}" :title="collapsed ? '{{ __('Settings') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.settings') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.cog-6-tooth variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Settings') }}</span>
        </a>
    @else
        <a href="{{ route('profile.edit') }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Settings') }}" :title="collapsed ? '{{ __('Settings') }}' : null"
           class="bts-nav-item {{ request()->routeIs('profile.*') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.cog-6-tooth variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Settings') }}</span>
        </a>
    @endif
</nav>

{{-- User chip --}}
<div class="border-t border-border p-3">
    <flux:dropdown position="top" align="start" class="w-full">
        <button type="button" data-test="sidebar-menu-button" aria-label="{{ __('Account menu') }}"
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
