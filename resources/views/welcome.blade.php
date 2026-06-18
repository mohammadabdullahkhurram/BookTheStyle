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
    {{-- Public marketing landing (apex). No auth, no tenant data. This is a
         minimal, on-system placeholder; the full visual design lands in a later
         styling pass. "Book a call" → register.{domain}; "Log in" → app.{domain}. --}}
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="mx-auto flex min-h-svh max-w-5xl flex-col px-6 py-8">
            <header class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-5 fill-current text-white" />
                    </span>
                    <span class="font-serif text-lg tracking-tight">BookTheStyle</span>
                </div>

                <nav class="flex items-center gap-2 text-sm">
                    <a href="{{ route('login') }}" class="rounded-md border border-border bg-card px-4 py-2 font-medium shadow-xs transition hover:border-accent hover:text-accent">
                        {{ __('Log in') }}
                    </a>
                </nav>
            </header>

            {{-- Hero --}}
            <main class="flex flex-1 flex-col justify-center py-16">
                <span class="mb-5 inline-flex w-fit items-center gap-2 rounded-full border border-border bg-accent-soft px-3 py-1 text-xs font-medium text-accent">
                    <span class="size-1.5 rounded-full bg-accent"></span>
                    {{ __('Multi-salon booking platform') }}
                </span>

                <h1 class="max-w-3xl font-serif text-4xl leading-tight tracking-tight text-ink sm:text-5xl">
                    {{ __('Every booking, every stylist, on one calendar.') }}
                </h1>

                <p class="mt-5 max-w-xl text-base leading-relaxed text-secondary">
                    {{ __('BookTheStyle is the scheduling backbone for boutique salons — services, stylists, availability, and check-in, kept in sync across the salon and its reminders.') }}
                </p>

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <a href="{{ route('book-call') }}" class="rounded-lg bg-accent px-5 py-2.5 text-sm font-medium text-accent-foreground shadow-sm transition hover:bg-accent-hover">
                        {{ __('Book a call') }}
                    </a>
                    <a href="{{ route('login') }}" class="rounded-lg border border-border bg-card px-5 py-2.5 text-sm font-medium text-ink shadow-xs transition hover:border-accent hover:text-accent">
                        {{ __('Log in') }}
                    </a>
                </div>

                {{-- Product band placeholder (restyled later). --}}
                <div class="mt-14 flex h-56 items-center justify-center rounded-2xl border border-dashed border-border bg-muted/40 text-sm text-secondary sm:h-72">
                    {{ __('Product preview') }}
                </div>

                {{-- Feature row. --}}
                <div class="mt-14 grid gap-6 sm:grid-cols-3">
                    @foreach ([
                        ['title' => __('One master calendar'), 'body' => __('See every stylist and booking for the salon in a single day or week view.')],
                        ['title' => __('App-managed availability'), 'body' => __('Working hours, breaks and time off are the source of truth for bookable slots.')],
                        ['title' => __('Reminders in sync'), 'body' => __('Bookings roll up and mirror out so clients get reminders without double entry.')],
                    ] as $feature)
                        <div class="flex flex-col gap-2 rounded-xl border border-border bg-card p-5 shadow-sm">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-accent-soft text-accent">
                                <flux:icon.sparkles variant="micro" />
                            </span>
                            <h3 class="font-serif text-lg text-ink">{{ $feature['title'] }}</h3>
                            <p class="text-sm leading-relaxed text-secondary">{{ $feature['body'] }}</p>
                        </div>
                    @endforeach
                </div>
            </main>

            <footer class="mt-12 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-6 text-xs text-secondary">
                <span>&copy; {{ date('Y') }} BookTheStyle. {{ __('Scheduling only — no payments.') }}</span>
                <a href="{{ route('book-call') }}" class="font-medium text-accent transition hover:text-accent-hover">{{ __('Book a call →') }}</a>
            </footer>
        </div>
    </body>
</html>
