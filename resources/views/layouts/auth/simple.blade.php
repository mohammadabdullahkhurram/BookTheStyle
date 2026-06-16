<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 p-6 md:p-10">
            <a href="{{ route('home') }}" class="flex flex-col items-center gap-3 font-medium" wire:navigate>
                <span class="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground shadow-sm">
                    <x-app-logo-icon class="size-6 fill-current text-white" />
                </span>
                <span class="font-serif text-xl tracking-tight text-ink">{{ config('app.name', 'BookTheStyle') }}</span>
            </a>

            <div class="flex w-full max-w-sm flex-col gap-6 rounded-xl border border-border bg-card p-8 shadow-md">
                {{ $slot }}
            </div>

            <p class="text-xs text-secondary">{{ __('Salon scheduling, by invitation only.') }}</p>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
