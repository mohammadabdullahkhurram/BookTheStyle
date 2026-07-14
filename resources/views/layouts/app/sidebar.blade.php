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
    <body class="min-h-screen bg-paper text-ink antialiased" @if ($bodyTheme = \App\Support\AppTheme::current($salon)) data-theme="{{ $bodyTheme }}" @endif>
        {{-- Keyboard users jump straight past the sidebar/top bar. --}}
        <a href="#main-content"
           class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-[12px] focus:bg-accent focus:px-5 focus:py-3 focus:text-[15px] focus:font-semibold focus:text-white">
            {{ __('Skip to content') }}
        </a>
        <div
            x-data="{ collapsed: (localStorage.getItem('bts-sidebar') === '1'), mobileNav: false }"
            x-init="$watch('collapsed', v => localStorage.setItem('bts-sidebar', v ? '1' : '0'))"
            class="flex min-h-svh"
        >
            {{-- Desktop sidebar (lg and up; phones/tablets get the top bar + drawer) --}}
            <aside
                :class="collapsed ? 'w-[68px]' : 'w-[220px]'"
                class="sticky top-0 z-20 hidden h-svh shrink-0 flex-col border-e border-border bg-card transition-[width] duration-200 lg:flex bts-chrome"
            >
                {{-- Logo + collapse --}}
                <div class="flex shrink-0 items-center gap-3 px-4 pb-2 pt-3">
                    <a href="{{ $salon ? route('salon.show', $salon) : route('dashboard') }}" wire:navigate
                       aria-label="{{ __('BookTheStyle') }}"
                       class="flex min-w-0 flex-1 items-center" :class="collapsed ? 'justify-center' : ''">
                        <x-app-logo x-show="!collapsed" x-cloak class="h-7" alt="" />
                        <x-app-logo-icon x-show="collapsed" x-cloak class="size-8" alt="" />
                    </a>
                    <button type="button" x-show="!collapsed" x-cloak @click="collapsed = true"
                            class="shrink-0 rounded-md p-1 text-faint transition hover:bg-muted hover:text-ink" aria-label="{{ __('Collapse sidebar') }}">
                        <flux:icon.chevron-left variant="micro" />
                    </button>
                </div>

                <button type="button" x-show="collapsed" x-cloak @click="collapsed = false"
                        class="mx-4 mb-1 flex items-center justify-center rounded-md p-1.5 text-faint transition hover:bg-muted hover:text-ink" aria-label="{{ __('Expand sidebar') }}">
                    <flux:icon.bars-3 variant="micro" />
                </button>

                @include('layouts.app.nav')
            </aside>

            {{-- Mobile off-canvas navigation drawer (same nav; labels always shown
                 via the nested collapsed:false scope). x-trap moves focus into the
                 drawer, restores it to the hamburger on close, and .noscroll locks
                 body scroll while open. Esc and the scrim both close it. --}}
            <div x-show="mobileNav" x-cloak
                 class="fixed inset-0 z-50 lg:hidden"
                 @keydown.escape.window="mobileNav = false">
                <div class="bts-scrim absolute inset-0 bg-ink/25" @click="mobileNav = false" aria-hidden="true"></div>

                <div x-data="{ collapsed: false }" x-trap.noscroll="mobileNav"
                     role="dialog" aria-modal="true" aria-label="{{ __('Navigation') }}"
                     class="bts-drawer-left bts-chrome relative flex h-full w-[280px] max-w-[85vw] flex-col overflow-y-auto border-e border-border bg-card shadow-xl">
                    <div class="flex items-center justify-between gap-3 px-4 pb-3 pt-4">
                        <a href="{{ $salon ? route('salon.show', $salon) : route('dashboard') }}" wire:navigate
                           @click="mobileNav = false" aria-label="{{ __('BookTheStyle') }}" class="flex min-w-0 items-center">
                            <x-app-logo class="h-8" alt="" />
                        </a>
                        <button type="button" @click="mobileNav = false" aria-label="{{ __('Close navigation') }}"
                                class="shrink-0 rounded-md p-1.5 text-faint transition hover:bg-muted hover:text-ink">
                            <flux:icon.x-mark variant="mini" />
                        </button>
                    </div>

                    @include('layouts.app.nav')
                </div>
            </div>

            {{-- Main --}}
            <div class="flex min-w-0 flex-1 flex-col">
                {{-- Mobile top bar: hamburger + logo (hidden from lg up). --}}
                <header class="bts-chrome sticky top-0 z-30 flex items-center gap-3 border-b border-border bg-card px-4 py-2.5 lg:hidden">
                    <button type="button" @click="mobileNav = true"
                            aria-label="{{ __('Open navigation') }}" :aria-expanded="mobileNav ? 'true' : 'false'"
                            class="rounded-md p-2 text-secondary transition hover:bg-muted hover:text-ink">
                        <flux:icon.bars-3 variant="mini" />
                    </button>
                    <a href="{{ $salon ? route('salon.show', $salon) : route('dashboard') }}" wire:navigate
                       aria-label="{{ __('BookTheStyle') }}" class="flex min-w-0 items-center gap-2.5">
                        <x-app-logo class="h-7" alt="" />
                        @if ($salon)
                            <span class="truncate text-[14px] font-semibold text-secondary">{{ $salon->name }}</span>
                        @endif
                    </a>
                </header>

                <main id="main-content" class="flex-1">
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
