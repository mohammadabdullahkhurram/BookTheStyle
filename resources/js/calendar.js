import Calendar from '@toast-ui/calendar';

/**
 * Alpine glue for the booking calendar. A thin Livewire component owns the data
 * (server-side, salon-scoped, role-filtered); this layer owns only the Toast UI
 * instance and view state (current date / day|week / per-stylist visibility).
 *
 * Data flow:
 *   - on init the calendar reports its visible range to the server (setRange),
 *     which replies with the feed via a `calendar:data` Livewire dispatch;
 *   - navigating (prev/next/today/view) re-reports the range;
 *   - `wire:poll` re-dispatches the current feed every few seconds, so new or
 *     changed bookings appear without a manual reload.
 *
 * Nothing here is trusted: clicking an empty slot just asks the server to open
 * the prefilled booking form, which re-validates via the Phase 3 slot engine.
 */

// Token-mapped theme (kept in sync with resources/css/app.css). The accent is
// per-salon and passed in from the server.
function themeFor(accent) {
    return {
        common: {
            backgroundColor: '#FFFFFF',
            border: '1px solid #E7E1D8',
            gridSelection: {
                backgroundColor: 'rgba(31, 111, 107, 0.10)',
                border: `1px solid ${accent}`,
            },
            dayName: { color: '#6B6660' },
            holiday: { color: '#B23A2E' },
            saturday: { color: '#6B6660' },
            today: { color: '#FFFFFF' },
        },
        week: {
            dayName: {
                borderLeft: 'none',
                borderTop: '1px solid #E7E1D8',
                borderBottom: '1px solid #E7E1D8',
                backgroundColor: '#FAF8F5',
            },
            today: { color: accent, backgroundColor: 'rgba(31, 111, 107, 0.04)' },
            pastDay: { color: '#6B6660' },
            timeGrid: { borderRight: '1px solid #E7E1D8' },
            timeGridLeft: { backgroundColor: '#FAF8F5', borderRight: '1px solid #E7E1D8', width: '72px' },
            timeGridHalfHour: { borderBottom: 'none' },
            timeGridHourLine: { borderBottom: '1px solid #F2EEE8' },
            nowIndicatorLabel: { color: accent },
            nowIndicatorPast: { border: `1px dashed ${accent}` },
            nowIndicatorBullet: { backgroundColor: accent },
            nowIndicatorToday: { border: `1px solid ${accent}` },
            gridSelection: { color: accent },
        },
    };
}

export default function bookingCalendar(config) {
    return {
        cal: null,
        calendars: [],
        hidden: {}, // stylistId -> true when filtered out
        label: '',
        view: 'week',

        init() {
            this.cal = new Calendar(this.$refs.cal, {
                defaultView: 'week',
                usageStatistics: false,
                isReadOnly: false,
                timezone: { zones: [{ timezoneName: config.timezone }] },
                theme: themeFor(config.accent),
                gridSelection: { enableDblClick: false, enableClick: true },
                week: {
                    startDayOfWeek: 1,
                    dayName: true,
                    eventView: ['time'],
                    taskView: false,
                    hourStart: 8,
                    hourEnd: 20,
                },
                template: {
                    time(event) {
                        const t = event.title ?? '';
                        const b = event.body ? ` · ${event.body}` : '';
                        return `<span style="font-weight:600">${t}</span>${b}`;
                    },
                },
            });

            // Booking events open the detail panel; blocked time is inert.
            this.cal.on('clickEvent', ({ event }) => {
                if (event.raw && event.raw.type === 'booking') {
                    this.$wire.openBooking(Number(event.raw.bookingId));
                }
            });

            // Empty-slot selection → server opens the prefilled booking form.
            this.cal.on('selectDateTime', (info) => {
                this.cal.clearGridSelections();
                this.$wire.selectSlot(info.start.toDate().toISOString());
            });

            // Server pushes the feed (initial range reply + every poll). The
            // dispatched payload may arrive as { payload }, [payload], or raw —
            // unwrap defensively.
            this.$wire.on('calendar:data', (e) => {
                const data = e && e.payload ? e.payload : (Array.isArray(e) ? e[0] : e);
                this.applyData(data);
            });

            this.syncRange(true);
            this.updateLabel();
        },

        applyData(data) {
            if (!data) return;

            this.calendars = data.calendars ?? [];
            // Toast UI calendars carry the per-stylist colours; blocked time is a
            // fixed muted calendar.
            const tuiCalendars = this.calendars.map((c) => ({
                id: c.id,
                name: c.name,
                backgroundColor: c.color,
                borderColor: c.color,
                dragBackgroundColor: c.color,
            }));
            tuiCalendars.push({ id: 'blocked', name: 'Blocked', backgroundColor: 'rgba(107,102,96,0.14)' });
            this.cal.setCalendars(tuiCalendars);

            if (data.hourStart != null && data.hourEnd != null) {
                this.cal.setOptions({ week: { hourStart: data.hourStart, hourEnd: data.hourEnd } });
            }

            this.cal.clear();
            this.cal.createEvents([...(data.events ?? []), ...(data.blocks ?? [])]);
            this.applyVisibility();
        },

        // --- Navigation -----------------------------------------------------
        prev() { this.cal.prev(); this.syncRange(); this.updateLabel(); },
        next() { this.cal.next(); this.syncRange(); this.updateLabel(); },
        today() { this.cal.today(); this.syncRange(); this.updateLabel(); },
        setView(view) {
            this.view = view;
            this.cal.changeView(view);
            this.syncRange();
            this.updateLabel();
        },

        // Report the visible range to the server, which replies with the feed.
        syncRange() {
            const start = this.cal.getDateRangeStart().toDate();
            const end = this.cal.getDateRangeEnd().toDate();
            // Pad the end to the close of its day so an event that runs late is
            // still fetched.
            end.setHours(23, 59, 59, 999);
            this.$wire.setRange(start.toISOString(), end.toISOString());
        },

        updateLabel() {
            const start = this.cal.getDateRangeStart().toDate();
            const end = this.cal.getDateRangeEnd().toDate();
            const opts = { month: 'short', day: 'numeric' };
            this.label = this.view === 'day'
                ? start.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric' })
                : `${start.toLocaleDateString(undefined, opts)} – ${end.toLocaleDateString(undefined, { ...opts, year: 'numeric' })}`;
        },

        // --- Per-stylist filter (master view) -------------------------------
        toggle(stylistId) {
            this.hidden[stylistId] = !this.hidden[stylistId];
            this.applyVisibility();
        },
        applyVisibility() {
            this.calendars.forEach((c) => {
                this.cal.setCalendarVisibility(c.id, !this.hidden[c.id]);
            });
        },
    };
}
