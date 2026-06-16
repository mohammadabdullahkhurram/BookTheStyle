<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'BookTheStyle') }} — Salon scheduling</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="mx-auto flex min-h-svh max-w-3xl flex-col px-6 py-8">
            <header class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-5 fill-current text-white" />
                    </span>
                    <span class="font-serif text-lg tracking-tight">BookTheStyle</span>
                </div>

                <nav class="flex items-center gap-2 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-md border border-border bg-card px-4 py-2 font-medium shadow-xs transition hover:border-accent hover:text-accent">
                            {{ __('Dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="rounded-md bg-accent px-4 py-2 font-medium text-accent-foreground shadow-sm transition hover:bg-accent-hover">
                            {{ __('Log in') }}
                        </a>
                    @endauth
                </nav>
            </header>

            <main class="flex flex-1 flex-col justify-center py-16">
                <span class="mb-5 inline-flex w-fit items-center gap-2 rounded-full border border-border bg-accent-soft px-3 py-1 text-xs font-medium text-accent">
                    <span class="size-1.5 rounded-full bg-accent"></span>
                    {{ __('Multi-salon booking platform') }}
                </span>

                <h1 class="max-w-2xl font-serif text-4xl leading-tight tracking-tight text-ink sm:text-5xl">
                    {{ __('Every booking, every stylist, on one calendar.') }}
                </h1>

                <p class="mt-5 max-w-xl text-base leading-relaxed text-secondary">
                    {{ __('BookTheStyle is the scheduling backbone for boutique salons — services, stylists, availability, and check-in, kept in sync across the salon and its reminders.') }}
                </p>

                <div class="mt-8 flex items-center gap-3">
                    <a href="{{ route('login') }}" class="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-accent-foreground shadow-sm transition hover:bg-accent-hover">
                        {{ __('Sign in to your salon') }}
                    </a>
                    <span class="text-sm text-secondary">{{ __('Access is by invitation only.') }}</span>
                </div>
            </main>

            <footer class="border-t border-border pt-6 text-xs text-secondary">
                &copy; {{ date('Y') }} BookTheStyle. {{ __('Scheduling only — no payments.') }}
            </footer>
        </div>
    </body>
</html>
