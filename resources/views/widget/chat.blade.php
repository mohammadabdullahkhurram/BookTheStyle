{{--
    The conversational booking chat panel — the page the chat bubble's
    iframe loads (chat.js positions and sizes the frame; THIS page fills
    it edge to edge as one chat column). No LLM anywhere: a scripted,
    guided flow of bot messages, quick-reply chips, and typed inputs that
    walks a visitor through the SAME booking steps as the classic widget —
    greet → service → stylist → day → time → name/phone/email → explicit
    confirm → booked — plus light info branches (services / hours /
    location) answered from the salon's public data.

    Visual language: the widget family's Atelier direction adapted to
    chat — branded surface, display-font header, editorial bot bubbles on
    the cell tint, visitor replies in the accent, pill quick-replies, an
    underline composer, and the themed thin scrollbar. Every colour/font is
    branding-driven via the same --wb-* variables as the booking widget.

    Self-contained and dependency-free (no Livewire, no session, no
    cookies). Books via the SAME public endpoints as the booking widget —
    same origin inside the iframe (no CORS), same throttle, same bot gate,
    tagged surface=chat → source chat_widget.

    Receives from ChatWidgetController: $salon, $widget, $branding,
    $catalogue, $currency, $widgetToken, $info, $endpoints (preview only).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>{{ __('Chat with :salon', ['salon' => $salon->name]) }}</title>
    @fonts
    @vite('resources/css/app.css')
    <style>
        :root {
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

        html, body { height: 100%; }
        html { background: var(--wb-surface); }
        body {
            margin: 0; font-family: var(--wb-body); color: var(--wb-ink);
            background: var(--wb-surface); display: flex; flex-direction: column;
            font-size: 14.5px; line-height: 1.45; overflow: hidden;
        }

        /* ── Header ─────────────────────────────────────────────────── */
        .cw-head {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 16px; border-bottom: 1px solid var(--wb-line);
            flex-shrink: 0;
        }
        .cw-logo { max-height: 34px; max-width: 120px; width: auto; object-fit: contain; }
        .cw-title { font-family: var(--wb-display); font-size: 17px; font-weight: 600; margin: 0; }
        .cw-sub { font-size: 11.5px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: color-mix(in srgb, var(--accent) 60%, var(--wb-ink)); margin: 1px 0 0; }
        .cw-close {
            margin-left: auto; border: none; background: transparent; cursor: pointer;
            color: var(--wb-muted); padding: 6px; border-radius: 8px; line-height: 0;
        }
        .cw-close:hover { color: var(--wb-ink); background: var(--wb-cell); }

        /* ── Message stream ─────────────────────────────────────────── */
        .cw-stream {
            flex: 1 1 auto; overflow-y: auto; padding: 16px 14px 10px;
            display: flex; flex-direction: column; gap: 10px;
            scrollbar-width: thin;
            scrollbar-color: color-mix(in srgb, var(--accent) 45%, transparent) transparent;
        }
        .cw-stream::-webkit-scrollbar { width: 6px; }
        .cw-stream::-webkit-scrollbar-thumb { background: color-mix(in srgb, var(--accent) 45%, transparent); border-radius: 999px; }
        .cw-msg { max-width: 86%; padding: 10px 13px; white-space: pre-line; overflow-wrap: break-word; }
        .cw-bot {
            align-self: flex-start; background: var(--wb-cell); color: var(--wb-ink);
            border-radius: 14px 14px 14px 4px;
        }
        .cw-user {
            align-self: flex-end; background: var(--accent); color: var(--wb-accent-ink);
            border-radius: 14px 14px 4px 14px;
        }
        .cw-error { border-left: 3px solid #B3563E; }

        /* Typing indicator */
        .cw-typing { display: inline-flex; gap: 4px; align-items: center; padding: 13px 14px; }
        .cw-typing span {
            width: 6px; height: 6px; border-radius: 50%; background: var(--wb-muted);
            animation: cw-bounce 1.1s infinite ease-in-out;
        }
        .cw-typing span:nth-child(2) { animation-delay: .15s; }
        .cw-typing span:nth-child(3) { animation-delay: .3s; }
        @keyframes cw-bounce { 0%, 70%, 100% { transform: translateY(0); opacity: .5; } 35% { transform: translateY(-4px); opacity: 1; } }

        /* Quick-reply chips */
        .cw-chips { display: flex; flex-wrap: wrap; gap: 7px; align-self: flex-start; max-width: 96%; }
        .cw-chip {
            border: 1px solid color-mix(in srgb, var(--accent) 55%, var(--wb-line));
            color: var(--accent-ink); background: transparent; cursor: pointer;
            border-radius: 999px; padding: 7px 14px; font-size: 13.5px; font-weight: 600;
            font-family: var(--wb-body); transition: background-color .15s ease, color .15s ease;
            text-align: start;
        }
        .cw-chip:hover { background: var(--accent); color: var(--wb-accent-ink); border-color: var(--accent); }
        .cw-chip.cw-chip-primary { background: var(--accent); color: var(--wb-accent-ink); border-color: var(--accent); }
        .cw-chip.cw-chip-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
        .cw-chip small { display: block; font-weight: 500; font-size: 11.5px; opacity: .75; }

        /* Summary card inside the confirm bubble */
        .cw-summary { border-top: 1px solid var(--wb-line); margin-top: 8px; padding-top: 8px; font-size: 13.5px; }
        .cw-summary div { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; }
        .cw-summary dt { color: var(--wb-muted); }
        .cw-summary dd { margin: 0; font-weight: 600; text-align: end; }

        /* ── Composer ───────────────────────────────────────────────── */
        .cw-composer {
            display: flex; gap: 8px; align-items: center; flex-shrink: 0;
            padding: 10px 12px; border-top: 1px solid var(--wb-line);
        }
        .cw-input {
            flex: 1 1 auto; border: none; border-bottom: 1.5px solid var(--wb-line);
            background: transparent; color: var(--wb-ink); font-family: var(--wb-body);
            font-size: 14.5px; padding: 8px 2px; outline: none; border-radius: 0;
        }
        .cw-input:focus { border-bottom-color: var(--accent); }
        .cw-input:disabled { opacity: .45; }
        .cw-send {
            width: 38px; height: 38px; border-radius: 50%; border: none; cursor: pointer;
            background: var(--accent); color: var(--wb-accent-ink); line-height: 0;
            display: inline-flex; align-items: center; justify-content: center;
            transition: background-color .15s ease;
        }
        .cw-send:hover { background: var(--accent-hover); }
        .cw-send:disabled { opacity: .4; cursor: default; }
        .cw-foot { text-align: center; font-size: 10.5px; color: var(--wb-faint); padding: 0 0 7px; flex-shrink: 0; }
    </style>
</head>
<body>
    <header class="cw-head">
        @if ($branding['logo_url'])
            <img src="{{ $branding['logo_url'] }}" alt="" class="cw-logo" />
        @endif
        <div>
            <h1 class="cw-title">{{ $salon->name }}</h1>
            <p class="cw-sub">{{ __('Booking assistant') }}</p>
        </div>
        <button type="button" class="cw-close" id="cw-close" aria-label="{{ __('Close chat') }}">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
    </header>

    <main class="cw-stream" id="cw-stream" aria-live="polite"></main>

    <form class="cw-composer" id="cw-composer" novalidate>
        <input type="text" class="cw-input" id="cw-input" autocomplete="off"
               placeholder="{{ __('Pick an option above…') }}" disabled aria-label="{{ __('Your message') }}" />
        <button type="submit" class="cw-send" id="cw-send" disabled aria-label="{{ __('Send') }}">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 10h13M11 5l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </form>
    <p class="cw-foot">{{ $salon->name }}</p>

    <script>
    (function () {
        'use strict';
        var CATALOGUE = @json($catalogue);
        var TOKEN = @json($widgetToken);
        var INFO = @json($info);
        var API = {
            availability: @json(($endpoints ?? [])['availability'] ?? route('salon.widget.availability', ['salon' => $salon->slug])),
            month: @json(($endpoints ?? [])['month'] ?? route('salon.widget.month', ['salon' => $salon->slug])),
            book: @json(($endpoints ?? [])['book'] ?? route('salon.widget.book', ['salon' => $salon->slug])),
        };
        var I18N = {
            greet: @json(__('Hi! Welcome to :salon. I can book your next appointment right here — or answer a quick question.', ['salon' => $salon->name])),
            menuBook: @json(__('Book an appointment')),
            menuServices: @json(__('Services & prices')),
            menuHours: @json(__('Opening hours')),
            menuWhere: @json(__('Where are you?')),
            pickService: @json(__('Lovely. Which service would you like?')),
            pickStylist: @json(__('Who would you like for your :service?')),
            anyStylist: @json(__('Any available stylist')),
            pickDay: @json(__('Which day suits you?')),
            moreDays: @json(__('More dates…')),
            noDays: @json(__("I couldn't find an open day in the next few weeks for that. Want to try a different service or stylist?")),
            pickTime: @json(__('Here are the open times on :date:')),
            noTimes: @json(__('That day just filled up — pick another day?')),
            askName: @json(__("Great choice. What's your name?")),
            askPhone: @json(__('Thanks, :name! What phone number should we use for your booking?')),
            askEmail: @json(__('And an email for your confirmation? (optional)')),
            skip: @json(__('Skip')),
            badName: @json(__("That name doesn't look right — just your name, please (letters only work best).")),
            badPhone: @json(__("Hmm, that doesn't look like a phone number. Digits please — e.g. 555 010 1234.")),
            badEmail: @json(__("That email doesn't look right — mind checking it? Or tap Skip.")),
            confirmLead: @json(__("Here's your appointment — shall I book it?")),
            confirm: @json(__('Confirm booking')),
            startOver: @json(__('Start over')),
            booked: @json(__("You're booked! :detail — we'll see you then.")),
            bookedMore: @json(__('Anything else I can help with?')),
            slotGone: @json(__('Oh no — that time was just taken. Here are the times still open:')),
            failed: @json(__("Something went wrong on our end. Give it another try in a moment?")),
            servicesLead: @json(__('Here is what we offer:')),
            hoursLead: @json(__('Our opening hours:')),
            whereLead: @json(__('You can find us here:')),
            closed: @json(__('Closed')),
            back: @json(__('Back to menu')),
            typeHere: @json(__('Type here…')),
            pickOption: @json(__('Pick an option above…')),
            service: @json(__('Service')),
            stylist: @json(__('Stylist')),
            when: @json(__('When')),
            name: @json(__('Name')),
            phone: @json(__('Phone')),
        };

        var state = { step: null, service: null, stylist: null, date: null, slot: null, name: '', phone: '', email: '', days: [], dayPage: 0 };

        var stream = document.getElementById('cw-stream');
        var input = document.getElementById('cw-input');
        var send = document.getElementById('cw-send');
        var composer = document.getElementById('cw-composer');

        // -- primitives ---------------------------------------------------
        function scrollDown() { stream.scrollTop = stream.scrollHeight; }

        function bot(text, opts) {
            var typing = document.createElement('div');
            typing.className = 'cw-msg cw-bot cw-typing';
            typing.innerHTML = '<span></span><span></span><span></span>';
            stream.appendChild(typing);
            scrollDown();
            setTimeout(function () {
                var el = document.createElement('div');
                el.className = 'cw-msg cw-bot' + ((opts && opts.error) ? ' cw-error' : '');
                if (opts && opts.html) { el.appendChild(opts.html); }
                el.insertBefore(document.createTextNode(text), el.firstChild);
                stream.replaceChild(el, typing);
                scrollDown();
                if (opts && opts.then) { opts.then(); }
            }, (opts && opts.fast) ? 140 : 420);
        }

        function user(text) {
            var el = document.createElement('div');
            el.className = 'cw-msg cw-user';
            el.textContent = text;
            clearChips();
            stream.appendChild(el);
            scrollDown();
        }

        function chips(options) {
            clearChips();
            var wrap = document.createElement('div');
            wrap.className = 'cw-chips';
            wrap.setAttribute('data-chips', '1');
            options.forEach(function (opt) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'cw-chip' + (opt.primary ? ' cw-chip-primary' : '');
                b.textContent = opt.label;
                if (opt.detail) {
                    var d = document.createElement('small');
                    d.textContent = opt.detail;
                    b.appendChild(d);
                }
                b.addEventListener('click', function () { opt.pick(); });
                wrap.appendChild(b);
            });
            stream.appendChild(wrap);
            scrollDown();
        }

        function clearChips() {
            var old = stream.querySelectorAll('[data-chips]');
            for (var i = 0; i < old.length; i++) { old[i].remove(); }
        }

        function expectTyping(placeholder) {
            input.disabled = false;
            send.disabled = false;
            input.placeholder = placeholder || I18N.typeHere;
            input.focus();
        }

        function stopTyping() {
            input.value = '';
            input.disabled = true;
            send.disabled = true;
            input.placeholder = I18N.pickOption;
        }

        // -- the menu -----------------------------------------------------
        function menu(lead) {
            state.step = 'menu';
            var options = [
                { label: I18N.menuBook, primary: true, pick: startBooking },
                { label: I18N.menuServices, pick: showServices },
                { label: I18N.menuHours, pick: showHours },
            ];
            if (INFO.address || INFO.phone) { options.push({ label: I18N.menuWhere, pick: showWhere }); }
            bot(lead, { then: function () { chips(options); } });
        }

        function showServices() {
            user(I18N.menuServices);
            var lines = CATALOGUE.map(function (s) {
                return s.name + ' — ' + s.duration_minutes + ' min' + (s.price ? ' · ' + s.price : '');
            }).join('\n');
            bot(I18N.servicesLead + '\n' + lines, { then: function () { afterInfo(); } });
        }

        function showHours() {
            user(I18N.menuHours);
            var lines = INFO.hours.map(function (h) {
                return h.day + ': ' + (h.open === null ? I18N.closed : h.open);
            }).join('\n');
            bot(I18N.hoursLead + '\n' + lines, { then: function () { afterInfo(); } });
        }

        function showWhere() {
            user(I18N.menuWhere);
            var lines = [];
            if (INFO.address) { lines.push(INFO.address); }
            if (INFO.phone) { lines.push(INFO.phone); }
            bot(I18N.whereLead + '\n' + lines.join('\n'), { then: function () { afterInfo(); } });
        }

        function afterInfo() {
            chips([
                { label: I18N.menuBook, primary: true, pick: startBooking },
                { label: I18N.back, pick: function () { menu(I18N.bookedMore); } },
            ]);
        }

        // -- booking: service → stylist → day → time ----------------------
        function startBooking() {
            user(I18N.menuBook);
            state.service = null; state.stylist = null; state.date = null; state.slot = null;
            state.step = 'service';
            bot(I18N.pickService, { then: function () {
                chips(CATALOGUE.map(function (s) {
                    return {
                        label: s.name,
                        detail: s.duration_minutes + ' min' + (s.price ? ' · ' + s.price : ''),
                        pick: function () { pickService(s); },
                    };
                }));
            } });
        }

        function pickService(service) {
            user(service.name);
            state.service = service;
            state.step = 'stylist';
            bot(I18N.pickStylist.replace(':service', service.name), { then: function () {
                var options = [{ label: I18N.anyStylist, primary: true, pick: function () { pickStylist('any', I18N.anyStylist); } }];
                service.stylists.forEach(function (st) {
                    options.push({ label: st.name, pick: function () { pickStylist(String(st.id), st.name); } });
                });
                chips(options);
            } });
        }

        function pickStylist(id, label) {
            user(label);
            state.stylist = id;
            state.step = 'day';
            loadDays();
        }

        function monthKey(offset) {
            var d = new Date();
            d.setDate(1);
            d.setMonth(d.getMonth() + offset);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
        }

        function loadDays() {
            var url = API.month + '?services[]=' + state.service.id + '&stylist=' + encodeURIComponent(state.stylist) + '&month=';
            Promise.all([0, 1].map(function (offset) {
                return fetch(url + monthKey(offset), { headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.json(); })
                    .then(function (data) { return data.dates || []; })
                    .catch(function () { return []; });
            })).then(function (months) {
                state.days = months[0].concat(months[1]);
                state.dayPage = 0;
                if (state.days.length === 0) {
                    bot(I18N.noDays, { then: function () {
                        chips([
                            { label: I18N.menuBook, primary: true, pick: startBooking },
                            { label: I18N.back, pick: function () { menu(I18N.bookedMore); } },
                        ]);
                    } });
                    return;
                }
                bot(I18N.pickDay, { then: offerDays });
            });
        }

        function dayLabel(iso) {
            var d = new Date(iso + 'T12:00:00');
            try {
                return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
            } catch (e) { return iso; }
        }

        function offerDays() {
            var page = state.days.slice(state.dayPage * 6, state.dayPage * 6 + 6);
            var options = page.map(function (iso) {
                return { label: dayLabel(iso), pick: function () { pickDay(iso); } };
            });
            if (state.days.length > (state.dayPage + 1) * 6) {
                options.push({ label: I18N.moreDays, pick: function () { state.dayPage++; offerDays(); } });
            }
            chips(options);
        }

        function pickDay(iso) {
            user(dayLabel(iso));
            state.date = iso;
            state.step = 'time';
            loadTimes(false);
        }

        function loadTimes(afterRace) {
            var url = API.availability + '?services[]=' + state.service.id + '&stylist=' + encodeURIComponent(state.stylist) + '&date=' + state.date;
            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var slots = data.slots || [];
                    if (slots.length === 0) {
                        bot(I18N.noTimes, { then: function () {
                            state.step = 'day';
                            offerDays();
                        } });
                        return;
                    }
                    var lead = afterRace ? I18N.slotGone : I18N.pickTime.replace(':date', dayLabel(state.date));
                    bot(lead, { then: function () {
                        chips(slots.slice(0, 14).map(function (slot) {
                            return {
                                label: slot.time + (state.stylist === 'any' && slot.stylist ? ' · ' + slot.stylist : ''),
                                pick: function () { pickTime(slot); },
                            };
                        }));
                    } });
                })
                .catch(function () { oops(); });
        }

        function pickTime(slot) {
            user(slot.time);
            state.slot = slot;
            state.step = 'name';
            bot(I18N.askName, { then: function () { expectTyping(I18N.name + '…'); } });
        }

        // -- client details (typed) --------------------------------------
        function firstName(full) { return full.split(/\s+/)[0] || full; }

        function handleTyped(value) {
            if (state.step === 'name') {
                if (value.length < 2 || value.length > 120 || /[<>{}\\\/@;]|\d{4,}/.test(value)) {
                    bot(I18N.badName, { fast: true, then: function () { expectTyping(I18N.name + '…'); } });
                    return;
                }
                state.name = value;
                state.step = 'phone';
                bot(I18N.askPhone.replace(':name', firstName(value)), { then: function () { expectTyping(I18N.phone + '…'); } });
                return;
            }
            if (state.step === 'phone') {
                var digits = value.replace(/\D+/g, '');
                if (digits.length < 7 || digits.length > 15) {
                    bot(I18N.badPhone, { fast: true, then: function () { expectTyping(I18N.phone + '…'); } });
                    return;
                }
                state.phone = value;
                state.step = 'email';
                bot(I18N.askEmail, { then: function () {
                    expectTyping('Email…');
                    chips([{ label: I18N.skip, pick: function () { user(I18N.skip); state.email = ''; stopTyping(); confirmStep(); } }]);
                } });
                return;
            }
            if (state.step === 'email') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
                    bot(I18N.badEmail, { fast: true, then: function () {
                        expectTyping('Email…');
                        chips([{ label: I18N.skip, pick: function () { user(I18N.skip); state.email = ''; stopTyping(); confirmStep(); } }]);
                    } });
                    return;
                }
                state.email = value;
                confirmStep();
            }
        }

        // -- explicit confirmation, then the booking ---------------------
        function confirmStep() {
            state.step = 'confirm';
            var dl = document.createElement('dl');
            dl.className = 'cw-summary';
            [[I18N.service, state.service.name],
             [I18N.stylist, state.slot.stylist || I18N.anyStylist],
             [I18N.when, dayLabel(state.date) + ' · ' + state.slot.time],
             [I18N.name, state.name],
             [I18N.phone, state.phone]].forEach(function (row) {
                var line = document.createElement('div');
                var dt = document.createElement('dt'); dt.textContent = row[0];
                var dd = document.createElement('dd'); dd.textContent = row[1];
                line.appendChild(dt); line.appendChild(dd);
                dl.appendChild(line);
            });
            bot(I18N.confirmLead, { html: dl, then: function () {
                chips([
                    { label: I18N.confirm, primary: true, pick: book },
                    { label: I18N.startOver, pick: function () { user(I18N.startOver); startBooking(); } },
                ]);
            } });
        }

        function book() {
            user(I18N.confirm);
            fetch(API.book, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    service: state.service.id,
                    stylist: state.slot.stylist_id ? String(state.slot.stylist_id) : state.stylist,
                    date: state.slot.date || state.date,
                    time: state.slot.time,
                    client: { name: state.name, phone: state.phone, email: state.email || null },
                    token: TOKEN,
                    website: '',
                    surface: 'chat',
                }),
            })
                .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
                .then(function (result) {
                    if (result.status === 201 && result.data.success) {
                        var detail = dayLabel(state.date) + ' · ' + state.slot.time;
                        bot(I18N.booked.replace(':detail', detail), { then: function () {
                            menu(I18N.bookedMore);
                        } });
                        return;
                    }
                    if (result.status === 409) {
                        state.step = 'time';
                        loadTimes(true);
                        return;
                    }
                    oops(result.data && result.data.message);
                })
                .catch(function () { oops(); });
        }

        function oops(message) {
            bot(message || I18N.failed, { error: true, then: function () {
                chips([
                    { label: I18N.menuBook, primary: true, pick: startBooking },
                    { label: I18N.back, pick: function () { menu(I18N.bookedMore); } },
                ]);
            } });
        }

        // -- wiring -------------------------------------------------------
        composer.addEventListener('submit', function (event) {
            event.preventDefault();
            var value = input.value.trim();
            if (value === '' || input.disabled) { return; }
            user(value);
            stopTyping();
            handleTyped(value);
        });

        document.getElementById('cw-close').addEventListener('click', function () {
            window.parent.postMessage({ type: 'bts:chat:close' }, '*');
        });

        menu(I18N.greet);
    })();
    </script>
</body>
</html>
