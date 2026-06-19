<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'BookTheStyle') }} — Salon scheduling, beautifully handled</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    {{-- Public marketing landing (apex). No auth, no tenant data. "Book a call"
         → register.{domain}; "Log in" → app.{domain}. --}}
    <body class="min-h-screen bg-card text-ink antialiased">
        {{-- Nav --}}
        <header class="mx-auto flex max-w-[1120px] items-center gap-4 px-6 py-5 sm:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5">
                <span class="flex size-9 items-center justify-center rounded-[12px] bg-accent text-white shadow-md">
                    <x-app-logo-icon class="size-5 fill-current" />
                </span>
                <span class="font-display text-[18px] font-extrabold tracking-[-0.015em]"><span class="text-accent">Book</span><span class="text-ink">TheStyle</span></span>
            </a>
            <div class="flex-1"></div>
            <a href="{{ route('login') }}" class="px-2 text-[15px] font-semibold text-[#3A3833] transition hover:text-accent">{{ __('Log in') }}</a>
            <x-ui.button :href="route('book-call')" size="sm">{{ __('Book a call') }}</x-ui.button>
        </header>

        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0" style="background: radial-gradient(80% 70% at 50% -8%, var(--accent-tint) 0%, rgba(255,255,255,0) 60%);"></div>
            <div class="relative mx-auto max-w-[860px] px-6 pb-7 pt-14 text-center sm:px-8">
                <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-border bg-card px-3.5 py-1.5 text-[13px] font-semibold text-secondary shadow-xs">
                    <span class="size-[7px] rounded-full bg-accent"></span>{{ __('Salon scheduling, beautifully handled') }}
                </div>
                <h1 class="font-display text-[40px] font-extrabold leading-[1.04] tracking-[-0.025em] text-ink text-balance sm:text-[60px]">
                    {{ __('Booking that runs your salon, not the other way around') }}
                </h1>
                <p class="mx-auto mt-6 max-w-[560px] text-[18px] leading-[1.55] text-body">
                    {{ __('A calm, premium calendar for every chair in the room — bookings from the front desk, voice, and chat, all in one place.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <x-ui.button :href="route('book-call')">{{ __('Book a call') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('login')">{{ __('Log in') }}</x-ui.button>
                </div>
            </div>
        </section>

        {{-- Product band: per-stylist calendar preview --}}
        @php($cols = [
            ['name' => 'Simone', 'initials' => 'SI', 'avatar' => '#6E9968', 'bg' => '#E7EFE4', 'border' => '#D5E4D0', 'ink' => '#3E5C3A', 'blocks' => [['t' => '9:00', 'n' => 'Brenda M.', 'top' => 8, 'h' => 52], ['t' => '10:30', 'n' => 'Craig M.', 'top' => 104, 'h' => 78]]],
            ['name' => 'Maya', 'initials' => 'MA', 'avatar' => '#C76A8C', 'bg' => '#FBE7EE', 'border' => '#F2D2DE', 'ink' => '#8E3D5A', 'blocks' => [['t' => '9:30', 'n' => 'Alena G.', 'top' => 44, 'h' => 62], ['t' => '12:30', 'n' => 'Desirae S.', 'top' => 184, 'h' => 52]]],
            ['name' => 'Jonah', 'initials' => 'JO', 'avatar' => '#D49A4E', 'bg' => '#FBEFD6', 'border' => '#EEDDB6', 'ink' => '#8A5A1E', 'blocks' => [['t' => '11:00', 'n' => 'Megan W.', 'top' => 128, 'h' => 62]]],
            ['name' => 'Elise', 'initials' => 'EL', 'avatar' => '#8C7FE0', 'bg' => '#EAE6FB', 'border' => '#D8D1F2', 'ink' => '#4B3F9E', 'blocks' => [['t' => '9:00', 'n' => 'James H.', 'top' => 8, 'h' => 62], ['t' => '10:30', 'n' => 'Amy J.', 'top' => 104, 'h' => 78]]],
        ])
        <section class="mx-auto mt-5 max-w-[1040px] px-6 sm:px-8">
            <div class="overflow-hidden rounded-t-[20px] border border-b-0 border-border bg-card" style="box-shadow: 0 24px 60px rgba(28,27,26,.10);">
                <div class="flex items-center gap-2 border-b border-divider px-4 py-3">
                    <span class="size-[11px] rounded-full bg-[#E6A6A6]"></span>
                    <span class="size-[11px] rounded-full bg-[#EBCF9A]"></span>
                    <span class="size-[11px] rounded-full bg-[#A9D2A4]"></span>
                    <div class="flex flex-1 justify-center"><div class="rounded-full bg-row px-3.5 py-1 text-[12.5px] text-faint">app.bookthestyle.com</div></div>
                </div>
                <div class="flex min-h-[300px]">
                    <div class="flex w-12 shrink-0 flex-col items-end gap-[34px] border-e border-row pe-2 pt-[44px]">
                        @foreach (['9', '10', '11', '12', '1'] as $h)
                            <span class="font-display text-[11px] text-fainter">{{ $h }}</span>
                        @endforeach
                    </div>
                    @foreach ($cols as $col)
                        <div class="relative flex-1 border-e border-row last:border-e-0">
                            <div class="flex items-center gap-2 border-b border-row px-3 py-2.5">
                                <span class="flex size-[22px] items-center justify-center rounded-full font-display text-[9px] font-semibold text-white" style="background-color: {{ $col['avatar'] }};">{{ $col['initials'] }}</span>
                                <span class="text-[12.5px] font-semibold text-ink">{{ $col['name'] }}</span>
                            </div>
                            <div class="relative h-[240px] p-2">
                                @foreach ($col['blocks'] as $b)
                                    <div class="absolute inset-x-2 overflow-hidden rounded-[9px] border px-2.5 py-1.5" style="top: {{ $b['top'] }}px; height: {{ $b['h'] }}px; background-color: {{ $col['bg'] }}; border-color: {{ $col['border'] }};">
                                        <div class="font-display text-[10px] font-semibold" style="color: {{ $col['ink'] }};">{{ $b['t'] }}</div>
                                        <div class="text-[11.5px] font-semibold text-[#2a2724]">{{ $b['n'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section class="mx-auto max-w-[1040px] px-6 pb-6 pt-[72px] sm:px-8">
            <div class="grid gap-7 sm:grid-cols-3">
                <div>
                    <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                        <flux:icon.calendar variant="outline" class="size-6" />
                    </div>
                    <h3 class="font-display text-[18px] font-bold text-ink">{{ __('Per-stylist day view') }}</h3>
                    <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Every chair in one calm column — see who is busy and who is free at a glance.') }}</p>
                </div>
                <div>
                    <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                        <flux:icon.inbox-arrow-down variant="outline" class="size-6" />
                    </div>
                    <h3 class="font-display text-[18px] font-bold text-ink">{{ __('Bookings from anywhere') }}</h3>
                    <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Front desk, voice AI, and chat bookings all land in the same place, automatically.') }}</p>
                </div>
                <div>
                    <div class="mb-4 flex size-[46px] items-center justify-center rounded-[13px] bg-accent-tint text-accent">
                        <flux:icon.check-circle variant="outline" class="size-6" />
                    </div>
                    <h3 class="font-display text-[18px] font-bold text-ink">{{ __('One-tap check-in') }}</h3>
                    <p class="mt-1.5 text-[14.5px] leading-[1.55] text-body">{{ __('Move clients from arrived to in service to complete with a single tap.') }}</p>
                </div>
            </div>
        </section>

        {{-- Footer --}}
        <footer class="mt-14 border-t border-divider">
            <div class="mx-auto flex max-w-[1040px] flex-wrap items-center gap-4 px-6 py-8 sm:px-8">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-[30px] items-center justify-center rounded-[9px] bg-accent text-white">
                        <x-app-logo-icon class="size-4 fill-current" />
                    </span>
                    <span class="font-display text-[15px] font-bold text-ink">BookTheStyle</span>
                </div>
                <div class="flex-1"></div>
                <div class="flex items-center gap-6 text-[14px] text-secondary">
                    <a href="{{ route('login') }}" class="transition hover:text-accent">{{ __('Log in') }}</a>
                    <a href="{{ route('book-call') }}" class="font-semibold text-accent transition hover:text-accent-hover">{{ __('Book a call') }}</a>
                </div>
            </div>
            <div class="mx-auto max-w-[1040px] px-6 pb-7 text-[13px] text-fainter sm:px-8">&copy; {{ date('Y') }} BookTheStyle. {{ __('Scheduling only — no payments.') }}</div>
        </footer>
    </body>
</html>
