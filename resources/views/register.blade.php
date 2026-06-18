<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ __('Book a call') }} — {{ config('app.name', 'BookTheStyle') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css'])
    </head>
    {{-- Public "book a call" page (register.{domain}). No auth, no tenant data.
         It hosts a GoHighLevel / LeadConnector calendar embed. The page's CSP
         frame-src is widened (only on this host) via config('app.register_embed_
         frame_src') so the iframe is permitted once added. Minimal placeholder
         for now; full visual design comes in the later styling pass. --}}
    <body class="min-h-screen bg-paper text-ink antialiased">
        <div class="mx-auto flex min-h-svh max-w-3xl flex-col px-6 py-8">
            <header class="flex items-center justify-between">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-5 fill-current text-white" />
                    </span>
                    <span class="font-serif text-lg tracking-tight">BookTheStyle</span>
                </a>
                <a href="{{ route('login') }}" class="text-sm font-medium text-secondary transition hover:text-accent">{{ __('Log in') }}</a>
            </header>

            <main class="flex flex-1 flex-col justify-center py-12">
                <h1 class="font-serif text-3xl leading-tight tracking-tight text-ink sm:text-4xl">
                    {{ __('Book a call with us') }}
                </h1>
                <p class="mt-4 max-w-xl text-base leading-relaxed text-secondary">
                    {{ __('Pick a time that works for you and we will walk you through setting up your salon on BookTheStyle.') }}
                </p>

                {{-- GHL calendar embed slot ---------------------------------------
                     Paste the GoHighLevel / LeadConnector booking iframe INSIDE
                     #ghl-embed when the embed code is provided, e.g.:

                       <iframe src="https://api.leadconnectorhq.com/widget/booking/XXXX"
                               style="width:100%;border:none;overflow:hidden"
                               scrolling="no" id="XXXX_booking"></iframe>
                       <script src="https://link.msgsndr.com/js/form_embed.js"></script>

                     The allowed iframe origin is set by REGISTER_EMBED_FRAME_SRC
                     (config app.register_embed_frame_src) and added to this page's
                     CSP frame-src by App\Http\Middleware\SecurityHeaders. --}}
                <div id="ghl-embed"
                     class="mt-8 flex min-h-[28rem] items-center justify-center rounded-2xl border border-dashed border-border bg-card p-6 text-center text-sm text-secondary shadow-sm">
                    {{ __('Scheduling calendar embed goes here.') }}
                </div>
            </main>

            <footer class="mt-8 border-t border-border pt-6 text-xs text-secondary">
                &copy; {{ date('Y') }} BookTheStyle. {{ __('Scheduling only — no payments.') }}
            </footer>
        </div>
    </body>
</html>
