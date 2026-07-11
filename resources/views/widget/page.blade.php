{{--
    The embeddable public booking page. Self-contained: token CSS via the
    compiled stylesheet, self-hosted fonts, per-salon accent (or the ?accent=
    override), and a dependency-free inline script — no Livewire, no session,
    nothing that breaks when third-party cookies are blocked inside an iframe.
    Posts its rendered height to the parent (widget.js) for auto-resizing.
    Receives from WidgetController@page: $salon, $accent (resolved palette or
    null), $catalogue (public service list), $preselectService, $widgetToken,
    $maxDate.
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
    @if ($accent)
        <style>:root{--accent: {{ $accent['accent'] }};--accent-hover: {{ $accent['hover'] }};--accent-tint: {{ $accent['tint'] }};--accent-ink: {{ $accent['ink'] }};}</style>
    @endif
</head>
<body class="bg-paper text-ink antialiased">
    <main class="mx-auto w-full max-w-xl p-4 sm:p-6" id="bts-widget"
          data-salon="{{ $salon->name }}"
          data-preselect="{{ $preselectService ?? '' }}">
        <header class="mb-4">
            <p class="bts-overline">{{ __('Book an appointment') }}</p>
            <h1 class="font-display text-[22px] font-bold leading-tight">{{ $salon->name }}</h1>
        </header>

        <div id="bts-error" class="mb-3 hidden rounded-[11px] px-3 py-2.5 text-[14px]" style="background-color:#F8E3E3;color:#A23A3A" role="alert"></div>

        {{-- Step 1: service --}}
        <section data-step="service">
            <h2 class="mb-2 font-display text-[16px] font-semibold">{{ __('Choose a service') }}</h2>
            <div id="bts-services" class="grid gap-2"></div>
            <p class="mt-3 hidden text-[14px] text-secondary" id="bts-no-services">{{ __('Online booking is not available right now. Please contact the salon directly.') }}</p>
        </section>

        {{-- Step 2: stylist --}}
        <section data-step="stylist" hidden>
            <h2 class="mb-2 font-display text-[16px] font-semibold">{{ __('Choose a stylist') }}</h2>
            <div id="bts-stylists" class="grid gap-2"></div>
        </section>

        {{-- Step 3: date + time --}}
        <section data-step="time" hidden>
            <h2 class="mb-2 font-display text-[16px] font-semibold">{{ __('Pick a date and time') }}</h2>
            <label class="block text-[13px] font-semibold text-secondary" for="bts-date">{{ __('Date') }}</label>
            <input type="date" id="bts-date" class="mt-1 h-12 w-full rounded-[11px] border border-input bg-field px-3 text-[15px]"
                   min="{{ now($salon->timezone)->format('Y-m-d') }}" max="{{ $maxDate }}">
            <div id="bts-slots" class="mt-3 flex flex-wrap gap-2" aria-live="polite"></div>
            <p id="bts-slots-empty" class="mt-2 hidden text-[14px] text-secondary">{{ __('No open times on that day — try another date.') }}</p>
        </section>

        {{-- Step 4: details --}}
        <section data-step="details" hidden>
            <h2 class="mb-2 font-display text-[16px] font-semibold">{{ __('Your details') }}</h2>
            <form id="bts-form" class="grid gap-3" novalidate>
                <div>
                    <label class="block text-[13px] font-semibold text-secondary" for="bts-name">{{ __('Name') }}</label>
                    <input id="bts-name" name="name" required autocomplete="name" class="mt-1 h-12 w-full rounded-[11px] border border-input bg-field px-3 text-[15px]">
                </div>
                <div>
                    <label class="block text-[13px] font-semibold text-secondary" for="bts-phone">{{ __('Phone') }}</label>
                    <input id="bts-phone" name="phone" type="tel" required autocomplete="tel" class="mt-1 h-12 w-full rounded-[11px] border border-input bg-field px-3 text-[15px]">
                </div>
                <div>
                    <label class="block text-[13px] font-semibold text-secondary" for="bts-email">{{ __('Email (optional)') }}</label>
                    <input id="bts-email" name="email" type="email" autocomplete="email" class="mt-1 h-12 w-full rounded-[11px] border border-input bg-field px-3 text-[15px]">
                </div>
                <div>
                    <label class="block text-[13px] font-semibold text-secondary" for="bts-notes">{{ __('Notes (optional)') }}</label>
                    <textarea id="bts-notes" name="notes" rows="2" maxlength="500" class="mt-1 w-full rounded-[11px] border border-input bg-field px-3 py-2 text-[15px]"></textarea>
                </div>
                {{-- Honeypot: hidden from humans; anything typed here fails the bot gate. --}}
                <div class="absolute -left-[9999px] top-auto" aria-hidden="true">
                    <label for="bts-website">Website</label>
                    <input id="bts-website" name="website" tabindex="-1" autocomplete="off">
                </div>
                <button type="submit" id="bts-submit" class="bts-btn bts-btn-primary w-full">{{ __('Confirm booking') }}</button>
            </form>
        </section>

        {{-- Step 5: confirmed --}}
        <section data-step="confirmed" hidden>
            <div class="rounded-[18px] border border-[#D5E4D0] bg-[#E7EFE4] p-4 text-[#3E5C3A]">
                <h2 class="font-display text-[18px] font-bold">{{ __('Booked') }}</h2>
                <p class="mt-1 text-[14px]" id="bts-confirmation"></p>
            </div>
            <button type="button" id="bts-again" class="bts-btn bts-btn-secondary mt-4">{{ __('Book another appointment') }}</button>
        </section>

        {{-- Summary line + back link, visible mid-flow --}}
        <div class="mt-4 flex items-center justify-between border-t border-divider pt-3" id="bts-footer" hidden>
            <p class="text-[13px] text-secondary" id="bts-summary"></p>
            <button type="button" id="bts-back" class="text-[13px] font-semibold text-accent-ink">{{ __('Back') }}</button>
        </div>
    </main>

    <script>
    (function () {
        'use strict';
        var CATALOGUE = @json($catalogue);
        var TOKEN = @json($widgetToken);
        var API = {
            availability: @json(route('salon.widget.availability', ['salon' => $salon->slug])),
            book: @json(route('salon.widget.book', ['salon' => $salon->slug])),
        };
        var I18N = {
            any: @json(__('Any available stylist')),
            with: @json(__('with')),
            loading: @json(__('Finding open times…')),
            failed: @json(__('Something went wrong. Please try again.')),
            taken: @json(__('That time was just taken — pick another:')),
        };

        var state = { step: 'service', service: null, stylist: 'any', date: null, slot: null };
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

        function button(label, sub, onclick) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'flex w-full items-center justify-between gap-3 rounded-[12px] border border-input bg-card px-4 py-3 text-start text-[15px] font-semibold hover:border-accent';
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

        function renderSummary() {
            var parts = [];
            if (state.service) { parts.push(state.service.name); }
            if (state.service && state.stylist !== 'any') {
                var s = state.service.stylists.find(function (x) { return String(x.id) === String(state.stylist); });
                if (s) { parts.push(s.name); }
            }
            if (state.slot) { parts.push(state.slot.spoken); }
            $('bts-summary').textContent = parts.join(' · ');
        }

        // -- step 1: services ---------------------------------------------
        function renderServices() {
            var wrap = $('bts-services');
            wrap.textContent = '';
            if (!CATALOGUE.length) {
                $('bts-no-services').classList.remove('hidden');
                return;
            }
            CATALOGUE.forEach(function (service) {
                var sub = service.duration_minutes + ' min' + (service.price ? ' · ' + service.price : '');
                wrap.appendChild(button(service.name, sub, function () {
                    state.service = service;
                    state.stylist = 'any';
                    state.slot = null;
                    renderStylists();
                    show('stylist');
                }));
            });
        }

        // -- step 2: stylists -----------------------------------------------
        function renderStylists() {
            var wrap = $('bts-stylists');
            wrap.textContent = '';
            wrap.appendChild(button(I18N.any, null, function () { pickStylist('any'); }));
            state.service.stylists.forEach(function (stylist) {
                wrap.appendChild(button(stylist.name, null, function () { pickStylist(String(stylist.id)); }));
            });
        }

        function pickStylist(id) {
            state.stylist = id;
            state.slot = null;
            show('time');
            if ($('bts-date').value) { loadSlots(); }
        }

        // -- step 3: slots ---------------------------------------------------
        function loadSlots() {
            var wrap = $('bts-slots');
            wrap.textContent = I18N.loading;
            $('bts-slots-empty').classList.add('hidden');
            state.date = $('bts-date').value;

            var url = API.availability
                + '?service=' + encodeURIComponent(state.service.id)
                + '&stylist=' + encodeURIComponent(state.stylist)
                + '&date=' + encodeURIComponent(state.date);

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { renderSlots(data.slots || []); })
                .catch(function () { wrap.textContent = ''; error(I18N.failed); });
        }

        function renderSlots(slots) {
            var wrap = $('bts-slots');
            wrap.textContent = '';
            $('bts-slots-empty').classList.toggle('hidden', slots.length > 0);
            slots.forEach(function (slot) {
                var label = slot.time + (state.stylist === 'any' ? ' · ' + slot.stylist : '');
                var b = button(label, null, function () {
                    state.slot = slot;
                    show('details');
                    $('bts-name').focus();
                });
                b.className = 'rounded-[12px] border border-input bg-card px-3 py-2 text-[14px] font-semibold hover:border-accent';
                wrap.appendChild(b);
            });
            postHeight();
        }

        // -- step 4: submit ---------------------------------------------------
        function submit(event) {
            event.preventDefault();
            var name = $('bts-name').value.trim();
            var phone = $('bts-phone').value.trim();
            if (!name || !phone) { error(@json(__('Name and phone are required.'))); return; }

            var btn = $('bts-submit');
            btn.disabled = true;

            fetch(API.book, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    service: state.service.id,
                    stylist: String(state.slot.stylist_id),
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
                        error(I18N.taken);
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
        $('bts-date').addEventListener('change', loadSlots);
        $('bts-form').addEventListener('submit', submit);
        $('bts-back').addEventListener('click', function () {
            if (state.step === 'details') { show('time'); }
            else if (state.step === 'time') { show('stylist'); }
            else if (state.step === 'stylist') { show('service'); }
        });
        $('bts-again').addEventListener('click', function () {
            state.service = null; state.slot = null; state.stylist = 'any';
            $('bts-form').reset();
            $('bts-slots').textContent = '';
            $('bts-date').value = '';
            show('service');
        });

        renderServices();

        // Deep-link: ?service=ID preselects and jumps to the stylist step.
        var preselect = document.getElementById('bts-widget').getAttribute('data-preselect');
        if (preselect) {
            var found = CATALOGUE.find(function (s) { return String(s.id) === preselect; });
            if (found) {
                state.service = found;
                renderStylists();
                show('stylist');
            }
        }
    })();
    </script>
</body>
</html>
