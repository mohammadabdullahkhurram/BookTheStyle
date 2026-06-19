<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="relative flex min-h-svh flex-col items-center justify-center gap-7 p-6 md:p-10">
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(70% 50% at 50% 0%, var(--accent-tint) 0%, rgba(255,255,255,0) 55%);"></div>

            <a href="{{ route('home') }}" class="relative flex justify-center">
                <x-app-logo class="h-9" />
            </a>

            <div class="relative flex w-full max-w-sm flex-col gap-6 rounded-[20px] border border-border bg-card p-8 shadow-[0_16px_48px_rgba(28,27,26,.08)]">
                {{ $slot }}
            </div>

            <p class="relative text-[13px] text-faint">{{ __('Salon scheduling, by invitation only.') }}</p>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
