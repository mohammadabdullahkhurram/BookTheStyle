# BookTheStyle — UI/UX audit (launch readiness)

**Date:** 2026-07-11 · **Method:** full code-level review of every screen, layout, shared component, and email template against `DESIGN-TOKENS.md`, `CLAUDE.md`, WCAG 2.1 AA, and researched staff-facing UX conventions from Fresha, Vagaro, Booksy, GlossGenius, Mangomint, and Boulevard. Read-only — no code was changed.

**Severity scale:**
- **[Critical]** — broken or unusable experience; blocks launch outright.
- **[High]** — must fix before launch; causes data loss, lockout, real user harm, or fails a core workflow expectation.
- **[Medium]** — should fix; noticeable quality/consistency/usability gap.
- **[Low]** — polish; batch when convenient.

---

## Executive summary

The design system is the strongest part of the product: token fidelity in `resources/css/app.css` is essentially exact against `DESIGN-TOKENS.md` (hexes, radii, shadows, status pills all match), the accent-swap architecture works as specced, sentence case and the no-emoji rule hold everywhere, and several flows (slot-engine-driven booking, GHL secret hygiene, allergy surfacing, temp-password onboarding) are genuinely better than competitor equivalents.

What blocks launch falls into five themes:

1. **No mobile story.** The sidebar has zero responsive classes — on a phone it permanently consumes 244px and there is no drawer or hamburger. Six-plus screens render fixed multi-column tables inside `overflow-hidden` cards with no scroll fallback. For a front-desk/stylist product this is the single biggest gap.
2. **Two functional bugs that lose or corrupt user data.** Editing a date-specific availability entry deletes the original *before* validating the replacement (mistype a time → entry gone, GHL sync fired). The reschedule modal computes free slots from only the *first* service of a multi-service booking, so it offers times the full visit cannot fit.
3. **Calendar week view hides bookings.** Week columns merge all stylists with no overlap-lane algorithm; concurrent bookings stack invisibly on top of each other. A busy salon's week view silently underreports the schedule.
4. **Destructive actions commit in one click, everywhere.** Cancel, no-show, reschedule-by-chip-click, deactivate service/staff/salon, reset password, remove time off, disable 2FA, regenerate recovery codes — none confirm, none undo, several fire GHL side-effects. One systematic `wire:confirm` sweep fixes most of it.
5. **A token-level contrast failure.** `--color-faint #9C9890` (~2.8:1 on white) and its neighbors are used for real content — empty states, helper text, overlines, the cancelled pill — across the whole app. One token change plus a usage sweep fixes dozens of WCAG failures at once.

Secondary but launch-relevant: the public "book a call" page ships internal placeholder copy ("Your scheduling tool drops in here"); the 2FA recovery-code path is unreachable by keyboard (lockout risk); the booking form can fail validation with no visible error; the settings page renders blank on a bad URL hash; client profiles are workflow dead ends (no "New booking" CTA, inert phone/email text).

Also noted: **CLAUDE.md says the calendar is Toast UI, but the implementation is a fully custom server-rendered grid** (`app/Services/Calendar/CalendarData.php`) — no Toast UI dependency exists in `package.json`. Not a UX defect, but the spec and the roadmap implications (drag-to-reschedule must be hand-built) should be reconciled.

Counts: **2 Critical · ~26 High · ~45 Medium · ~55 Low.**

---

## Industry baseline (what competitors do)

Researched from Fresha, Vagaro, Booksy, GlossGenius, Mangomint, and Boulevard help centers and product docs. Key conventions used as the comparison bar below:

