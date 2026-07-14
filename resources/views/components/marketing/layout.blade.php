@props([
    'title' => 'Bluejaypro',
    'description' => 'Booking, CRM and local SEO for growing local businesses.',
])

{{--
    The Bluejaypro marketing-site shell: the landing page's exact visual
    language (the brand palette — base tokens on white, Fraunces display)
    grown into a shared header/nav + footer for the multi-page site. Public
    only — no auth, no tenant data. The mobile nav is a no-JS <details>
    disclosure so the pages stay dependency-free and accessible.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $description }}">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="/favicon-32.png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-card text-ink antialiased">
        <a href="#main-content"
           class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[60] focus:rounded-[12px] focus:bg-accent focus:px-5 focus:py-3 focus:text-[15px] focus:font-semibold focus:text-white">
            {{ __('Skip to content') }}
        </a>

        {{-- Header / nav --}}
        <header class="border-b border-divider">
            <div class="mx-auto flex max-w-[1120px] items-center gap-5 px-6 py-5 sm:px-8">
                <a href="{{ route('home') }}" class="flex items-center" aria-label="{{ __('Bluejaypro home') }}">
                    <x-app-logo class="h-9" alt="BookTheStyle by Bluejaypro" />
                </a>

                <nav class="ms-4 hidden items-center gap-6 md:flex" aria-label="{{ __('Main') }}">
                    @foreach ([['home', __('Home')], ['marketing.services', __('Services')], ['marketing.features', __('Features')], ['marketing.contact', __('Contact')]] as [$routeName, $label])
                        <a href="{{ route($routeName) }}"
                           @if (request()->routeIs($routeName)) aria-current="page" @endif
                           class="text-[15px] font-semibold transition {{ request()->routeIs($routeName) ? 'text-accent' : 'text-[#3A3833] hover:text-accent' }}">{{ $label }}</a>
                    @endforeach
                </nav>

                <div class="flex-1"></div>
                <a href="{{ route('login') }}" class="hidden px-2 text-[15px] font-semibold text-[#3A3833] transition hover:text-accent sm:block">{{ __('Log in') }}</a>
                <x-ui.button :href="route('book-call')" size="sm">{{ __('Book a call') }}</x-ui.button>

                {{-- Mobile nav: an accessible, no-JS disclosure. --}}
                <details class="relative md:hidden">
                    <summary class="flex size-10 cursor-pointer list-none items-center justify-center rounded-[10px] border border-input-border text-body [&::-webkit-details-marker]:hidden" aria-label="{{ __('Open navigation') }}">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75Zm0 5.25A.75.75 0 0 1 2.75 9.25h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10Zm0 5.25a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd"/></svg>
                    </summary>
                    <nav class="absolute right-0 top-12 z-40 flex w-52 flex-col gap-1 rounded-[14px] border border-border bg-card p-2 shadow-[0_16px_40px_rgba(28,27,26,.12)]" aria-label="{{ __('Main') }}">
                        @foreach ([['home', __('Home')], ['marketing.services', __('Services')], ['marketing.features', __('Features')], ['marketing.contact', __('Contact')]] as [$routeName, $label])
                            <a href="{{ route($routeName) }}"
                               class="rounded-[10px] px-3 py-2.5 text-[15px] font-semibold {{ request()->routeIs($routeName) ? 'bg-accent-tint text-accent-ink' : 'text-body hover:bg-muted' }}">{{ $label }}</a>
                        @endforeach
                        <a href="{{ route('login') }}" class="rounded-[10px] px-3 py-2.5 text-[15px] font-semibold text-body hover:bg-muted">{{ __('Log in') }}</a>
                    </nav>
                </details>
            </div>
        </header>

        <main id="main-content">
            {{ $slot }}
        </main>

        {{-- Footer --}}
        <footer class="mt-20 border-t border-divider">
            <div class="mx-auto grid max-w-[1120px] gap-10 px-6 py-12 sm:px-8 md:grid-cols-3">
                <div>
                    <x-app-logo class="h-7" alt="BookTheStyle by Bluejaypro" />
                    <p class="mt-3 max-w-[280px] text-[14px] leading-[1.6] text-body">{{ __('Bluejaypro — a marketing agency helping local businesses grow: bookings, CRM, and search.') }}</p>
                </div>
                <div>
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('What we do') }}</h2>
                    <ul class="mt-3 flex flex-col gap-2 text-[14.5px]">
                        <li><a href="{{ route('marketing.services') }}#bookthestyle" class="text-body transition hover:text-accent">{{ __('BookTheStyle — salon booking') }}</a></li>
                        <li><a href="{{ route('marketing.services') }}#loopflo" class="text-body transition hover:text-accent">{{ __('Loopflo — the CRM') }}</a></li>
                        <li><a href="{{ route('marketing.services') }}#seo" class="text-body transition hover:text-accent">{{ __('Local SEO') }}</a></li>
                        <li><a href="{{ route('marketing.features') }}" class="text-body transition hover:text-accent">{{ __('Product features') }}</a></li>
                    </ul>
                </div>
                <div>
                    <h2 class="text-[13px] font-semibold uppercase tracking-[0.06em] text-secondary">{{ __('Contact') }}</h2>
                    <address class="mt-3 flex flex-col gap-2 text-[14.5px] not-italic text-body">
                        <span>9447 Crystal Shore Ln, Floor 2<br>Elk Grove, CA 95758</span>
                        <a href="tel:+19168948575" class="transition hover:text-accent">(916) 894-8575</a>
                        <a href="mailto:hello@bluejaypro.com" class="transition hover:text-accent">hello@bluejaypro.com</a>
                    </address>
                </div>
            </div>
            <div class="mx-auto flex max-w-[1120px] flex-wrap items-center gap-4 px-6 pb-8 text-[13px] text-faint sm:px-8">
                <span>&copy; {{ date('Y') }} Bluejaypro. {{ __('BookTheStyle is a Bluejaypro product. Scheduling only — no payments.') }}</span>
                <div class="flex-1"></div>
                <a href="{{ route('login') }}" class="transition hover:text-accent">{{ __('Log in') }}</a>
            </div>
        </footer>
    </body>
</html>
