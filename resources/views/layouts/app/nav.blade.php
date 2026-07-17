{{-- Shared navigation content: primary nav, New booking, settings nav, and
     the user chip. Rendered inside BOTH the desktop sidebar (where the Alpine
     `collapsed` flag hides labels) and the mobile off-canvas drawer (which
     nests x-data="{ collapsed: false }" so labels always show). Every link
     carries an aria-label (the accessible name survives the collapsed,
     icon-only state) and a title tooltip while collapsed; tapping a link also
     closes the mobile drawer (`mobileNav` lives on the layout root, so the
     assignment is harmless on desktop). Expects $salon / $user / $roleLabel
     from the including layout. --}}

{{-- Primary nav. min-h-0 + overflow-y-auto: on short viewports THIS region
     scrolls while New booking, the settings links, and the user chip stay
     pinned and visible. --}}
<nav class="mt-1 flex min-h-0 flex-1 flex-col gap-0.5 overflow-y-auto px-3" aria-label="{{ __('Primary') }}">
    @if ($salon)
        {{-- Salon nav, in the canonical order (SPEC): Today · Calendar ·
             Check-in · Appointments · Clients · Reports · Services · Users ·
             Availability. Visibility mirrors each screen's own server-side
             authorization, so no link ever 403s — stylists simply see fewer. --}}
        <a href="{{ route('salon.show', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Today') }}" :title="collapsed ? '{{ __('Today') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.show') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.squares-2x2 variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Today') }}</span>
        </a>
        {{-- Calendar: managers get the master view, a stylist their own column. --}}
        @can('accessBookings', $salon)
            <a href="{{ route('salon.calendar', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Calendar') }}" :title="collapsed ? '{{ __('Calendar') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.calendar') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.calendar variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Calendar') }}</span>
            </a>
        @endcan
        {{-- Check-in: desk level only — hidden from stylists. --}}
        @can('manageBookings', $salon)
            <a href="{{ route('salon.appointments', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Check-in') }}" :title="collapsed ? '{{ __('Check-in') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.appointments') || request()->routeIs('salon.bookings.create') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.clipboard-document-check variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Check-in') }}</span>
            </a>
        @endcan
        {{-- Every appointment, searchable (stylists see their own; the page scopes it). --}}
        @can('accessBookings', $salon)
            <a href="{{ route('salon.appointments.all', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Appointments') }}" :title="collapsed ? '{{ __('Appointments') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.appointments.all') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.list-bullet variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Appointments') }}</span>
            </a>
        @endcan
        @can('manageBookings', $salon)
            <a href="{{ route('salon.clients', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Clients') }}" :title="collapsed ? '{{ __('Clients') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.clients') || request()->routeIs('salon.client') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.user-group variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Clients') }}</span>
            </a>
        @endcan
        @can('manage', $salon)
            <a href="{{ route('salon.reports', $salon) }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Reports') }}" :title="collapsed ? '{{ __('Reports') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.reports') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.chart-bar variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Reports') }}</span>
            </a>
        @endcan
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
               aria-label="{{ __('Users') }}" :title="collapsed ? '{{ __('Users') }}' : null"
               class="bts-nav-item {{ request()->routeIs('salon.staff') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.users variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Users') }}</span>
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
        @if ($user?->isAgencyOperator())
            {{-- Agency console nav: Dashboard → Salons → Reporting → Users.
                 ONE Salons entry — the salon picker (which carries the
                 manage gallery/list for salon managers). --}}
            <a href="{{ route('agency.overview') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Dashboard') }}" :title="collapsed ? '{{ __('Dashboard') }}' : null"
               class="bts-nav-item {{ request()->routeIs('agency.overview') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.building-office-2 variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Dashboard') }}</span>
            </a>
            <a href="{{ route('dashboard') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Salons') }}" :title="collapsed ? '{{ __('Salons') }}' : null"
               class="bts-nav-item {{ request()->routeIs('dashboard') || request()->routeIs('agency.salons.*') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.scissors variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Salons') }}</span>
            </a>
            <a href="{{ route('agency.reports') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Reporting') }}" :title="collapsed ? '{{ __('Reporting') }}' : null"
               class="bts-nav-item {{ request()->routeIs('agency.reports') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.chart-bar variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Reporting') }}</span>
            </a>
            <a href="{{ route('agency.users.index') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Users') }}" :title="collapsed ? '{{ __('Users') }}' : null"
               class="bts-nav-item {{ request()->routeIs('agency.users.*') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.users variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Users') }}</span>
            </a>
        @else
            {{-- Salon staff on the central host: their salons to open. --}}
            <a href="{{ route('dashboard') }}" wire:navigate @click="mobileNav = false"
               aria-label="{{ __('Salons') }}" :title="collapsed ? '{{ __('Salons') }}' : null"
               class="bts-nav-item {{ request()->routeIs('dashboard') ? 'bts-nav-item-active' : '' }}">
                <flux:icon.scissors variant="micro" class="shrink-0" />
                <span x-show="!collapsed" x-cloak>{{ __('Salons') }}</span>
            </a>
        @endif
    @endif
</nav>

{{-- New salon: the agency's primary action, pinned under its nav. --}}
@if (! $salon && $user?->isAgencyOperator())
    <div class="px-3 pb-1">
        <a href="{{ route('agency.salons.create') }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('New salon') }}" :title="collapsed ? '{{ __('New salon') }}' : null"
           class="bts-btn bts-btn-primary w-full"
           :class="collapsed ? '!px-0' : ''">
            <flux:icon.plus variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('New salon') }}</span>
        </a>
    </div>
@endif

{{-- New booking (salon context) --}}
@if ($salon)
    @can('manageBookings', $salon)
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
<nav class="flex shrink-0 flex-col gap-0.5 px-3 pb-1.5 pt-0.5" aria-label="{{ __('Settings') }}">
    {{-- My calendar: every salon member's own iCal feed (personal, not admin). --}}
    @if ($salon)
        <a href="{{ route('salon.account', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('My calendar') }}" :title="collapsed ? '{{ __('My calendar') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.account') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.calendar variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('My calendar') }}</span>
        </a>
    @endif
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
        <a href="{{ route('salon.widgets', $salon) }}" wire:navigate @click="mobileNav = false"
           aria-label="{{ __('Widgets') }}" :title="collapsed ? '{{ __('Widgets') }}' : null"
           class="bts-nav-item {{ request()->routeIs('salon.widgets') ? 'bts-nav-item-active' : '' }}">
            <flux:icon.code-bracket-square variant="micro" class="shrink-0" />
            <span x-show="!collapsed" x-cloak>{{ __('Widgets') }}</span>
        </a>
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

{{-- User chip — pinned at the bottom, always visible. --}}
<div class="shrink-0 border-t border-border px-2.5 py-2">
    <flux:dropdown position="top" align="start" class="w-full">
        <button type="button" data-test="sidebar-menu-button" aria-label="{{ __('Account menu') }}"
                class="flex w-full items-center gap-2.5 rounded-[13px] p-1.5 text-start transition hover:bg-muted">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-[#1E1D2A] text-[12.5px] font-semibold text-white">{{ $user?->initials() }}</span>
            <span x-show="!collapsed" x-cloak class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-[12.5px] font-semibold text-ink">{{ $user?->name }}</span>
                <span class="block truncate text-[11.5px] text-secondary">{{ $roleLabel }}</span>
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
            <form method="POST" action="{{ route('logout') }}" class="w-full" novalidate>
                @csrf
                <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer" data-test="logout-button">
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu>
    </flux:dropdown>
</div>
