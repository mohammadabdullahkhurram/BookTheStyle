<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('Book a call') }} — {{ config('app.name', 'BookTheStyle') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" href="/favicon-32.png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    {{-- Public "book a call" page (register.{domain}). No auth, no tenant data.
         It hosts a LeadConnector calendar embed. The page's CSP
         frame-src is widened (only on this host) via config('app.register_embed_
         frame_src') so the iframe is permitted once added. --}}
    <body class="min-h-screen bg-card text-ink antialiased">
        <div class="flex min-h-svh flex-col" style="background: radial-gradient(90% 60% at 50% -10%, var(--accent-tint) 0%, rgba(255,255,255,0) 55%);">
            {{-- Nav --}}
            <header class="mx-auto flex w-full max-w-[1120px] items-center gap-4 px-6 py-5 sm:px-8">
                <a href="{{ route('home') }}" class="flex items-center">
                    <x-app-logo class="h-9" />
                </a>
                <div class="flex-1"></div>
                <a href="{{ route('login') }}" class="text-[15px] font-semibold text-[#3A3833] transition hover:text-accent">{{ __('Log in') }}</a>
            </header>

            {{-- Intro + embed --}}
            <main class="mx-auto w-full max-w-[840px] flex-1 px-6 pb-14 pt-6 text-center sm:px-8">
                <h1 class="font-display text-[32px] font-extrabold leading-[1.08] tracking-[-0.02em] text-ink text-balance sm:text-[40px]">
                    {{ __("Let's get your salon set up") }}
                </h1>
                <p class="mx-auto mt-4 max-w-[520px] text-[17px] leading-[1.55] text-body">
                    {{ __("Pick a time that works and we'll walk you through BookTheStyle — no commitment, just a friendly look at how it fits your salon.") }}
                </p>

                <div class="mt-9 overflow-hidden rounded-[20px] border border-border bg-card text-start" style="box-shadow: 0 16px 48px rgba(28,27,26,.08);">
                    <div class="flex items-center justify-between border-b border-divider px-6 py-4">
                        <div class="font-display text-[16px] font-semibold text-ink">{{ __('Choose a time') }}</div>
                        <span class="text-[13px] text-faint">{{ __('30 min · video call') }}</span>
                    </div>

                    {{-- Bluejaypro's live GHL booking calendar. The iframe
                         origin is permitted by this host's CSP (SecurityHeaders:
                         register_embed_frame_src + marketing_embed_src). --}}
                    <div class="p-2 sm:p-4">
                        <div id="ghl-embed">
                            <iframe src="https://app.bluejaypro.com/widget/booking/me0hVAzF5a4VJqQ16UeX" style="width: 100%;border:none;overflow: hidden;min-height:640px" scrolling="no" id="3RXVqHja3OQPDp495XRn_1783979629218" title="{{ __('Book a call with Bluejaypro') }}"></iframe><br><script src="https://app.bluejaypro.com/js/form_embed.js" type="text/javascript"></script>
                        </div>
                    </div>
                </div>

                <p class="mx-auto mt-6 text-[13.5px] text-faint">{{ __('Prefer email? Reach us at hello@bluejaypro.com') }}</p>
            </main>
        </div>
    </body>
</html>
