{{--
    The embeddable public booking page — the salon's ONLY customer-facing
    booking surface. Visual language: one cohesive branded container (owner-
    approved reference look) — a generously rounded shell filled with the
    salon's SOLID branded background, split by a hairline divider into the
    info pane and the scheduling pane, all colours branding-driven with the
    foreground family DERIVED from the surface by WCAG contrast
    (WidgetBranding::mode).

    Flow: a PER-SERVICE loop. The right pane repeats service → stylist for
    THAT service → date & time for THAT service (inline availability
    calendar, that service's duration with that stylist); each added service
    is its own independently-timed appointment (gaps allowed, nothing forced
    back-to-back). After each addition: "Add another service" or "Finalize
    booking" → client details → confirmation. The LEFT pane is the live
    running summary of the visit (each added service with its stylist and
    time, removable, plus running totals).

    Self-contained: token CSS via the compiled stylesheet, self-hosted fonts,
    and a dependency-free inline script — no Livewire, no session, nothing
    that breaks when third-party cookies are blocked inside an iframe. Posts
    its rendered height to the parent (widget.js) for auto-resizing.

    Receives from WidgetController@page: $salon, $branding, $catalogue,
    $currency, $preselectService, $widgetToken, $minDate, $maxDate.
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
            {{-- The branded accent fills BOTH slots. The direct --accent* set
                 covers the classic widget theme (no data-theme attribute, so
                 nothing re-declares them); the --brand-accent* slot is what a
                 THEMED body (e.g. marble) reads — its own block re-declares
                 --accent as var(--brand-accent, <theme default>) for all
                 descendants, so without the slot the salon/widget accent was
                 silently discarded on every themed widget. --}}
            --brand-accent: {{ $branding['accent']['accent'] }};
            --brand-accent-hover: {{ $branding['accent']['hover'] }};
            --brand-accent-tint: {{ $branding['accent']['tint'] }};
            --brand-accent-ink: {{ $branding['accent']['ink'] }};
            --brand-accent-foreground: {{ $branding['mode']['accent_ink'] }};
            --accent: {{ $branding['accent']['accent'] }};
            --accent-hover: {{ $branding['accent']['hover'] }};
            --accent-tint: {{ $branding['accent']['tint'] }};
            --accent-ink: {{ $branding['accent']['ink'] }};
            --wb-secondary: {{ $branding['secondary'] }};
            --wb-surface: {{ $branding['surface'] }};
            --wb-ink: {{ $branding['mode']['ink'] }};
            --wb-muted: {{ $branding['mode']['muted'] }};
            --wb-faint: {{ $branding['mode']['faint'] }};
            --wb-line: {{ $branding['mode']['line'] }};
            --wb-cell: {{ $branding['mode']['cell'] }};
            --wb-accent-ink: {{ $branding['mode']['accent_ink'] }};
            --wb-display: {!! $branding['font']['display'] !!};
            --wb-body: {!! $branding['font']['body'] !!};
        }

        /* ── One rounded, solid-branded container (host page shows around it) ── */
        body { font-family: var(--wb-body); background: transparent; color: var(--wb-ink); }
        .wb-shell {
            display: grid;
            background: var(--wb-surface);
            color: var(--wb-ink);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 16px 44px rgb(12 12 18 / .16), 0 2px 8px rgb(12 12 18 / .08);
        }
        .wb-info { padding: 26px 22px; }
        .wb-book { padding: 22px; }
        /* Duet split with a hairline divider from 820px; stacked (info on top) below. */
        @media (min-width: 820px) {
            .wb-shell { grid-template-columns: 292px minmax(0, 1fr); }
            .wb-info { border-right: 1px solid var(--wb-line); }
        }
        @media (max-width: 819.98px) {
            .wb-info { border-bottom: 1px solid var(--wb-line); }
        }

        .wb-display { font-family: var(--wb-display); }
        .wb-muted { color: var(--wb-muted); }
        .wb-faint { color: var(--wb-faint); }
        .wb-overline {
            font-size: 11.5px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
            color: color-mix(in srgb, var(--accent) 60%, var(--wb-ink));
        }
        .wb-logo { max-height: 56px; max-width: 190px; width: auto; object-fit: contain; margin-bottom: 14px; }

        /* Rows / options: raised cells on the branded surface. */
        .wb-opt {
            display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 12px;
            border-radius: 14px; padding: 12px 14px; text-align: start; font-size: 15px; font-weight: 600;
            color: var(--wb-ink);
            background: var(--wb-cell);
            border: 1.5px solid var(--wb-line);
            transition: border-color .15s ease, background-color .15s ease;
            cursor: pointer; min-height: 48px;
        }
        .wb-opt:hover { border-color: color-mix(in srgb, var(--accent) 60%, transparent); }
        .wb-opt[aria-pressed='true'] {
            border-color: var(--accent);
            background: color-mix(in srgb, var(--accent) 16%, var(--wb-surface));
        }

        /* Time slots: accent-bordered rounded buttons (the reference look). */
        .wb-chip {
            border-radius: 12px; padding: 10px 14px; font-size: 14px; font-weight: 600;
            color: var(--wb-ink); background: transparent; cursor: pointer; min-height: 44px;
            border: 1.5px solid color-mix(in srgb, var(--accent) 70%, transparent);
            transition: background-color .15s ease, border-color .15s ease;
        }
        .wb-chip:hover { background: color-mix(in srgb, var(--accent) 16%, transparent); border-color: var(--accent); }

        .wb-field {
            width: 100%; min-height: 48px; border-radius: 11px; padding: 10px 13px; font-size: 15px;
            color: var(--wb-ink); background: var(--wb-cell); border: 1.5px solid var(--wb-line);
        }
        .wb-field:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

        .wb-cta {
            display: flex; width: 100%; min-height: 48px; align-items: center; justify-content: center;
            border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer;
            color: var(--wb-accent-ink); background: var(--accent); border: 1px solid transparent;
            box-shadow: 0 6px 18px color-mix(in srgb, var(--accent) 35%, transparent);
            transition: background-color .15s ease;
        }
        .wb-cta:hover { background: var(--accent-hover); }
        .wb-cta:disabled { opacity: .5; pointer-events: none; }
        .wb-ghost {
            display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: 8px;
            border-radius: 11px; padding: 0 18px; font-size: 14px; font-weight: 600; cursor: pointer;
            color: var(--wb-muted); background: transparent; border: 1.5px solid var(--wb-line);
        }
        .wb-ghost:hover { color: var(--wb-ink); border-color: color-mix(in srgb, var(--accent) 50%, transparent); }

        /* Live visit summary: one row per ADDED service, removable. */
        .wb-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 10px 0; }
        .wb-item + .wb-item { border-top: 1px solid var(--wb-line); }
        .wb-item-name { font-size: 13.5px; font-weight: 600; }
        .wb-item-meta { margin-top: 2px; font-size: 12.5px; color: var(--wb-muted); }
        .wb-remove {
            display: flex; min-width: 34px; min-height: 34px; align-items: center; justify-content: center;
            border-radius: 9px; cursor: pointer; color: var(--wb-muted);
            background: transparent; border: 1.5px solid var(--wb-line);
        }
        .wb-remove:hover { color: var(--wb-ink); border-color: color-mix(in srgb, var(--accent) 50%, transparent); }

        .wb-sumline { display: flex; justify-content: space-between; gap: 10px; font-size: 13.5px; padding: 7px 0; }

        /* ── Inline availability calendar (no native date input) ── */
        .wb-cal-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
        .wb-cal-nav {
            display: flex; min-width: 40px; min-height: 40px; align-items: center; justify-content: center;
            border-radius: 10px; cursor: pointer; color: var(--wb-ink);
            background: var(--wb-cell); border: 1.5px solid var(--wb-line);
            transition: border-color .15s ease;
        }
        .wb-cal-nav:hover { border-color: color-mix(in srgb, var(--accent) 60%, transparent); }
        .wb-cal-nav:disabled { opacity: .35; pointer-events: none; }
        .wb-cal-dow, .wb-cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; }
        .wb-cal-dow span {
            padding: 4px 0; text-align: center; font-size: 11px; font-weight: 600;
            letter-spacing: .08em; text-transform: uppercase; color: var(--wb-faint);
        }
        .wb-cal-grid[aria-busy='true'] { opacity: .55; }
        /* Available vs not is never colour-alone: open days are accent-tinted
           circles (semibold, accent dot); closed days are flat, regular, faint. */
        .wb-day {
            position: relative; display: flex; align-items: center; justify-content: center;
            min-height: 42px; width: 100%; border-radius: 99px; font-size: 14px; font-weight: 400;
            color: var(--wb-faint); background: transparent; border: 1.5px solid transparent; cursor: default;
        }
        .wb-day[data-available='true'] {
            font-weight: 600; color: var(--wb-ink); cursor: pointer;
            background: color-mix(in srgb, var(--accent) 16%, transparent);
            transition: border-color .15s ease;
        }
        .wb-day[data-available='true']::after {
            content: ''; position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 99px; background: var(--accent);
        }
        .wb-day[data-available='true']:hover { border-color: var(--accent); }
        .wb-day[aria-pressed='true'] { background: var(--accent); border-color: var(--accent); color: var(--wb-accent-ink); font-weight: 700; }
        .wb-day[aria-pressed='true']::after { background: var(--wb-accent-ink); }
        .wb-day:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body class="antialiased" data-theme="{{ $widget->themeKey() }}">
    <main class="mx-auto w-full max-w-5xl p-3 sm:p-5" id="bts-widget"
          data-salon="{{ $salon->name }}"
          data-widget="{{ $widget->public_id }}"
          data-preselect="{{ $preselectService ?? '' }}">

        <div class="wb-shell">
            {{-- ── Info pane: brand + the LIVE running summary of the visit ── --}}
            <aside class="wb-info" aria-label="{{ __('Visit summary') }}">
                @if ($branding['logo_url'])
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $salon->name }}" class="wb-logo" />
                @endif
                <p class="wb-overline">{{ __('Book an appointment') }}</p>
                <h1 class="wb-display mt-1 text-[21px] font-semibold leading-tight">{{ $salon->name }}</h1>

                <div id="bts-items" class="mt-4 hidden" aria-live="polite">
                    <p class="wb-muted text-[12px] font-semibold uppercase tracking-wide">{{ __('Your visit') }}</p>
                    <div id="bts-item-lines" class="mt-1"></div>
                    <div class="wb-sumline" id="bts-summary-total" style="font-weight:600; border-top: 1.5px solid var(--wb-line);"></div>
                </div>
                <p id="bts-summary-empty" class="wb-muted mt-4 text-[13.5px]">{{ __('Choose a service to begin. Each service gets its own stylist and time.') }}</p>
            </aside>

            {{-- ── Scheduling pane: the per-service loop ── --}}
            <div class="wb-book">
                <div id="bts-error" class="mb-3 hidden rounded-[12px] px-3 py-2.5 text-[14px]" style="background:#F8E3E3;color:#A23A3A" role="alert"></div>

                {{-- Loop step 1: pick a service --}}
                <section data-step="service">
                    <h2 class="wb-display text-[17px] font-semibold" id="bts-service-heading">{{ __('Choose a service') }}</h2>
                    <p class="wb-muted mt-0.5 text-[13.5px]">{{ __('Each service gets its own stylist and its own time — you can add several.') }}</p>
                    <div id="bts-services" class="mt-3 grid gap-2"></div>
                    <p class="wb-muted mt-3 hidden text-[14px]" id="bts-no-services">{{ __('Online booking is not available right now. Please contact the salon directly.') }}</p>
                </section>

                {{-- Loop step 2: pick THIS service's stylist --}}
                <section data-step="stylist" hidden>
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Choose a stylist') }}</h2>
                    <p class="wb-muted mt-0.5 text-[13.5px]" id="bts-stylist-sub"></p>
                    <div id="bts-stylists" class="mt-3 grid gap-2"></div>
                </section>

                {{-- Loop step 3: pick THIS service's date & time --}}
                <section data-step="time" hidden>
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Select date & time') }}</h2>
                    <p class="wb-muted mt-0.5 text-[13.5px]" id="bts-time-sub"></p>
                    <div id="bts-cal" class="mt-3">
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
                    <p id="bts-day-label" class="wb-muted mt-3 hidden text-[13px] font-semibold"></p>
                    <div id="bts-slots" class="mt-2 flex flex-wrap gap-2" aria-live="polite"></div>
                    <p id="bts-slots-empty" class="wb-muted mt-2 hidden text-[14px]">{{ __('No open times for this service that day — try another date.') }}</p>
                    <p class="wb-faint mt-3 text-[12.5px]">{{ __('Times shown in :timezone', ['timezone' => $salon->timezone]) }}</p>
                </section>

                {{-- Loop step 4: added — extend the visit or finalize --}}
                <section data-step="added" hidden>
                    <h2 class="wb-display text-[17px] font-semibold" id="bts-added-title"></h2>
                    <p class="wb-muted mt-0.5 text-[13.5px]">{{ __('Add more services to this visit, or finalize your booking when you are ready.') }}</p>
                    <div class="mt-4 grid gap-2">
                        <button type="button" id="bts-finalize" class="wb-cta">{{ __('Finalize booking') }}</button>
                        <button type="button" id="bts-add-more" class="wb-ghost w-full">{{ __('Add another service') }}</button>
                    </div>
                </section>

                {{-- Details --}}
                <section data-step="details" hidden>
                    <h2 class="wb-display text-[17px] font-semibold">{{ __('Your details') }}</h2>
                    <form id="bts-form" class="mt-3 grid gap-3" novalidate>
                        <div>
                            <label class="wb-muted block text-[13px] font-semibold" for="bts-name">{{ __('Name') }}</label>
                            <input id="bts-name" name="name" required autocomplete="name" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="wb-muted block text-[13px] font-semibold" for="bts-phone">{{ __('Phone') }}</label>
                            <input id="bts-phone" name="phone" type="tel" required autocomplete="tel" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="wb-muted block text-[13px] font-semibold" for="bts-email">{{ __('Email (optional)') }}</label>
                            <input id="bts-email" name="email" type="email" autocomplete="email" class="wb-field mt-1">
                        </div>
                        <div>
                            <label class="wb-muted block text-[13px] font-semibold" for="bts-notes">{{ __('Notes (optional)') }}</label>
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

                {{-- Confirmed --}}
                <section data-step="confirmed" hidden>
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full"
                         style="background: color-mix(in srgb, var(--accent) 18%, var(--wb-surface)); color: var(--wb-ink);">
                        <svg viewBox="0 0 20 20" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7A1 1 0 1 1 4.7 8.3l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>
                    </div>
                    <h2 class="wb-display mt-3 text-center text-[19px] font-semibold">{{ __("You're booked") }}</h2>
                    <p class="wb-muted mt-1 text-center text-[14px]" id="bts-confirmation"></p>
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
            required: @json(__('Name and phone are required.')),
            min: @json(__('min')),
            total: @json(__('Estimated total')),
            varies: @json(__('some prices vary')),
            available: @json(__('available')),
            unavailable: @json(__('unavailable')),
            timesFor: @json(__('Open times for :date')),
            chooseService: @json(__('Choose a service')),
            addAnother: @json(__('Add another service')),
            forService: @json(__('For :service · :minutes min')),
            added: @json(__(':service added')),
            removeItem: @json(__('Remove :service')),
        };

        var state = { step: 'service', items: [], draft: null };
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
            $('bts-footer').hidden = step === 'service' || step === 'added' || step === 'confirmed';
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
                s.className = 'wb-muted shrink-0 text-[13px] font-normal';
                s.textContent = sub;
                b.appendChild(s);
            }
            b.addEventListener('click', onclick);
            return b;
        }

        function shortWhen(slot) {
            var d = localDate(slot.date);
            return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' })
                + ' · ' + slot.time + ' · ' + slot.stylist;
        }

        // -- info pane: the LIVE running summary of the visit ------------------
        function renderSummary() {
            var lines = $('bts-item-lines');
            lines.textContent = '';
            var hasItems = state.items.length > 0;
            $('bts-items').classList.toggle('hidden', !hasItems);
            $('bts-summary-empty').classList.toggle('hidden', hasItems);
            if (!hasItems) { return; }

            state.items.forEach(function (item, index) {
                var row = document.createElement('div');
                row.className = 'wb-item';
                var text = document.createElement('div');
                var name = document.createElement('div');
                name.className = 'wb-item-name';
                name.textContent = item.service.name;
                var meta = document.createElement('div');
                meta.className = 'wb-item-meta';
                meta.textContent = shortWhen(item.slot)
                    + ' · ' + item.slot.duration_minutes + ' ' + I18N.min
                    + (item.service.price ? ' · ' + item.service.price : '');
                text.appendChild(name);
                text.appendChild(meta);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'wb-remove';
                remove.setAttribute('aria-label', I18N.removeItem.replace(':service', item.service.name));
                remove.textContent = '×';
                remove.addEventListener('click', function () { removeItem(index); });

                row.appendChild(text);
                row.appendChild(remove);
                lines.appendChild(row);
            });

            var minutes = state.items.reduce(function (sum, item) { return sum + item.slot.duration_minutes; }, 0);
            var pricedCents = state.items.reduce(function (sum, item) { return sum + (item.service.price_cents || 0); }, 0);
            var unpriced = state.items.some(function (item) { return item.service.price_cents === null; });

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

        function removeItem(index) {
            state.items.splice(index, 1);
            renderSummary();
            postHeight();
            if (!state.items.length && (state.step === 'added' || state.step === 'details')) {
                startService();
            }
        }

        // -- loop step 1: pick a service ---------------------------------------
        function startService() {
            state.draft = null;
            $('bts-service-heading').textContent = state.items.length ? I18N.addAnother : I18N.chooseService;
            renderServices();
            show('service');
        }

        function renderServices() {
            var wrap = $('bts-services');
            wrap.textContent = '';
            if (!CATALOGUE.length) {
                $('bts-no-services').classList.remove('hidden');
                return;
            }
            CATALOGUE.forEach(function (service) {
                var sub = service.duration_minutes + ' ' + I18N.min + (service.price ? ' · ' + service.price : '');
                wrap.appendChild(option(service.name, sub, function () { pickService(service); }, false));
            });
            postHeight();
        }

        function pickService(service) {
            state.draft = { service: service, stylist: 'any' };
            renderStylists();
            show('stylist');
        }

        // -- loop step 2: THIS service's stylist -------------------------------
        function renderStylists() {
            $('bts-stylist-sub').textContent = I18N.forService
                .replace(':service', state.draft.service.name)
                .replace(':minutes', String(state.draft.service.duration_minutes));

            var wrap = $('bts-stylists');
            wrap.textContent = '';
            wrap.appendChild(option(I18N.any, null, function () { pickStylist('any'); }, false));
            state.draft.service.stylists.forEach(function (stylist) {
                wrap.appendChild(option(stylist.name, null, function () { pickStylist(String(stylist.id)); }, false));
            });
            postHeight();
        }

        function pickStylist(id) {
            state.draft.stylist = id;
            $('bts-time-sub').textContent = I18N.forService
                .replace(':service', state.draft.service.name)
                .replace(':minutes', String(state.draft.service.duration_minutes));
            $('bts-slots').textContent = '';
            $('bts-slots-empty').classList.add('hidden');
            $('bts-day-label').classList.add('hidden');
            state.date = null;
            show('time');
            openCalendar();
        }

        // -- loop step 3: inline availability calendar for THIS service --------
        // One month endpoint call paints the whole grid (cached per service +
        // stylist + month); only days with an opening for THIS service's
        // duration with THIS stylist are selectable.
        var cal = { month: MIN_DATE.slice(0, 7), avail: {}, focus: null, refocus: false };

        function pad2(n) { return (n < 10 ? '0' : '') + n; }
        function itemQuery() {
            return 'service=' + encodeURIComponent(state.draft.service.id)
                + '&stylist=' + encodeURIComponent(state.draft.stylist);
        }
        function calKey(month) { return itemQuery() + '|' + month; }
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
            cal.month = MIN_DATE.slice(0, 7);
            cal.focus = MIN_DATE;
            loadMonth();
        }

        function loadMonth() {
            var month = cal.month, key = calKey(month);
            if (cal.avail[key]) { renderCalendar(cal.avail[key]); return; }
            renderCalendar(null); // greyed skeleton while the month loads

            var url = API.month + '?' + itemQuery() + '&month=' + encodeURIComponent(month);

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

        // -- the selected day's slots for THIS service --------------------------
        function loadSlots() {
            var wrap = $('bts-slots');
            wrap.textContent = I18N.loading;
            $('bts-slots-empty').classList.add('hidden');
            var label = $('bts-day-label');
            label.textContent = I18N.timesFor.replace(':date', prettyDate(state.date));
            label.classList.remove('hidden');

            var url = API.availability + '?' + itemQuery() + '&date=' + encodeURIComponent(state.date);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success === false) { wrap.textContent = ''; error(data.message || I18N.failed); return; }
                    renderSlots(data.slots || []);
                })
                .catch(function () { wrap.textContent = ''; error(I18N.failed); });
        }

        // Guard: hide a slot whose stylist already has an OVERLAPPING service
        // in this in-progress visit (the server re-validates at finalize too).
        function clashesWithVisit(slot) {
            var start = Date.parse(slot.starts_at);
            var end = start + slot.duration_minutes * 60000;
            return state.items.some(function (item) {
                if (item.slot.stylist_id !== slot.stylist_id) { return false; }
                var s = Date.parse(item.slot.starts_at);
                var e = s + item.slot.duration_minutes * 60000;
                return start < e && s < end;
            });
        }

        function renderSlots(slots) {
            var open = slots.filter(function (slot) { return !clashesWithVisit(slot); });
            var wrap = $('bts-slots');
            wrap.textContent = '';
            $('bts-slots-empty').classList.toggle('hidden', open.length > 0);
            open.forEach(function (slot) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wb-chip';
                b.textContent = slot.time + (state.draft.stylist === 'any' ? ' · ' + slot.stylist : '');
                b.addEventListener('click', function () { addItem(slot); });
                wrap.appendChild(b);
            });
            postHeight();
        }

        // -- loop step 4: service added — extend or finalize ---------------------
        function addItem(slot) {
            state.items.push({ service: state.draft.service, slot: slot });
            state.draft = null;
            state.date = null;
            $('bts-added-title').textContent = I18N.added.replace(':service', state.items[state.items.length - 1].service.name);
            renderSummary();
            show('added');
        }

        // -- finalize: client details, then the whole visit ----------------------
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
                    // Each added service exactly as scheduled: its own stylist
                    // (resolved per slot, even for "any") and its own start.
                    items: state.items.map(function (item) {
                        return {
                            service: item.service.id,
                            stylist: String(item.slot.stylist_id),
                            date: item.slot.date,
                            time: item.slot.time,
                        };
                    }),
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
                    } else if (result.status === 409 && result.data.conflicts) {
                        // Name the service(s) that need a new time; the summary
                        // keeps everything so only those need re-picking.
                        show('added');
                        error(result.data.message || I18N.failed);
                    } else {
                        error(result.data.message || I18N.failed);
                    }
                })
                .catch(function () { btn.disabled = false; error(I18N.failed); });
        }

        // -- wiring -----------------------------------------------------------
        $('bts-add-more').addEventListener('click', startService);
        $('bts-finalize').addEventListener('click', function () { show('details'); $('bts-name').focus(); });
        $('bts-form').addEventListener('submit', submit);
        $('bts-back').addEventListener('click', function () {
            if (state.step === 'details') { show('added'); }
            else if (state.step === 'time') { renderStylists(); show('stylist'); }
            else if (state.step === 'stylist') { startService(); }
        });
        $('bts-again').addEventListener('click', function () {
            state.items = []; state.draft = null; state.date = null;
            $('bts-form').reset();
            $('bts-slots').textContent = '';
            $('bts-day-label').classList.add('hidden');
            startService();
        });

        startService();
        renderSummary();

        // Deep-link: ?service=ID preselects it and jumps to its stylist step.
        var preselect = document.getElementById('bts-widget').getAttribute('data-preselect');
        if (preselect) {
            var found = CATALOGUE.find(function (s) { return String(s.id) === preselect; });
            if (found) { pickService(found); }
        }
    })();
    </script>
</body>
</html>
