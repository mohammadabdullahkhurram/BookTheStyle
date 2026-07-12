<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-8 p-6 md:p-10">
            {{-- Warm boutique wash: a plum glow from above, a faint blush
                 rising from below — both token-driven, purely decorative. --}}
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(70% 50% at 50% 0%, var(--accent-tint) 0%, rgba(255,255,255,0) 55%);"></div>
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(60% 40% at 50% 100%, var(--color-blush) 0%, rgba(255,255,255,0) 60%);"></div>

            <a href="{{ route('home') }}" class="relative flex justify-center">
                <x-app-logo class="h-10" />
            </a>

            <div class="relative flex w-full max-w-sm flex-col gap-6 rounded-[var(--radius-modal)] border border-border bg-card p-8 shadow-[var(--shadow-overlay)]">
                {{ $slot }}
            </div>

            <p class="relative text-[13px] tracking-wide text-faint">{{ __('Salon scheduling, by invitation only.') }}</p>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
