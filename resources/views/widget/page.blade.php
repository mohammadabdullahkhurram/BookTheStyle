{{--
    The embeddable public booking page — the salon's ONLY customer-facing
    booking surface. Vitrine liquid-glass visual language over a Duet split
    layout: a branded summary pane beside the active step on desktop,
    stacking to a single column (compact summary bar) on phones. Themed per
    salon by WidgetBranding (logo, accent set, secondary, surface, font).

    Self-contained: token CSS via the compiled stylesheet, self-hosted fonts,
    and a dependency-free inline script — no Livewire, no session, nothing
    that breaks when third-party cookies are blocked inside an iframe. Posts
    its rendered height to the parent (widget.js) for auto-resizing.

    Receives from WidgetController@page: $salon, $branding, $catalogue,
    $currency, $preselectService, $widgetToken, $maxDate.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>{{ __('Book at :salon', ['salon' => $salon->name]) }}</title>
    @fonts
    @vite('resources/css/app.css')
    <style>
        :root {
            --accent: {{ $branding['accent']['accent'] }};
            --accent-hover: {{ $branding['accent']['hover'] }};
            --accent-tint: {{ $branding['accent']['tint'] }};
            --accent-ink: {{ $branding['accent']['ink'] }};
            --wb-secondary: {{ $branding['secondary'] }};
            --wb-surface: {{ $branding['surface'] }};
            --wb-display: {!! $branding['font']['display'] !!};
            --wb-body: {!! $branding['font']['body'] !!};
        }

        /* ── Vitrine: liquid glass over a branded gradient backdrop ── */
        body { font-family: var(--wb-body); background-color: var(--wb-surface); }
        /* A body background with attachment:fixed silently fails to paint
           inside the auto-resizing embed iframe (WebKit), leaving the glass
           floating on flat white — so the scenery is its own fixed layer:
           brand-tinted gradient washes plus two soft colour blooms the
           panels' backdrop-filter actually has something to blur. */
        .wb-backdrop {
            position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none;
            background:
                radial-gradient(60% 55% at 10% 0%, color-mix(in srgb, var(--accent) 18%, var(--wb-surface)), transparent 62%),
                radial-gradient(55% 48% at 94% 6%, color-mix(in srgb, var(--wb-secondary) 22%, var(--wb-surface)), transparent 60%),
                radial-gradient(70% 55% at 50% 108%, color-mix(in srgb, var(--accent) 13%, var(--wb-surface)), transparent 62%),
                var(--wb-surface);
        }
        .wb-backdrop::before, .wb-backdrop::after { content: ''; position: absolute; border-radius: 999px; filter: blur(52px); }
        .wb-backdrop::before { width: 30rem; height: 30rem; left: -10rem; top: -12rem; background: color-mix(in srgb, var(--accent) 32%, transparent); }
        .wb-backdrop::after { width: 24rem; height: 24rem; right: -8rem; top: 30%; background: color-mix(in srgb, var(--wb-secondary) 30%, transparent); }
        .wb-display { font-family: var(--wb-display); }
        .wb-glass {
            background: rgb(255 255 255 / .46);
            -webkit-backdrop-filter: blur(20px) saturate(1.5);
            backdrop-filter: blur(20px) saturate(1.5);
            border: 1px solid rgb(255 255 255 / .7);
            border-radius: 20px;
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .9), 0 12px 36px rgb(40 25 40 / .14);
        }
        .wb-opt {
            display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 12px;
            border-radius: 14px; padding: 12px 14px; text-align: start; font-size: 15px; font-weight: 600;
            background: rgb(255 255 255 / .48);
            border: 1.5px solid rgb(255 255 255 / .7);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8);
            transition: border-color .15s ease, background-color .15s ease;
            cursor: pointer; min-height: 48px;
        }
        .wb-opt:hover { border-color: color-mix(in srgb, var(--accent) 55%, transparent); }
        .wb-opt[aria-pressed='true'] {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 12%, rgb(255 255 255 / .6));
        }
        .wb-chip {
            border-radius: 12px; padding: 9px 13px; font-size: 14px; font-weight: 600;
            background: rgb(255 255 255 / .48); border: 1.5px solid rgb(255 255 255 / .7);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8); cursor: pointer; min-height: 44px;
            transition: border-color .15s ease;
        }
        .wb-chip:hover { border-color: color-mix(in srgb, var(--accent) 55%, transparent); }
        .wb-field {
            width: 100%; min-height: 48px; border-radius: 12px; padding: 10px 13px; font-size: 15px;
            background: rgb(255 255 255 / .72); border: 1.5px solid rgb(255 255 255 / .85);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .9); color: var(--color-body);
        }
        .wb-field:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
        .wb-cta {
            display: flex; width: 100%; min-height: 48px; align-items: center; justify-content: center;
            border-radius: 99px; font-size: 15px; font-weight: 600; color: #fff; cursor: pointer;
            background: var(--accent); border: 1px solid rgb(255 255 255 / .35);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .3), 0 8px 22px color-mix(in srgb, var(--accent) 38%, transparent);
            transition: background-color .15s ease;
        }
        .wb-cta:hover { background: var(--accent-hover); }
        .wb-cta:disabled { opacity: .5; pointer-events: none; }
        .wb-ghost {
            display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px;
            border-radius: 99px; padding: 0 18px; font-size: 14px; font-weight: 600; cursor: pointer;
            background: rgb(255 255 255 / .55); border: 1.5px solid rgb(255 255 255 / .8);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .85); color: var(--color-body);
        }
        .wb-overline {
            font-size: 11.5px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
            color: var(--accent-ink);
        }
        .wb-sumline { display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; padding: 7px 0; }
        .wb-sumline + .wb-sumline { border-top: 1px solid rgb(255 255 255 / .6); }

        /* ── Inline availability calendar (replaces the native date input) ── */
        .wb-cal {
            border-radius: 16px; padding: 12px;
            background: rgb(255 255 255 / .42);
            border: 1.5px solid rgb(255 255 255 / .7);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8);
        }
        .wb-cal-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .wb-cal-nav {
            display: flex; min-width: 40px; min-height: 40px; align-items: center; justify-content: center;
            border-radius: 10px; cursor: pointer; color: var(--color-body);
            background: rgb(255 255 255 / .55); border: 1.5px solid rgb(255 255 255 / .75);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8); transition: border-color .15s ease;
        }
        .wb-cal-nav:hover { border-color: color-mix(in srgb, var(--accent) 55%, transparent); }
        .wb-cal-nav:disabled { opacity: .35; pointer-events: none; }
        .wb-cal-dow, .wb-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
        .wb-cal-dow span {
            padding: 4px 0; text-align: center; font-size: 11px; font-weight: 600;
            letter-spacing: .08em; text-transform: uppercase; color: var(--color-secondary);
        }
        .wb-cal-grid[aria-busy='true'] { opacity: .55; }
        /* Available vs not is never colour-alone: open days are raised glass
           tiles (semibold, accent dot); closed days are flat, regular, faint. */
        .wb-day {
            position: relative; display: flex; align-items: center; justify-content: center;
            min-height: 42px; width: 100%; border-radius: 10px; font-size: 14px; font-weight: 400;
            color: var(--color-faint); background: transparent; border: 1.5px solid transparent; cursor: default;
        }
        .wb-day[data-available='true'] {
            font-weight: 600; color: var(--color-ink); cursor: pointer;
            background: rgb(255 255 255 / .55); border-color: rgb(255 255 255 / .75);
            box-shadow: inset 0 1px 0 rgb(255 255 255 / .8); transition: border-color .15s ease;
        }
        .wb-day[data-available='true']::after {
            content: ''; position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 99px; background: var(--accent);
        }
        .wb-day[data-available='true']:hover { border-color: color-mix(in srgb, var(--accent) 55%, transparent); }
        .wb-day[aria-pressed='true'] { background: var(--accent); border-color: var(--accent); color: #fff; }
        .wb-day[aria-pressed='true']::after { background: #fff; }
        .wb-day:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

        /* ── Duet: split from 760px; single column (summary bar first) below ── */
        .wb-duet { display: grid; gap: 16px; align-items: start; }
        @media (min-width: 760px) {
            .wb-duet { grid-template-columns: 260px minmax(0, 1fr); gap: 20px; }
            .wb-pane-summary { position: sticky; top: 16px; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body class="text-ink antialiased">
    <div class="wb-backdrop" aria-hidden="true"></div>
    <main class="mx-auto w-full max-w-4xl p-4 sm:p-6" id="bts-widget"
          data-salon="{{ $salon->name }}"
          data-preselect="{{ $preselectService ?? '' }}">

        <div class="wb-duet">
            {{-- ── Duet pane 1: branded summary/context ── --}}
            <aside class="wb-pane-summary wb-glass p-5" aria-label="{{ __('Booking summary') }}">
                @if ($branding['logo_url'])
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $salon->name }}" class="mb-3 max-h-12 w-auto max-w-[180px] object-contain" />
                @endif
                <p class="wb-overline">{{ __('Book an appointment') }}</p>
                <h1 class="wb-display mt-1 text-[21px] font-semibold leading-tight">{{ $salon->name }}</h1>

                <div id="bts-summary" class="mt-4 hidden">
                    <div id="bts-summary-lines"></div>
                    <div class="wb-sumline" id="bts-summary-total" style="font-weight:600; border-top: 1.5px solid rgb(255 255 255 / .8);"></div>
                </div>
                <p id="bts-summary-empty" class="mt-4 text-[13.5px] text-secondary">{{ __('Choose one or more services to begin.') }}</p>
            </aside>

            {{-- ── Duet pane 2: the active step ── --}}
            <div>
                <div id="bts-error" class="mb-3 hidden rounded-[12px] px-3 py-2.5 text-[14px]" style="background:rgb(248 227 227 / .9);color:#A23A3A" role="alert"></div>

                {{-- Step 1: services (multi-select) --}}
                <section data-step="service" class="wb-glass p-5">
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Choose your services') }}</h2>
                    <p class="mt-0.5 text-[13.5px] text-secondary">{{ __('Pick one or more — they run back to back in one visit.') }}</p>
                    <div id="bts-services" class="mt-3 grid gap-2"></div>
                    <p class="mt-3 hidden text-[14px] text-secondary" id="bts-no-services">{{ __('Online booking is not available right now. Please contact the salon directly.') }}</p>
                    <button type="button" id="bts-continue" class="wb-cta mt-4" hidden></button>
                </section>

                {{-- Step 2: stylist — auto (one stylist / team) or manual per service --}}
                <section data-step="stylist" hidden class="wb-glass p-5">
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Choose a stylist') }}</h2>
                    <p class="mt-0.5 text-[13.5px] text-secondary" id="bts-stylist-hint">{{ __('One stylist takes your whole visit when possible.') }}</p>
                    <div id="bts-stylists" class="mt-3 grid gap-2"></div>
                    {{-- Manual mode is an opt-in, so casual bookers never see it --}}
                    <button type="button" id="bts-manual-toggle" class="wb-ghost mt-3" hidden aria-expanded="false">{{ __('Choose stylists per service') }}</button>
                    <div id="bts-manual" hidden class="mt-3 grid gap-3">
                        <div id="bts-manual-rows" class="grid gap-3"></div>
                        <button type="button" id="bts-manual-continue" class="wb-cta">{{ __('See available times') }}</button>
                    </div>
                </section>

                {{-- Step 3: date + time — inline availability calendar, no native picker --}}
                <section data-step="time" hidden class="wb-glass p-5">
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Pick a date and time') }}</h2>
                    <p class="mt-0.5 text-[13.5px] text-secondary">{{ __('Days that fit your whole visit are highlighted.') }}</p>
                    <div id="bts-cal" class="wb-cal mt-3">
                        <div class="wb-cal-head">
                            <button type="button" id="bts-cal-prev" class="wb-cal-nav" aria-label="{{ __('Previous month') }}">
                                <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1-.02 1.06L8.832 10l3.938 3.71a.75.75 0 1 1-1.04 1.08l-4.5-4.25a.75.75 0 0 1 0-1.08l4.5-4.25a.75.75 0 0 1 1.06.02Z" clip-rule="evenodd"/></svg>
                            </button>
                            <h3 id="bts-cal-title" class="wb-display text-[15px] font-semibold" aria-live="polite"></h3>
                            <button type="button" id="bts-cal-next" class="wb-cal-nav" aria-label="{{ __('Next month') }}">
                                <svg viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/></svg>
                            </button>
                        </div>
                        <div id="bts-cal-dow" class="wb-cal-dow" aria-hidden="true"></div>
                        <div id="bts-cal-grid" class="wb-cal-grid" role="group" aria-label="{{ __('Choose a date') }}"></div>
                    </div>
                    <p id="bts-day-label" class="mt-3 hidden text-[13px] font-semibold text-secondary"></p>
                    <div id="bts-slots" class="mt-2 flex flex-wrap gap-2" aria-live="polite"></div>
                    <p id="bts-slots-empty" class="mt-2 hidden text-[14px] text-secondary">{{ __('No open times fit the whole visit that day — try another date.') }}</p>
                </section>

                {{-- Step 4: details --}}
                <section data-step="details" hidden class="wb-glass p-5">
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Your details') }}</h2>
                    <form id="bts-form" class="mt-3 grid gap-3" novalidate>
                        <div>
                            <label class="block text-[13px] font-semibold text-secondary" for="bts-name">{{ __('Name') }}</label>
                            <input id="bts-name" name="name" required autocomplete="name" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-secondary" for="bts-phone">{{ __('Phone') }}</label>
                            <input id="bts-phone" name="phone" type="tel" required autocomplete="tel" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-secondary" for="bts-email">{{ __('Email (optional)') }}</label>
                            <input id="bts-email" name="email" type="email" autocomplete="email" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="block text-[13px] font-semibold text-secondary" for="bts-notes">{{ __('Notes (optional)') }}</label>
                            <textarea id="bts-notes" name="notes" rows="2" maxlength="500" class="wb-field mt-1"></textarea>
                        </div>
                        {{-- Honeypot: hidden from humans; anything typed here fails the bot gate. --}}
                        <div class="absolute -left-[9999px] top-auto" aria-hidden="true">
                            <label for="bts-website">Website</label>
                            <input id="bts-website" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <button type="submit" id="bts-submit" class="wb-cta">{{ __('Confirm booking') }}</button>
                    </form>
                </section>

                {{-- Step 5: confirmed --}}
                <section data-step="confirmed" hidden class="wb-glass p-5">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full"
                         style="background: color-mix(in srgb, var(--accent) 14%, rgb(255 255 255 / .7)); color: var(--accent-ink); box-shadow: inset 0 1px 0 rgb(255 255 255 / .9);">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7A1 1 0 1 1 4.7 8.3l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                    </div>
                    <h2 class="wb-display mt-3 text-center text-[19px] font-semibold">{{ __("You're booked") }}</h2>
                    <p class="mt-1 text-center text-[14px] text-secondary" id="bts-confirmation"></p>
                    <button type="button" id="bts-again" class="wb-ghost mx-auto mt-4 flex">{{ __('Book another appointment') }}</button>
                </section>

                {{-- Back link, visible mid-flow --}}
                <div class="mt-3 flex justify-start" id="bts-footer" hidden>
                    <button type="button" id="bts-back" class="wb-ghost">{{ __('Back') }}</button>
                </div>
            </div>
        </div>
    </main>

    <script>
    (function () {
        'use strict';
        var CATALOGUE = @json($catalogue);
        var TOKEN = @json($widgetToken);
        var CURRENCY = @json($currency);
        var MIN_DATE = @json($minDate);
        var MAX_DATE = @json($maxDate);
        var API = {
            availability: @json(route('salon.widget.availability', ['salon' => $salon->slug])),
            month: @json(route('salon.widget.month', ['salon' => $salon->slug])),
            book: @json(route('salon.widget.book', ['salon' => $salon->slug])),
        };
        var I18N = {
            any: @json(__('Any available stylist')),
            loading: @json(__('Finding open times…')),
            failed: @json(__('Something went wrong. Please try again.')),
            taken: @json(__('That time was just taken — pick another:')),
            required: @json(__('Name and phone are required.')),
            continueOne: @json(__('Continue with 1 service')),
            continueMany: @json(__('Continue with :count services')),
            min: @json(__('min')),
            services: @json(__('Services')),
            stylist: @json(__('Stylist')),
            when: @json(__('When')),
            total: @json(__('Estimated total')),
            varies: @json(__('some prices vary')),
            available: @json(__('available')),
            unavailable: @json(__('unavailable')),
            timesFor: @json(__('Open times for :date')),
            anyStylist: @json(__('Any available')),
            teamNote: @json(__("No single stylist offers everything you picked — we'll arrange your services back to back with the team.")),
            manualOn: @json(__('Choose stylists per service')),
            manualOff: @json(__('Let us arrange it instead')),
        };

        var state = { step: 'service', services: [], stylist: 'any', mode: 'auto', assignments: {}, date: null, slot: null };
        var $ = function (id) { return document.getElementById(id); };
        var steps = document.querySelectorAll('[data-step]');

        // -- auto-resize: tell the parent loader our height ---------------
        function postHeight() {
            var h = document.documentElement.scrollHeight;
            window.parent.postMessage({ type: 'bts:resize', height: h }, '*');
        }
        if (window.ResizeObserver) {
            new ResizeObserver(postHeight).observe(document.body);
        }
        window.addEventListener('load', postHeight);

        function money(cents) {
            try {
                return new Intl.NumberFormat(undefined, { style: 'currency', currency: CURRENCY, minimumFractionDigits: cents % 100 === 0 ? 0 : 2 }).format(cents / 100);
            } catch (e) {
                return (cents / 100).toFixed(2);
            }
        }

        function show(step) {
            state.step = step;
            steps.forEach(function (el) { el.hidden = el.getAttribute('data-step') !== step; });
            $('bts-footer').hidden = step === 'service' || step === 'confirmed';
            renderSummary();
            error('');
            postHeight();
        }

        function error(message) {
            var el = $('bts-error');
            el.textContent = message;
            el.classList.toggle('hidden', message === '');
            postHeight();
        }

        function option(label, sub, onclick, pressed) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'wb-opt';
            b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
            var span = document.createElement('span');
            span.textContent = label;
            b.appendChild(span);
            if (sub) {
                var s = document.createElement('span');
                s.className = 'shrink-0 text-[13px] font-normal text-secondary';
                s.textContent = sub;
                b.appendChild(s);
            }
            b.addEventListener('click', onclick);
            return b;
        }

        // -- Duet summary pane (all chosen services, combined time + price) --
        function renderSummary() {
            var lines = $('bts-summary-lines');
            lines.textContent = '';
            var hasContent = state.services.length > 0;
            $('bts-summary').classList.toggle('hidden', !hasContent);
            $('bts-summary-empty').classList.toggle('hidden', hasContent);
            if (!hasContent) { return; }

            // With a chosen slot, each service line names ITS stylist and leg
            // time — the arrangement reads at a glance ("9:00 AM · Maya").
            var legs = state.slot && state.slot.stylists ? state.slot.stylists : null;
            state.services.forEach(function (service, index) {
                var row = document.createElement('div');
                row.className = 'wb-sumline';
                var name = document.createElement('span');
                name.textContent = service.name;
                var meta = document.createElement('span');
                meta.className = 'text-secondary';
                var leg = legs && legs[index];
                meta.textContent = leg
                    ? leg.time + ' · ' + leg.stylist
                    : service.duration_minutes + ' ' + I18N.min + (service.price ? ' · ' + service.price : '');
                row.appendChild(name);
                row.appendChild(meta);
                lines.appendChild(row);
            });

            if ((state.mode === 'auto' && state.stylist !== 'any') || state.slot) {
                var who = document.createElement('div');
                who.className = 'wb-sumline';
                var lbl = document.createElement('span');
                lbl.textContent = I18N.stylist;
                var val = document.createElement('span');
                val.className = 'text-secondary';
                val.textContent = state.slot ? state.slot.stylist : stylistName(state.stylist) || I18N.any;
                who.appendChild(lbl); who.appendChild(val);
                lines.appendChild(who);
            }
            if (state.slot) {
                var when = document.createElement('div');
                when.className = 'wb-sumline';
                var wl = document.createElement('span');
                wl.textContent = I18N.when;
                var wv = document.createElement('span');
                wv.className = 'text-secondary';
                wv.textContent = state.slot.spoken;
                when.appendChild(wl); when.appendChild(wv);
                lines.appendChild(when);
            }

            var minutes = state.slot
                ? state.slot.duration_minutes
                : state.services.reduce(function (sum, s) { return sum + s.duration_minutes; }, 0);
            var pricedCents = state.services.reduce(function (sum, s) { return sum + (s.price_cents || 0); }, 0);
            var unpriced = state.services.some(function (s) { return s.price_cents === null; });

            var total = $('bts-summary-total');
            total.textContent = '';
            var tl = document.createElement('span');
            tl.textContent = I18N.total;
            var tv = document.createElement('span');
            tv.textContent = minutes + ' ' + I18N.min
                + (pricedCents > 0 ? ' · ' + money(pricedCents) : '')
                + (unpriced ? ' · ' + I18N.varies : '');
            total.appendChild(tl);
            total.appendChild(tv);
        }

        function stylistName(id) {
            if (id === 'any') { return null; }
            var found = null;
            state.services.forEach(function (service) {
                service.stylists.forEach(function (s) { if (String(s.id) === String(id)) { found = s.name; } });
            });
            return found;
        }

        // -- step 1: services (multi-select) --------------------------------
        function renderServices() {
            var wrap = $('bts-services');
            wrap.textContent = '';
            if (!CATALOGUE.length) {
                $('bts-no-services').classList.remove('hidden');
                return;
            }
            CATALOGUE.forEach(function (service) {
                var selected = state.services.some(function (s) { return s.id === service.id; });
                var sub = service.duration_minutes + ' ' + I18N.min + (service.price ? ' · ' + service.price : '');
                wrap.appendChild(option(service.name, sub, function () { toggleService(service); }, selected));
            });
            var next = $('bts-continue');
            next.hidden = state.services.length === 0;
            next.textContent = state.services.length === 1
                ? I18N.continueOne
                : I18N.continueMany.replace(':count', String(state.services.length));
            postHeight();
        }

        function toggleService(service) {
            var index = state.services.findIndex(function (s) { return s.id === service.id; });
            if (index >= 0) { state.services.splice(index, 1); }
            else { state.services.push(service); }
            state.stylist = 'any';
            state.mode = 'auto';
            state.assignments = {};
            state.slot = null;
            renderServices();
            renderSummary();
        }

        // -- step 2: stylists — qualified for EVERY selected service ---------
        function sharedStylists() {
            if (!state.services.length) { return []; }
            return state.services[0].stylists.filter(function (stylist) {
                return state.services.every(function (service) {
                    return service.stylists.some(function (s) { return s.id === stylist.id; });
                });
            });
        }

        function renderStylists() {
            var wrap = $('bts-stylists');
            wrap.textContent = '';
            var shared = sharedStylists();

            // No single stylist covers the whole selection: auto composes a
            // back-to-back team arrangement — say so instead of refusing.
            $('bts-stylist-hint').textContent = shared.length || state.services.length < 2
                ? @json(__('One stylist takes your whole visit when possible.'))
                : I18N.teamNote;

            wrap.appendChild(option(I18N.any, null, function () { pickStylist('any'); }, state.mode === 'auto' && state.stylist === 'any'));
            shared.forEach(function (stylist) {
                wrap.appendChild(option(stylist.name, null, function () { pickStylist(String(stylist.id)); }, state.mode === 'auto' && String(state.stylist) === String(stylist.id)));
            });

            var toggle = $('bts-manual-toggle');
            toggle.hidden = state.services.length < 2;
            toggle.textContent = state.mode === 'manual' ? I18N.manualOff : I18N.manualOn;
            toggle.setAttribute('aria-expanded', state.mode === 'manual' ? 'true' : 'false');
            $('bts-manual').hidden = state.mode !== 'manual';
            if (state.mode === 'manual') { renderManualRows(); }
            postHeight();
        }

        // -- manual mode: an explicit stylist per service ----------------------
        function renderManualRows() {
            var rows = $('bts-manual-rows');
            rows.textContent = '';
            state.services.forEach(function (service) {
                var row = document.createElement('div');
                var label = document.createElement('label');
                label.className = 'block text-[13px] font-semibold text-secondary';
                label.setAttribute('for', 'bts-assign-' + service.id);
                label.textContent = service.name;
                var select = document.createElement('select');
                select.className = 'wb-field mt-1';
                select.id = 'bts-assign-' + service.id;
                var any = document.createElement('option');
                any.value = 'any';
                any.textContent = I18N.anyStylist;
                select.appendChild(any);
                service.stylists.forEach(function (stylist) {
                    var opt = document.createElement('option');
                    opt.value = String(stylist.id);
                    opt.textContent = stylist.name;
                    select.appendChild(opt);
                });
                select.value = state.assignments[service.id] || 'any';
                select.addEventListener('change', function () { state.assignments[service.id] = select.value; });
                row.appendChild(label);
                row.appendChild(select);
                rows.appendChild(row);
            });
        }

        function pickStylist(id) {
            state.mode = 'auto';
            state.stylist = id;
            openTimeStep();
        }

        function openTimeStep() {
            state.slot = null;
            state.date = null;
            $('bts-slots').textContent = '';
            $('bts-slots-empty').classList.add('hidden');
            $('bts-day-label').classList.add('hidden');
            show('time');
            openCalendar();
        }

        // -- step 3: inline availability calendar -----------------------------
        // One month endpoint call paints the whole grid (cached per services +
        // stylist + month); only days the FULL visit fits are selectable.
        var cal = { month: MIN_DATE.slice(0, 7), avail: {}, focus: null, refocus: false };

        function pad2(n) { return (n < 10 ? '0' : '') + n; }
        function calKey(month) { return servicesQuery() + '|' + month; }
        function monthAdd(month, delta) {
            var y = +month.slice(0, 4), m = +month.slice(5, 7) - 1 + delta;
            return (y + Math.floor(m / 12)) + '-' + pad2(((m % 12) + 12) % 12 + 1);
        }
        function localDate(date) {
            return new Date(+date.slice(0, 4), +date.slice(5, 7) - 1, +date.slice(8, 10));
        }
        function prettyDate(date) {
            return localDate(date).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' });
        }

        function openCalendar() {
            cal.month = state.date ? state.date.slice(0, 7) : MIN_DATE.slice(0, 7);
            cal.focus = state.date || MIN_DATE;
            loadMonth();
        }

        function loadMonth() {
            var month = cal.month, key = calKey(month);
            if (cal.avail[key]) { renderCalendar(cal.avail[key]); return; }
            renderCalendar(null); // greyed skeleton while the month loads

            var url = API.month + '?' + servicesQuery() + '&month=' + encodeURIComponent(month);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success === false) { error(data.message || I18N.failed); return; }
                    cal.avail[key] = data.dates || [];
                    if (cal.month === month) { renderCalendar(cal.avail[key]); }
                })
                .catch(function () { error(I18N.failed); });
        }

        function renderCalendar(dates) {
            var y = +cal.month.slice(0, 4), m = +cal.month.slice(5, 7);
            $('bts-cal-title').textContent = new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
            $('bts-cal-prev').disabled = cal.month <= MIN_DATE.slice(0, 7);
            $('bts-cal-next').disabled = cal.month >= MAX_DATE.slice(0, 7);

            var dow = $('bts-cal-dow');
            if (!dow.childNodes.length) {
                for (var i = 0; i < 7; i++) {
                    var label = document.createElement('span');
                    // Aug 2 2021 was a Monday — Monday-first weekday initials.
                    label.textContent = new Date(2021, 7, 2 + i).toLocaleDateString(undefined, { weekday: 'short' }).slice(0, 2);
                    dow.appendChild(label);
                }
            }

            var grid = $('bts-cal-grid');
            grid.textContent = '';
            grid.setAttribute('aria-busy', dates === null ? 'true' : 'false');
            var open = dates || [];
            var lead = (new Date(y, m - 1, 1).getDay() + 6) % 7; // Monday-first offset
            var days = new Date(y, m, 0).getDate();

            for (var blank = 0; blank < lead; blank++) { grid.appendChild(document.createElement('span')); }

            for (var d = 1; d <= days; d++) {
                var date = cal.month + '-' + pad2(d);
                var available = dates !== null && open.indexOf(date) >= 0 && date >= MIN_DATE && date <= MAX_DATE;
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wb-day';
                b.textContent = String(d);
                b.setAttribute('data-date', date);
                b.setAttribute('data-available', available ? 'true' : 'false');
                if (!available) { b.setAttribute('aria-disabled', 'true'); }
                b.setAttribute('aria-pressed', state.date === date ? 'true' : 'false');
                b.setAttribute('aria-label', prettyDate(date) + ' — ' + (available ? I18N.available : I18N.unavailable));
                b.setAttribute('tabindex', date === cal.focus ? '0' : '-1');
                b.addEventListener('click', dayClick);
                grid.appendChild(b);
            }

            // Exactly one tab stop: the focus date, else the first open day.
            if (!grid.querySelector('[tabindex="0"]')) {
                var fallback = grid.querySelector('[data-available="true"]') || grid.querySelector('.wb-day');
                if (fallback) { fallback.setAttribute('tabindex', '0'); cal.focus = fallback.getAttribute('data-date'); }
            }
            if (cal.refocus && dates !== null) {
                var target = grid.querySelector('[data-date="' + cal.focus + '"]');
                if (target) { target.focus(); }
                cal.refocus = false;
            }
            postHeight();
        }

        function setTabStop(btn) {
            var current = $('bts-cal-grid').querySelectorAll('[tabindex="0"]');
            Array.prototype.forEach.call(current, function (el) { el.setAttribute('tabindex', '-1'); });
            btn.setAttribute('tabindex', '0');
        }

        function dayClick(event) {
            var btn = event.currentTarget;
            cal.focus = btn.getAttribute('data-date');
            setTabStop(btn);
            if (btn.getAttribute('data-available') !== 'true') { return; }
            state.date = cal.focus;
            state.slot = null;
            Array.prototype.forEach.call(document.querySelectorAll('#bts-cal-grid .wb-day'), function (el) {
                el.setAttribute('aria-pressed', el.getAttribute('data-date') === state.date ? 'true' : 'false');
            });
            loadSlots();
        }

        // Arrow keys roam the grid (crossing month edges pages the calendar);
        // Enter/Space activate via the buttons' native click.
        $('bts-cal-grid').addEventListener('keydown', function (event) {
            var delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
            if (!delta || !cal.focus) { return; }
            event.preventDefault();

            var next = localDate(cal.focus);
            next.setDate(next.getDate() + delta);
            var date = next.getFullYear() + '-' + pad2(next.getMonth() + 1) + '-' + pad2(next.getDate());
            if (date < MIN_DATE || date > MAX_DATE) { return; }

            cal.focus = date;
            if (date.slice(0, 7) !== cal.month) {
                cal.month = date.slice(0, 7);
                cal.refocus = true;
                loadMonth();
                return;
            }
            var btn = $('bts-cal-grid').querySelector('[data-date="' + date + '"]');
            if (btn) { setTabStop(btn); btn.focus(); }
        });

        $('bts-cal-prev').addEventListener('click', function () { cal.month = monthAdd(cal.month, -1); cal.focus = cal.month + '-01'; loadMonth(); });
        $('bts-cal-next').addEventListener('click', function () { cal.month = monthAdd(cal.month, 1); cal.focus = cal.month + '-01'; loadMonth(); });

        // -- step 3b: the selected day's full-visit slots ----------------------
        function servicesQuery() {
            var query = state.services.map(function (s) { return 'services[]=' + encodeURIComponent(s.id); }).join('&');
            if (state.mode === 'manual') {
                // Per-service assignment, aligned with services[] order.
                query += '&' + state.services.map(function (s) {
                    return 'stylists[]=' + encodeURIComponent(state.assignments[s.id] || 'any');
                }).join('&');
            } else {
                query += '&stylist=' + encodeURIComponent(state.stylist);
            }
            return query;
        }

        function loadSlots() {
            var wrap = $('bts-slots');
            wrap.textContent = I18N.loading;
            $('bts-slots-empty').classList.add('hidden');
            var label = $('bts-day-label');
            label.textContent = I18N.timesFor.replace(':date', prettyDate(state.date));
            label.classList.remove('hidden');

            var url = API.availability + '?' + servicesQuery() + '&date=' + encodeURIComponent(state.date);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success === false) { wrap.textContent = ''; error(data.message || I18N.failed); return; }
                    renderSlots(data.slots || []);
                })
                .catch(function () { wrap.textContent = ''; error(I18N.failed); });
        }

        function renderSlots(slots) {
            var wrap = $('bts-slots');
            wrap.textContent = '';
            $('bts-slots-empty').classList.toggle('hidden', slots.length > 0);
            slots.forEach(function (slot) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wb-chip';
                // Name the staffing whenever it wasn't a single explicit pick
                // ("Maya + Sarah" for a composed team visit).
                var named = state.mode === 'manual' || state.stylist === 'any' || slot.multi_stylist;
                b.textContent = slot.time + (named ? ' · ' + slot.stylist : '');
                b.addEventListener('click', function () {
                    state.slot = slot;
                    show('details');
                    $('bts-name').focus();
                });
                wrap.appendChild(b);
            });
            postHeight();
        }

        // -- step 4: submit the whole visit ------------------------------------
        function submit(event) {
            event.preventDefault();
            var name = $('bts-name').value.trim();
            var phone = $('bts-phone').value.trim();
            if (!name || !phone) { error(I18N.required); return; }

            var btn = $('bts-submit');
            btn.disabled = true;

            fetch(API.book, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    // Submit the offered arrangement verbatim: services and
                    // their per-leg stylists from the chosen slot, aligned.
                    services: state.slot.stylists.map(function (a) { return a.service_id; }),
                    stylists: state.slot.stylists.map(function (a) { return String(a.stylist_id); }),
                    date: state.slot.date,
                    time: state.slot.time,
                    client: { name: name, phone: phone, email: $('bts-email').value.trim() || null },
                    notes: $('bts-notes').value.trim() || null,
                    token: TOKEN,
                    website: $('bts-website').value,
                }),
            })
                .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
                .then(function (result) {
                    btn.disabled = false;
                    if (result.status === 201 && result.data.success) {
                        $('bts-confirmation').textContent = result.data.message;
                        show('confirmed');
                    } else if (result.status === 409 && result.data.alternatives) {
                        show('time');
                        renderSlots(result.data.alternatives);
                        error(I18N.taken);
                    } else {
                        error(result.data.message || I18N.failed);
                    }
                })
                .catch(function () { btn.disabled = false; error(I18N.failed); });
        }

        // -- wiring -----------------------------------------------------------
        $('bts-continue').addEventListener('click', function () {
            renderStylists();
            show('stylist');
        });
        $('bts-manual-toggle').addEventListener('click', function () {
            state.mode = state.mode === 'manual' ? 'auto' : 'manual';
            renderStylists();
        });
        $('bts-manual-continue').addEventListener('click', function () {
            state.services.forEach(function (service) {
                var select = document.getElementById('bts-assign-' + service.id);
                state.assignments[service.id] = select ? select.value : 'any';
            });
            openTimeStep();
        });
        $('bts-form').addEventListener('submit', submit);
        $('bts-back').addEventListener('click', function () {
            if (state.step === 'details') { show('time'); }
            else if (state.step === 'time') { show('stylist'); }
            else if (state.step === 'stylist') { show('service'); renderServices(); }
        });
        $('bts-again').addEventListener('click', function () {
            state.services = []; state.slot = null; state.stylist = 'any'; state.date = null;
            state.mode = 'auto'; state.assignments = {};
            $('bts-form').reset();
            $('bts-slots').textContent = '';
            $('bts-day-label').classList.add('hidden');
            renderServices();
            show('service');
        });

        renderServices();
        renderSummary();

        // Deep-link: ?service=ID preselects it and jumps to the stylist step.
        var preselect = document.getElementById('bts-widget').getAttribute('data-preselect');
        if (preselect) {
            var found = CATALOGUE.find(function (s) { return String(s.id) === preselect; });
            if (found) {
                state.services = [found];
                renderServices();
                renderStylists();
                show('stylist');
            }
        }
    })();
    </script>
</body>
</html>