- **Calendar:** resource columns per stylist with a team filter; drag-to-reschedule with a confirm dialog (conflict list + "notify client?" toggle); click-empty-slot-to-book; a current-time line; day/3-day/week/month with one-tap drill-down; color by service or staff with status conveyed redundantly (color + pill/icon). Fresha's status ladder: Booked → Confirmed → Arrived → Started → Complete, no-show only after start time, cancel only before.
- **Front-desk booking:** client-first flow with search-as-you-type, inline create, and an anonymous **walk-in** option; multi-service stacking with per-service stylist override; only feasible slots shown; deliberate double-booking allowed but surfaced explicitly (Vagaro); "rebook last visit" accelerators.
- **Check-in:** one-tap status transitions from the calendar tile; Mangomint's SMS self-check-in → waiting room → stylist push is the leading edge; Boulevard ships a dedicated front-desk board (waiting / in service / done).
- **Client CRM:** header snapshot with tappable contact shortcuts + total visits/spend; tabs for upcoming vs past appointments, notes (formulas/allergies), files; profile essentials reachable without leaving the calendar (GlossGenius).
- **Availability:** visual week grid; repeating patterns ("every 2 weeks" = the industry's copy-week); typed time-off categories; schedule changes **never silently strand bookings** — conflicts are counted and flagged for manual rescheduling.
- **Reports:** every product leads with the same tiles — revenue + trend, appointment counts vs cancellations/no-shows, occupancy %, new-vs-returning retention, top services/staff — always with a previous-period comparison.
- **General:** the calendar is home; every frequent action ≤1 tap from a tile; getting-started checklist for onboarding; front desk on desktop/tablet, stylists on phones.

---

## Per-screen findings

### 1. Design system & shared chrome
`resources/css/app.css`, `layouts/app.blade.php`, `layouts/app/sidebar.blade.php`, `partials/head.blade.php`, `components/ui/*`

**Strengths:** exact token fidelity (`app.css:28–109`); the zinc→warm remap (`app.css:74–84`) and dark-mode kill-switch (`app.css:12`); accent architecture per spec (`head.blade.php:19–22`); `x-ui.help-modal` a11y (x-trap, `aria-modal`, reduced-motion at `app.css:236–241`); only free Flux installed.

- **[Critical] No mobile navigation exists.** `layouts/app/sidebar.blade.php:25–27` — the `<aside>` is `sticky h-svh w-[244px]` (76px collapsed) with no `sm:`/`md:`/`lg:` classes anywhere in the file. At 375px the sidebar leaves ~130px for content; no off-canvas drawer, hamburger, or bottom nav. Fix: below `lg`, hide the sidebar and render an off-canvas drawer (the `bts-drawer` animation already exists at `app.css:222–241`) behind a top-bar hamburger.
- **[High] Collapsed sidebar links have no accessible name and no tooltip.** `sidebar.blade.php:54,62,69…` — labels are `x-show="!collapsed"` (display:none), so collapsed nav is icon-only links with no name for screen readers and no hover tooltip for sighted users. Fix: `aria-label` + `flux:tooltip` (free) per link, or visually-hidden labels.
- **[Medium] Inputs ship ~40px tall, not the specced 48px.** `app.css:287–293` remaps Flux control colors/radius but never sets height; `.bts-btn` *is* 48px (`h-12`), so button-beside-input rows are visibly misaligned. Reconcile: set `height: 3rem` on `[data-flux-control]`, or amend DESIGN-TOKENS and shrink buttons.
- **[Medium] `x-ui.button` has no disabled/loading affordance** (`components/ui/button.blade.php`) — Livewire submits are double-clickable unless every call site hand-rolls `wire:loading.attr="disabled"`. Bake `disabled:opacity-50 disabled:pointer-events-none` into `.bts-btn` and add a loading prop.
- **[Medium] Duplicated hardcoded hexes bypass the token layer:** status colors twice (`status-pill.blade.php:6–14`), stat tones twice (`stat-card.blade.php:9–15` vs `--color-info/success/danger` at `app.css:66–70`), booked-by dots (`booked-by.blade.php:10–15`), sidebar chip `#1E1D2A` twice (`sidebar.blade.php:171,180`) while `--color-sidebar-dark` (`app.css:41`) sits unused.
- **[Medium] Dead flux-pro `@source` line** at `app.css:16` scans `vendor/livewire/flux-pro/stubs/**` — the package isn't installed; delete before it silently activates.
- **[Low]** `.bts-btn-sm` uses off-scale `rounded-[10px]` (`app.css:192`); `<title>` falls back to "Laravel" (`head.blade.php:5`); no `meta description`/`theme-color`; auth-card and help-modal shadows are off the two-shadow scale (`layouts/auth/simple.blade.php:14`) — add as `--shadow-hero`/`--shadow-overlay` tokens or normalize; `page-header.blade.php:10` re-implements the overline instead of `.bts-overline`; unknown statuses silently render Booked-grey (`status-pill.blade.php:18`).

### 2. Today dashboard
`resources/views/pages/salon/dashboard.blade.php`

- **[High] The page lies about the date.** H1 "Today at the salon" (`:105`), "Today's bookings" (`:152`), "so far today" (`:120–121`) stay fixed while the date filter (`:126`) can show any day. Viewing last Tuesday still reads "No-shows today". Swap copy when `$date` ≠ today.
- **[High] Six-column table has no mobile strategy** (`:159–193`, inside `overflow-hidden` at `:150`) — no `overflow-x-auto`, no stacked fallback.
- **[Medium] Rows are dead ends** — no click-through to booking detail, client profile, or status actions; the front desk sees "Arrived" but must switch screens to act.
- **[Medium] Client avatars are seeded by stylist id** (`:173,179`) — the same client gets a different pastel per stylist, and the color falsely implies stylist identity in a "Client" cell. Seed by `client_id`.
- **[Medium] No prev/next day arrows** — only the raw date input (`:126`); "yesterday" is a three-interaction picker trip.
- **[Low]** Empty state (`:190`) lacks an "Add booking" CTA; "across 0 stylists" sublabel shows at zero (`:118`); `<th>` lacks `scope="col"` (`:162–167`); header hand-rolls what `x-ui.page-header` provides (`:102–113`).

### 3. Calendar
`resources/views/pages/salon/calendar.blade.php`, `app/Services/Calendar/CalendarData.php`

**Strengths:** click-empty-slot-to-book with stylist+time prefill (`calendar.blade.php:113–126`); blocks correctly colored by service (`CalendarData.php:247`); break/time-off hatching, buffer tails, anti-IDOR checks on modal open; salon-timezone-correct throughout.

- **[Critical] Week view occludes concurrent bookings.** Week columns aggregate all stylists into one day column (`CalendarData.php:118–125`) and every block renders `absolute inset-x-1` at its time position (`calendar.blade.php:291–297`) with no overlap-lane algorithm — two 10:00 bookings stack exactly on top of each other; only the last is visible/clickable. Fix: compute side-by-side lanes, or make week view a per-day summary ("12 bookings") that drills into day view.
- **[High] No current-time indicator** — the most-used affordance in every competitor day view. Emit `nowMin` from `CalendarData::frame()` (`:158–172`) when the anchor is today; draw a 2px accent line.
- **[High] No way to jump to a date** — toolbar is prev/next/today only (`:203–211`); "three weeks from Tuesday" ≈ 21 clicks. Make the range label (`:213`) a date-picker popover.
- **[High] Week→day drill-down missing** — week day headers (`:252–255`) are static text; clicking should set `date` + `view='day'`.
- **[High] Empty-slot buttons are unlabeled and flood the tab order** (`:265–266`) — a 12-hour day × 6 stylists ≈ 144 blank tab stops. Add `aria-label="Book {stylist} at {time}"` minimum; ideally roving tabindex.
- **[Medium] `wire:poll.5s` re-renders the whole grid** (`:182`) — heavy per-stylist queries + full DOM morph every 5s; hovers/clicks can race the morph. 30–60s is the norm.
- **[Medium] No reschedule/edit from the calendar detail modal** (`:317–412`) — status transitions only; no Reschedule (exists on Appointments), no client-profile link. Include `partials/booking-reschedule-modal` here.
- **[Medium] Cancel/No-show commit one-click** (`:386–392`) — see cross-cutting confirmation sweep.
- **[Medium] Blocked-interval label fails contrast** — `text-[11px] text-faint` on the hatched overlay (`:282–285`) ≈ 2.6:1. Use `text-secondary` at ≥12px.
- **[Medium] Stylist filter chips and Day/Week toggle expose no state to AT** — hidden-ness signaled only by `opacity-60` (`:220–224`); segments lack `aria-pressed` (`:197–200`).
- **[Medium] No drag-to-reschedule/extend** — expected in category; hand-build cost is real since the grid is custom (roadmap item).
- **[Low]** 11px time label with `opacity-80` (`:294`) below the 12.5px floor; hardcoded `am`/`pm`, no 24h option (`CalendarData.php:433`); stale doc comment says blocks are "coloured per stylist" (`CalendarData.php:100–104`); stylist header row not `sticky top-0` — names scroll away on tall days (`:247`).

### 4. Appointments list + reschedule modal
`resources/views/pages/salon/appointments/all.blade.php`, `partials/booking-reschedule-modal.blade.php`

- **[High] Reschedule slots ignore every service after the first.** `rescheduleSlots()` (`all.blade.php:150–163`) blocks only the first item's duration + buffer — a 3h cut+color booking gets offered 45-min-shaped slots that collide or misplace the rest of the visit. Same bug in Check-in's copy (`appointments/index.blade.php:125–138`). The modal copy even promises "the stylist and services stay the same." Fix: compute from the full visit span.
- **[High] Clicking a time chip commits the reschedule instantly** (`booking-reschedule-modal.blade.php:29–34`) — no selected state, no confirm, no undo; fires reminders/GHL. Select-then-confirm.
- **[Medium] No stylist/service filters, and search misses stylist names** (`:65–68, 205–215`) — "Maya's June appointments" is impossible. The Today screen already has these selects.
- **[Medium] Default Livewire pagination view** (`:280`) — starter-kit styling, off-token. Publish/override with `bts-*` styles (also on Clients).
- **[Medium] Cancel/No-show one-click** (`:256–262`).
- **[Low]** Slot chips show 24h `H:i` while everything else is `g:i A`; error/warning copy hardcodes `#A23A3A`/`#8A5A1E` (`modal:13,22`); walk-in pill inline style copy-pasted in 4 files (`all.blade.php:234`, `dashboard:181`, `calendar:327`); GHL sync-failure detail hidden in `title=` (`:237`, invisible on touch/AT) and labeled "Sync failed" here vs "GoHighLevel sync failed" on the calendar; no results count above the list.

### 5. Check-in
`resources/views/pages/salon/appointments/index.blade.php`

- **[High] No-show and Cancel are one tap, terminal, irreversible** — `changeStatus` (`:60–67`) commits immediately and `BookingStatus::allowedTransitions()` (`app/Enums/BookingStatus.php:66–77`) has no path back from NoShow/Cancelled/Arrived. A mis-tap permanently marks a present client a no-show. `wire:confirm` minimum; ideally a toast Undo window or admin revert.
- **[High] The live screen never updates itself** — no `wire:poll`; bookings from the voice AI, chat widget, or another terminal don't appear until reload. Add `wire:poll.30s`.
- **[Medium] Action labels are state names, not verbs** — buttons render `$next->label()` (`:223`): "Cancelled" as a button, "Checked in" past tense. Use "Cancel booking", "Mark no-show", "Check in".
- **[Medium] No end time/duration on cards** (`:190–214`) — front desk can't answer "when will she be done?". Show `start – end`.
- **[Medium] Avatar seeding inconsistent** — here by `stylist_id` (`:188`), Clients directory by `client->id` (`clients/index.blade.php:294`); same person, different pastel per screen, violating the palette's "identifies people" rule.
- **[Low]** Search covers name/phone only (`:51–54`); empty state (`:236`) lacks CTA; strictly-today window (`:40–48`) drops after-midnight in-progress visits; dead stylist-scoping branch contradicts the `manageBookings` mount gate (`:25` vs `:49–50, 158–162`).

### 6. New booking flow
`resources/views/pages/salon/bookings/create.blade.php`

**Strengths:** slot-engine truth everywhere (no typed times), back-to-back defaulting for multi-service (`:296–339`), re-validation under lock on save, calendar-click prefill (`:58–67`) — stronger than most competitors' front-desk flows.

- **[High] Per-line validation errors are invisible.** `items.*.time` ("Pick a start time for every service.", `:360`) renders nowhere — no `@error("items.{$i}.time")` in the view; the top block covers only `start`/`items`/`client` (`:396–398`). Submitting with a missing time appears to do nothing. Render per-line errors + scroll-to-first-error.
- **[High] Stale client selection risk.** Search filters the options of a separate `flux:select` (`:410–419`); if the search text changes after selection, `clientId` persists while its option disappears — the select looks empty but submit books the previously chosen client. Replace with a combobox (results as rows, selected client as a dismissible chip).
- **[High] No `?client=` prefill and no "New booking" on the client profile** — booking a known client always means re-searching them. `mount()` reads `date`/`time`/`stylist` only (`:58–67`).
- **[High] Slot chips show 24-hour times** (`format('H:i')`, `:190`) while the review summary shows "1:30 PM" (`:229`). Format per salon locale everywhere (also reschedule modal).
- **[Medium] Quick-add client has no duplicate detection** (`:354–366`) — walk-in traffic breeds duplicate records; competitors surface "matches existing client X" inline.
- **[Medium] Slot walls:** a full open day renders 30–50 chips in one wrapping flex (`:477–484`). Group Morning/Afternoon/Evening (Fresha).
- **[Medium] Selected slot state is color-only and unannounced** (`:480`) — add `aria-pressed`.
- **[Medium] Past dates selectable** (`:440`, no `min`) → misleading "No open times… Try another date or stylist."
- **[Medium] Heavy `wire:model.live` recompute** — every keystroke re-runs `slotsForLine()` per line with several queries each (`:152–194`). Cache per render.
- **[Low]** "— choose —" placeholder options (`:415,449,455`); disabled one-option stylist select for locked stylists (`:454`) — plain text "You" is cleaner; no double-submit guard on "Create booking" (`:530`); Cancel always returns to Today (`:531`) regardless of origin.

### 7. Clients directory
`resources/views/pages/salon/clients/index.blade.php`

**Strengths:** single-query aggregated stats; real mobile card fallback (`:340–365`); empty states distinguish "no results" vs "no clients yet" (`:267–272`).

- **[High] Phone/email are inert text** (`:311–312`) — not `tel:`/`mailto:`/`sms:` links. Calling a client is a core front-desk task; every competitor makes these tappable.
- **[Medium] 8-column table clips instead of scrolling** — `overflow-hidden` card (`:275`), no `overflow-x-auto`.
- **[Medium] "Add a client" is a permanently expanded card above the directory** (`:223–237`), pushing search/list down every visit. Make it a header button opening the existing modal pattern (`:371`).
- **[Medium] Default Livewire pagination** (`:367`) — violates "do not ship the default starter look".
- **[Medium] Search input has no label** (`:241`) — placeholder-only, unlike Check-in's labeled search.
- **[Low]** Only the name cell is clickable (`:293`) — make the row a target; column headers not sortable (sorting lives in a separate select, `:242`); "new" definitions drift between header copy, filter label, and `NEW_CLIENT_DAYS`; `isNew()` uses server TZ (`:144`).

### 8. Client profile
`resources/views/pages/salon/clients/show.blade.php`

**Strengths:** allergy banner + row pill (`:214–223`) is genuinely good safety UX.

- **[High] Dead end: no "New booking" CTA anywhere** (header `:183–212`) — combined with the missing `?client=` prefill, the biggest workflow gap vs Fresha/Booksy/GlossGenius.
- **[Medium] Upcoming and past visits are one undifferentiated list** — `orderByDesc(starts_at)` (`:63–71`) puts future bookings under "Visit history" with no actions. Split Upcoming (with reschedule/cancel) from Past.
- **[Medium] Header breaks the type scale** — `text-[22px] font-semibold` (`:188`) vs the 26px/700 page-heading token; skips `x-ui.page-header`; no back link to the directory.
- **[Low]** Stats derive from the 100-visit-capped list with no "showing latest 100" note (`:86–95`); `ucfirst($method)` renders "Sms" (`:295`); prefs inline form swap doesn't move focus (`:281–304`); contact details inert (same as directory).

### 9. Availability
`resources/views/pages/salon/availability/index.blade.php`, `components/availability/staff-card.blade.php`

**Strengths:** drawer focus-return + `role="dialog"` (`:594–595, :150`); "Copy times to…" matches Fresha-style editing; split shifts; timezone stated; server-gating mirrored in UI; actionable empty states.

- **[High] Editing a date-specific entry can destroy it on validation failure.** `dsSubmit()` (`:475–481`) deletes the original TimeOff via `RemoveTimeOff` *before* the replacement validates; `AddTimeOff` throws on `end ≤ start` (`app/Actions/Availability/AddTimeOff.php:46–50`) *after* the delete — a mistyped "17:00 → 09:00" loses the entry and fires GHL sync on the delete. Also non-transactional across dates × blocks. Validate first / wrap in one transaction.
- **[High] No warning when new hours or time off strand existing bookings.** `SaveWeeklyHours`/`AddTimeOff` never check future bookings. Industry convention (Fresha/Vagaro): count and flag conflicts with a confirm step.
- **[Medium] Time off removed with a single unconfirmed click** (`removeTimeOff` `:582`, trigger `:875–878`) — and it triggers GHL sync.
- **[Medium] Three different week-start orders on one screen:** weekly grid Monday-first (`:54`), copy-popover Sunday-first (`:779`), date-picker Sunday-first (`:522, :911`).
- **[Medium] "Date-specific hours" terminology contradicts itself** — tab says hours, model is TimeOff, helper says "custom unavailability" (`:850`), modal asks "When are you available?" (`:931`), and override-hours days vs days-off are visually identical in the list (`:555–568`). Adopt one term + badge the two kinds.
- **[Medium] Broken save/cancel contract on the dates tab** — modal "Submit" (`:968`) persists immediately; the drawer's "Save date-specific hours" (`:888`) only re-queues GHL sync; "Cancel" (`:887`) doesn't discard anything. Stage-then-commit, or drop drawer-level Save/Cancel there.
- **[Medium] Weekly-grid errors are one undirected message** (`@error('weekly')` `:812`) — key errors per day/row and highlight the offending row.
- **[Low]** Generic "Submit" label (`:968`) and instruction-as-title heading (`:893`); split-shift affordance is icon-only 12px micro icons (`:748–800`); drawer lacks a focus trap (`x-init="$el.focus()"` only, `:644` — the copy popover *does* trap at `:767`); read-only notice copy assumes stylist context (`:684`); `dsToggleDate` aria-label is a raw ISO date (`:919`); `role="tab"` without tablist keyboard semantics (`:670–681`); raw "America/New_York" in edit view (`:713`) vs friendly GMT label on dates tab (`:539–544`).

### 10. Services
`resources/views/pages/salon/services/index.blade.php`, `components/ui/qualified-stylists.blade.php`

- **[High] Service color is unexplained, color-only, and not overridable.** The dot (`:265`) has no label/tooltip/legend (WCAG 1.4.1) and no way to see or change assignment. Add the ServicePalette key name as a `title`/visible label; ideally a 12-swatch picker in the edit modal.
- **[High] Deactivate is one unexplained click** (`toggleActive` `:284–286`) — no confirm, no consequence copy ("Clients can no longer book this; existing bookings unaffected."), no undo.
- **[Medium] The create form is a permanently open card above the list** (`:223–246`) — a settled menu still scrolls past a 4-field + N-stylist form daily. Move to a modal button (Fresha/Booksy pattern); it already shares `x-ui.qualified-stylists` with the edit modal.
- **[Medium] Six-column table not responsive** (`:249`, `overflow-hidden` card).
- **[Medium] Override inputs have placeholder-as-label and no accessible names** (`qualified-stylists.blade.php:26–39`) — "30 min"/"buffer" placeholders vanish on input; screen readers announce unlabeled spinbuttons. Add column headings + `aria-label`s.
- **[Medium] Unchecking a stylist keeps their stale override values visible** (`:115–130` reads only checked ids) — numbers persist next to unchecked names implying they apply. Disable/clear when unchecked.
- **[Medium] No service categories or ordering** (flat alphabetical, `:61`) — all competitors group menus; the booking flow inherits the flat list (product-level gap).
- **[Low]** Status pill hexes hand-rolled at `:276–278`; "None assigned" (service is unbookable!) rendered as muted grey (`:272`) — deserves a warning pill; 13px text-link actions with adjacent unconfirmed "Deactivate" (`:283–286`); whole-row `opacity-65` drops muted text below contrast (`:262`); price rounding silent (`toCents` `:211–216`) and "Price (£, optional)" label awkward.

### 11. Staff
`resources/views/pages/salon/staff/index.blade.php`

**Strengths:** `temp-password-panel.blade.php` pattern (shown once, copy button, forced change explained).

- **[High] Roles and staff types are never explained.** The invite form (`:212–224`) offers Role and Staff type with zero helper text; permissions-vs-function distinction is invisible. Fresha explains each level inline. Add one-line descriptions per option.
- **[Medium] "Send invite" actually mints a password** (`:227`, modal at `:128–131`) — rename "Add staff member" + set expectation ("They'll receive a temporary password by email").
- **[Medium] Reset password fires instantly, no confirmation** (`:185–191`, trigger `:268`).
- **[Medium] No pending/first-login state** — Active/Inactive only; admins can't see who never signed in, and "resend invite" doesn't exist (nearest tool is Reset password, undiscoverable for that purpose). Add an "Invited — hasn't signed in" chip.
- **[Medium] Table not responsive** (`:233`).
- **[Low]** Bio textarea silently hidden on role change (`:303–305`); `__('—')` placeholder cell (`:273`); temp-password modal dismissible by scrim misclick before copying; `staff_type` "Manager" exists in the enum (`StaffType.php:16`) while CLAUDE.md says `stylist | front_desk` — align docs/product language.

### 12. Reports
`resources/views/pages/salon/reports.blade.php`

- **[Medium] Inverted custom range silently collapses** — `range()` (`:69–76`) clamps From>To to a single day while the inputs (`:115–116`) still show the inverted pair. Validate + inline error; add `min`/`max` attrs.
- **[Medium] No previous-period comparison** — the single most standard reports affordance (all competitors lead with deltas). Even "+12% vs previous 30 days" sublabels transform usefulness.
- **[Medium] No loading state on range change** — synchronous recompute, no `wire:loading` dimming; page appears frozen on slow queries.
- **[Medium] No export (CSV)** — owners will ask; nice-to-have, not blocker.
- **[Low]** Bars floor at 2% width (`:153, :192`) — misleading at the tail; hardcoded `bg-[#EFEDE8]` track (`:152, :191` — `bg-muted` exists) and `bg-[#9C9890]` bars (`:153`); AI-vs-other emphasis is color-only; "Estimated revenue —" with info-tone looks broken at zero (`:130`); `lg:grid-cols-5` orphans the fifth card at `sm` (`:123`).

### 13. Salon settings
`resources/views/pages/salon/settings.blade.php`, `partials/ghl-connection-card.blade.php`

**Strengths:** secret hygiene (write-only GHL token `:99–105`; show-once API token `:1143–1147`); per-section saves with toasts; `wire:confirm` on disconnect/rotation/revoke; scopes disclosure and email auto-match in the GHL card (`ghl-connection-card.blade.php:51–71`); excellent timezone-change and no-show automation microcopy (`:761–763, 806`).

- **[High] Invalid/unauthorized URL hash renders a blank page.** `tab` initializes from `window.location.hash` (`:721`) with no whitelist — `#branding2`, or `#integrations` for a manager without `manageGhlConnection` (tab button `@can`-hidden `:732–735`), matches no panel → empty content column, no message. Whitelist against visible tabs, fall back to `general`.
- **[Medium] Back button appears broken** — tab clicks write `location.hash` (`:724–734`) creating history entries, but no `hashchange` listener; back changes the URL, not the panel.
- **[Medium] Voice-AI booking API card is unreachable for its own permission tier** — the card (`:1137–1165`) is gated `manage` but only reachable via the Integrations tab button gated `manageGhlConnection` (`:732`). Align gates or move the card.
- **[Medium] Timezone picker is a raw ~430-option IANA `<select>`** (`:756–760`) — group by continent (`optgroup`) or make filterable; show current time in the zone. (Same on agency salon create, `agency/salons/create.blade.php:162–166`.)
- **[Medium] Webhook secret in permanent plaintext with no copy button** (`:1027, :1031–1032`) — inconsistent with every other secret (show-once/write-only). Mask + copy buttons.
- **[Medium] Feature-flags copy is developer-facing** ("Later phases read these…", `:862`).
- **[Medium] "Test connection" only appears after save and never auto-runs** (`ghl-connection-card.blade.php:75–79`) — status pill can read "Connected" from mere field presence. Auto-verify on save.
- **[Medium] Tab nav has no accessible active state** — class-only; add `aria-current` (also account-settings nav, `pages/settings/layout.blade.php:4–7`).
- **[Low]** No copy button on the one-time API token (`:1146` — reuse calendar-feed's pattern); accent hex input has no live preview/swatch, default Laravel "format is invalid" error (`:645`), and doesn't say clearing reverts to violet; Branding tab holds a single small card — fold into General; hand-rolled status pills (`:934, 936, 986, 988, 1077–1081`, `ghl-connection-card.blade.php:19`) and `style="color:#A23A3A"` errors (`:1067, :1119`); no unsaved-changes protection across six forms; Location/Calendar ID fields lack "where to find this" links.

### 14. Account settings (profile, security, 2FA, passkeys, calendar feed)
`resources/views/pages/settings/*`, `components/passkey-*.blade.php`

- **[High] "Disable 2FA" fires with no confirmation** (`security.blade.php:221`).
- **[Medium] Post-enable 2FA never shows recovery codes** — after `confirmTwoFactor` the modal just closes (`two-factor-setup-modal.blade.php:86–97`); the `setupComplete` state (`:131–137`) is unreachable dead code with wrong copy. Best practice: force recovery codes in front of the user immediately after enabling.
- **[Medium] "Regenerate codes" invalidates all recovery codes without confirmation** (`two-factor/recovery-codes.blade.php:89–98`).
- **[Medium] The 2FA modal is unthemed starter code** — `border-stone-*`, `dark:` classes, `$flux.appearance === 'dark'` logic (`two-factor-setup-modal.blade.php:162–176, 228–241, 259–260`) in a light-only warm-palette app; raw `flux:modal`/`flux:button` instead of `x-ui.*`. Same drift in recovery-codes and delete-user-modal (`:36–41`). **This is the single most visible design-system seam in the app.**
- **[Medium] Delete-account copy is dangerously vague for owners** (`delete-user-modal.blade.php:30`) — does it delete the salon? Bookings? Spell out consequences; consider blocking sole-owner deletion.
- **[Low]** Delete section card-less below two `x-ui.card`s — page rhythm breaks at the destructive action (`delete-user-form.blade.php:7–20`); IA: calendar feed + stylist bio + delete all under "Profile" whose subtitle promises name/email (`profile.blade.php:56–60`); "OTP Code" Title Case (`two-factor-setup-modal.blade.php:197`); "Registering..." three dots vs "…" elsewhere (`passkey-registration.blade.php:83`); icon-only buttons without names (manual-key copy `two-factor-setup-modal.blade.php:293–303`, passkey delete `security.blade.php:273–280`); clipboard writes without failure handling (`calendar-feed.blade.php:64, 111`); Revoke missing from the just-generated ICS state (`:106–126` vs `:141–145`); no session management ("log out other devices") anywhere in Security.

### 15. Auth pages
`resources/views/pages/auth/*`, `components/passkey-verify.blade.php`, `temp-password-panel.blade.php`

**Strengths:** correct `autocomplete` almost everywhere, `viewable` password toggles, autofocus, passkey-first login with graceful fallback (`passkey-verify.blade.php:47`), well-explained forced password change (`force-password.blade.php:5`).

- **[High] 2FA recovery-code path is not keyboard-accessible.** `two-factor-challenge.blade.php:87–90` — the toggle links are `<span @click>` with no `tabindex`/focus style. Keyboard and screen-reader users cannot reach recovery codes at all — lockout risk. Make them `<button type="button">`.
- **[Medium] OTP-path errors may never render** — only `@error('recovery_code')` exists (`:75–79`); verify a wrong `code` shows a visible message (label is sr-only).
- **[Medium] Entire 2FA page behind `x-cloak`** (`:5`) — invisible with no fallback if Alpine fails.
- **[Medium] No visible password requirements** on reset (`reset-password.blade.php:31` — Safari-only `passwordrules`) or force-password (`force-password.blade.php:25–43`); users learn rules by failing.
- **[Medium] Mixed button systems** — passkey `flux:button variant="outline"` beside `x-ui.button` submit (`passkey-verify.blade.php:50–56`): different heights/radii on one card.
- **[Low]** `forgot-password.blade.php:12–19` missing `autocomplete="email"`; "login using a recovery code" → "log in" (`two-factor-challenge.blade.php:88`); "OTP Code" jargon/Title Case (`:56`); force-password has no log-out escape hatch; reset-password lacks a "return to log in" link.

### 16. Public pages
`welcome.blade.php`, `register.blade.php`

**Strengths:** both fully on the token system; the hero + fake-browser calendar preview is launch-quality.

- **[High] The book-a-call page ships internal placeholder copy.** `register.blade.php:59–66` — visitors see a dashed box reading "Calendar embed / Your scheduling tool drops in here" on the page every CTA points at. Wire the iframe via config or replace with a real fallback ("Email us at … to schedule a walkthrough"); add an iframe loading state.
- **[Medium] No meta description or OG/Twitter tags** (`welcome.blade.php:3–15`) — shared links render bare.
- **[Medium] Footer lacks privacy policy / terms links** (`welcome.blade.php:118–128`).
- **[Low]** Calendar preview cramps at 375px — four `flex-1` columns ≈ 70px each (`welcome.blade.php:64–86`); hardcoded `text-[#3A3833]` log-in links (`welcome.blade.php:25`, `register.blade.php:28`); heading order skips h2 (`:36` → `:97`); `hello@bookthestyle.com` hardcoded, not `mailto:` (`register.blade.php:70`).

### 17. Salon picker
`dashboard.blade.php`

- **[Medium] No single-salon fast path** — a one-salon user hits a one-card picker every login. Auto-redirect when `count === 1`.
- **[Low]** Off-scale `rounded-xl` on the agency card and empty state (`:52, :62`); `<dd>` without `<dt>` (`:113–136`); timezone as the lead card detail (`:114–117`) — city/phone scan better; inline `@php` query/badge logic (`:2–42`) violates the repo's own service-class convention.

### 18. Agency console
`resources/views/pages/agency/*`

- **[High] Deactivate salon has no confirmation** (`agency/salons/index.blade.php:84–86`; also `agency/salons/edit.blade.php:302–305`) — one click hides the salon from all its staff, adjacent to "Edit".
- **[Medium] Tables have no overflow handling** (`overview.blade.php:62–98`, `salons/index.blade.php:54–97`, `users/index.blade.php:43–84`).
- **[Medium] No agency-user lifecycle actions** — edit is name/role/scope only; no deactivate/remove, no admin password reset, email uneditable with no explanation (`agency/users/edit.blade.php`).
- **[Low]** Hand-rolled status pills in four places (`overview.blade.php:82–84`, `salons/index.blade.php:76–78`, `salons/edit.blade.php:241–243`); accent input is hex-only despite `AccentPalette` supporting preset names, no swatch/preview (`salons/create.blade.php:85`); raw 400-item timezone select (`:162–166`); empty states without CTAs (`salons/index.blade.php:92`); "Cancel" vs "Back" for the same action across create/edit; temp-password "Copy" gives no "Copied" feedback (`temp-password-panel.blade.php:15–17`); GHL token collected on create with no test feedback.

### 19. Email templates
`resources/views/mail/*`, `vendor/mail/html/themes/bookthestyle.css`

**Strengths:** custom branded mail theme wired via `config/mail.php:131`; markdown mailables give plain-text versions free.

- **[Medium] Temp-password email security copy is wrong** — "If you were not expecting this, you can ignore this email" (`temporary-password.blade.php:20`); an unexpected credential email should say "contact your administrator", and there's no expiry statement (if temp passwords never expire, that's a product question too).
- **[Low]** Text-only header, no logo mark; `salon-added.blade.php` missing the "not expecting this" line the other three have.

---

## Cross-cutting issues

1. **[Critical] No mobile experience.** Sidebar (`sidebar.blade.php:25–27`) + fixed tables on Today (`dashboard.blade.php:159`), Services (`services/index.blade.php:249`), Staff (`staff/index.blade.php:233`), Clients (`clients/index.blade.php:275`), and all three agency tables. Clients has a card fallback; nothing else does. One responsive sweep: off-canvas drawer + `overflow-x-auto` minimum everywhere + stacked cards on the highest-traffic screens.
2. **[High] Destructive/consequential actions never confirm.** Cancel/no-show (calendar `:386–392`, check-in `:60–67`, appointments `:256–262`), reschedule chip click (`booking-reschedule-modal.blade.php:29–34`), deactivate service (`services:284`), reset staff password (`staff:185`), remove time off (`availability:582`), deactivate salon (`agency/salons/index:84`), disable 2FA (`security:221`), regenerate recovery codes (`recovery-codes:89`). Several fire GHL fan-out. One `wire:confirm` sweep + verb-labeled buttons + action-specific toasts ("Marked as no-show", not "Booking updated.").
3. **[High] Contrast token failure.** `--color-faint #9C9890` ≈ 2.8:1 and `--color-fainter #A09C94` ≈ 2.5:1 on white, used for real content app-wide: empty states, helper text ("Choose a service and stylist to see open times", `create.blade.php:488`), `.bts-overline` (`app.css:304–311`), stat sublabels, "Booked by" lines, blocked-interval labels, cancelled pill text (`#9C9890` on `#F0EEEA` ≈ 2.5:1 — a DESIGN-TOKENS spec-level problem). Fix at the token: darken faint to ≈`#7A766E`, restrict fainter to decoration, darken the cancelled pill text to `#6B6862`.
4. **[Medium] Hand-rolled status/info pills with duplicated hexes in 10+ files** (services, staff, salon settings ×5, GHL card, agency ×3, walk-in pill ×4, client pills ×8) instead of `x-ui.status-pill` variants. Add `variant` props (`active|inactive|danger|info|warning|accent`) and sweep.
5. **[Medium] Zero `wire:loading` feedback anywhere** — date changes, filters, saves, and report recomputes give no signal; combined with no disabled-state on `x-ui.button`, double submits are invited.
6. **[Medium] Time-format inconsistency:** slot chips render 24h `H:i` (booking create `:190`, reschedule modal) while everything else is `g:i A`; hardcoded `am`/`pm` unlocalized.
7. **[Medium] Avatar identity inconsistency:** client avatars seeded by `stylist_id` on Today (`dashboard:173`) and Check-in (`index:188`) but `client->id` in the directory (`clients/index:294`) — same person, different color per screen.
8. **[Medium] Two component dialects:** Fortify-derived surfaces (2FA modal, recovery codes, delete account, passkey buttons) still ship starter Flux idioms (stone greys, `dark:`, `rounded-xl`, `flux:button` variants) against the warm `x-ui` system — visible seam when navigating salon settings → Security.
9. **[Medium] Default Livewire pagination views** on Clients (`:367`) and Appointments (`:280`) — the only "starter look" remnants in main app chrome.
10. **[Low] Page-width drift:** `max-w-6xl` (Today) vs `max-w-5xl` (Appointments) vs `max-w-[1400px]` (Calendar) — gutters visibly jump between adjacent nav items.
11. **[Low] Duplicated partial-sized blocks:** status-history modal pasted verbatim in calendar (`:397–409`) and appointments (`:288–305`); walk-in pill styles ×4.
12. **[Doc] CLAUDE.md/SPEC say Toast UI Calendar; the implementation is a custom grid** (`CalendarData.php`, no Toast UI in `package.json`). Reconcile the spec — it changes the cost of roadmap items like drag-to-reschedule.

---

## Accessibility findings (consolidated)

**Blocking (fix before launch):**
- 2FA recovery-code toggle unreachable by keyboard — lockout risk (`two-factor-challenge.blade.php:87–90`). **[High]**
- Contrast: faint/fainter tokens (~2.5–2.8:1) on real content app-wide; cancelled status pill ~2.5:1; blocked-interval calendar labels ~2.6:1; white avatar initials on light pastels (amber ~2.2:1, `avatar.blade.php:21–22`); `opacity-65` inactive rows dropping muted text ~3.1:1. **[High]**
- Collapsed sidebar: icon-only links with no accessible name (`sidebar.blade.php:54+`). **[High]**
- ~144 unlabeled empty-slot buttons flooding calendar tab order (`calendar.blade.php:265–266`). **[High]**

**Should fix:**
- No `:focus-visible` styles on any `.bts-*` interactive class (`app.css` styles focus only for `[data-flux-control]`, `:175–179`).
- No skip-to-content link; unlabeled `<nav>` landmarks (`sidebar.blade.php:49,150`).
- State conveyed by color/opacity only: stylist filter chips (`calendar:220–224`), Day/Week segments without `aria-pressed` (`calendar:197–200`), selected slot chips (`create:480`), settings tabs without `aria-current` (`settings:724`, `settings/layout:4–7`).
- Unlabeled form controls: clients search (`clients/index:241`), duration/buffer spinbuttons (`qualified-stylists:26–39`), icon-only copy/delete buttons (2FA manual key, passkey delete).
- Focus management: availability drawer has no focus trap (`availability:644`); prefs form swap doesn't move focus (`clients/show:281`).
- Error channels: booking per-line errors unrendered (`create:360`); 2FA OTP `@error('code')` missing (`two-factor-challenge:75–79`); GHL failure detail only in `title=` (`all:237`).
- Semantics: `<th>` without `scope="col"` on every table; `role="tab"` without tablist keyboard behavior (`availability:670–681`); `<dd>` without `<dt>` (`dashboard.blade.php:113`); welcome heading order h1→h3; raw ISO dates in aria-labels (`availability:919`); hardcoded `aria-expanded` (`recovery-codes:71,83`); entire 2FA page behind `x-cloak`.

---

## Prioritized fix list

### Must fix before launch — grouped into batchable prompts

**Batch 1 — Functional bugs (data integrity)**
1. Availability date-specific edit: validate replacement blocks before deleting the original; make the whole edit transactional (`availability/index.blade.php:475–481`, `AddTimeOff.php:46–50`).
2. Reschedule slots: compute the blocked window from the full multi-service visit span, in both copies (`appointments/all.blade.php:150–163`, `appointments/index.blade.php:125–138`).
3. Settings hash: whitelist the initial hash against visible tabs, fall back to `general`; add `hashchange` handling (`settings.blade.php:721–734`).
4. Booking form: render per-line `items.*.time` errors + scroll-to-first-error (`bookings/create.blade.php:360, 396–398`).
5. Fix the stale-client-selection pattern → combobox with selected-client chip (`create.blade.php:410–419`).
6. Align the Voice-AI card's gate with its tab's gate (`settings.blade.php:732, 1137`).

**Batch 2 — Mobile & responsive sweep**
1. Off-canvas sidebar drawer below `lg` with hamburger (reuse `bts-drawer`, `app.css:222–241`); tooltips/aria-labels for collapsed icon nav.
2. `overflow-x-auto` (+ `tabindex="0"` on the wrapper) on every fixed table: Today, Services, Staff, Clients, agency ×3.
3. Stacked-card fallbacks for Today and Check-in-adjacent tables (pattern already exists in `clients/index.blade.php:340–365`).
4. Standardize page max-widths (pick 6xl for lists; calendar may stay wide).

**Batch 3 — Confirmation & feedback sweep**
1. `wire:confirm` on: cancel, no-show, reschedule commit, deactivate service/staff/salon, reset password, remove time off, disable 2FA, regenerate recovery codes.
2. Reschedule modal: select-then-confirm instead of click-commits (`booking-reschedule-modal.blade.php:29–34`).
3. Verb-labeled action buttons ("Cancel booking", "Mark no-show", "Check in") instead of state names (`appointments/index.blade.php:223`).
4. Action-specific toast copy; `disabled`/loading states baked into `x-ui.button` + `wire:loading` on grids, lists, and report recomputes.

**Batch 4 — Accessibility & contrast**
1. Darken `--color-faint` to ≈`#7A766E`; restrict `fainter` to decoration; cancelled pill text → `#6B6862`; avatar initials → family ink color; drop the `opacity-65` row pattern.
2. Make the 2FA method toggle real buttons (`two-factor-challenge.blade.php:87–90`); add `@error('code')` output.
3. Shared `:focus-visible` ring for all `.bts-*` interactives; skip link; labeled nav landmarks.
4. `aria-label` empty calendar slots; `aria-pressed` on chips/segments/slots; `aria-current` on settings tabs; label the clients search and duration/buffer inputs; `scope="col"` sweep.

**Batch 5 — Calendar completeness**
1. Week view: overlap lanes or per-day summary + drill-down; clickable week headers → day view.
2. Current-time indicator (emit `nowMin` from `CalendarData::frame()`).
3. Date-jump picker on the range label; `wire:poll.5s` → `.30s`; add `wire:poll.30s` to Check-in.
4. Reschedule + client-profile link inside the calendar booking modal.

**Batch 6 — Core workflow gaps**
1. "New booking" CTA on the client profile + `?client=` prefill in `mount()` (`create.blade.php:58–67`, `clients/show.blade.php:183`).
2. `tel:`/`mailto:` links on all client contact fields (directory, profile, check-in cards).
3. Split client profile visits into Upcoming (actionable) vs Past.
4. 12-hour slot chips per salon locale everywhere; `min` on booking date input; show `start – end` on check-in cards.
5. Fix "Today" copy when viewing another date (`dashboard.blade.php:105,120,152`); prev/next day arrows.
6. Replace the register-page embed placeholder with config-driven iframe or a real fallback (`register.blade.php:59–66`).

**Batch 7 — Design-system consolidation**
1. Restyle the four Fortify surfaces (2FA modal, recovery codes, delete account, passkey buttons) onto `x-ui.*`/tokens; remove `dark:`/stone classes.
2. `x-ui.status-pill` variants; sweep the 10+ hand-rolled pill sites; tokenize inline hexes (`text-danger`, `bg-muted`, `bg-sidebar-dark`).
3. Publish/override Livewire pagination views with `bts-*` styling.
4. Input height 48px (or amend the spec); delete the flux-pro `@source` line (`app.css:16`); `<title>` fallback → "BookTheStyle".
5. Show recovery codes immediately after enabling 2FA (fix the unreachable `setupComplete` state); confirm on disable/regenerate.

**Batch 8 — Settings & safety copy**
1. Availability: warn when hours/time-off changes strand existing bookings (count + confirm); unify week-start order; fix the dates-tab save/cancel contract; rename "Submit".
2. Explain roles/staff types on the invite form; rename "Send invite"; add invited-not-signed-in state.
3. Explain service deactivate consequences; make "None assigned" a visible warning; add service color name + picker.
4. Delete-account consequence copy for owners; temp-password email security line; webhook secret masking + copy buttons; copy button on the one-time API token.
5. Timezone selects → grouped/filterable (salon settings + agency create).

### Nice to have (post-launch)

- Drag-to-reschedule / drag-to-extend on the calendar (note: custom grid means hand-building — factor the Toast UI spec decision first).
- Service categories/menu grouping (ripples into the booking flow) and manual service ordering.
- Reports: previous-period deltas, occupancy %, CSV export, retention tile (competitor-standard set).
- Client quick-add duplicate detection; slot grouping (Morning/Afternoon/Evening); "rebook last visit" accelerator.
- Undo window (toast-level) for status changes; admin-level status revert.
- Single-salon auto-redirect on the picker; salon-picker card detail reorder.
- Session management ("log out other devices") in Security; password-rules hint text on reset/force screens.
- OG/meta tags + privacy/terms footer on the marketing page; mail logo header.
- Agency: user deactivate/remove + admin password reset; accent preset swatch picker (AccentPalette already supports names).
- Onboarding checklist (Vagaro-style getting-started panel) for new salons.
- Websocket/event-driven calendar updates replacing polling; slot computation caching in the booking form.
- Sticky stylist header row on tall calendar days; results counts above paginated lists; sortable table headers in Clients.

---

## Strengths worth keeping (do not regress)

- Exact token fidelity in `app.css` and the accent-swap architecture; the zinc→warm Flux remap.
- Slot-engine-driven booking with no typed times, back-to-back multi-service defaulting, and save-time re-validation under lock.
- Click-empty-slot-to-book with prefill; service-colored calendar blocks with stylist pastels reserved for people.
- Secret hygiene: write-only GHL token, show-once API token and ICS link ("treat this link like a password"), scopes disclosure, per-booking sync retry.
- Allergy banner on client profiles; temp-password one-time panel + forced first-login change; anti-IDOR checks on every calendar modal open.
- Sentence case and no-emoji held everywhere; `trans_choice` pluralization; empty states that distinguish "no results" from "none yet".
