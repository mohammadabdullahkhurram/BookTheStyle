# BookTheStyle — pre-launch walkthrough

**Temporary review document — delete after launch.**

How to use this: walk the app top to bottom, tick each box as you verify it, and write anything wrong (or just "off") on the `> Notes:` line beneath the item — screenshots welcome, one-word notes fine. When you're done, hand the doc back; every note becomes a fix; then this file is deleted and we deploy.

Setup before you start:

- [ ] Run `php artisan migrate` (additive only) and `npm run build`; hard-refresh / use incognito throughout.
  > Notes:
- [ ] Seed data: `php artisan db:seed --class=DemoSalonSeeder` gives you Glamour Studio at `http://demo.lvh.me` (owner@demo.test / frontdesk@demo.test / maya@demo.test — all `password`). For the clean-slate path instead, `php artisan app:factory-reset` (abdullah@bluejaypro.com / password) — you can do the clean-slate items at the end so you keep the demo data for the bulk of the walkthrough.
  > Notes:

---

## 1. Salon app — auth (Brand/landing palette, NOT Marble)

- [ ] Login page (`app.lvh.me/login`): white ground, plum accents, the landing-card panel — matches the marketing site, not the salon app theme.
  > Notes:
- [ ] Log in as owner@demo.test — lands on the salon picker / straight into the salon; wrong password shows a clear error; rate limiting kicks in after repeated failures.
  > Notes:
- [ ] Log out from the salon subdomain — returns to login cleanly (cross-subdomain logout works).
  > Notes:
- [ ] Forgot password (`/forgot-password`): request a reset for a real account, receive the email (check `laravel.log` if no local transport), and the reset form works end to end.
  > Notes:
- [ ] Staff invite accept: invite a new staff member from Staff, log in with the printed temp password, get FORCED to change it before reaching anything else, and the new password sticks.
  > Notes:
- [ ] 2FA: enable it in account settings (QR + confirm), log out, log back in — the challenge appears; a recovery code also passes the challenge; regenerating recovery codes invalidates old ones.
  > Notes:
- [ ] Passkeys: register one from Security settings and log in with it.
  > Notes:

## 2. Today / dashboard (Marble)

- [ ] The whole salon app renders Marble: butter-cream paper, coral accents, chunky rounded tiles with the pressed-clay edge — cohesive on every screen you visit below.
  > Notes:
- [ ] Today stats are right for the seeded day (booked / arrived / completed / no-shows) and match the list below them.
  > Notes:
- [ ] Today's booking list: correct times, stylists, status pills; the source ("Booking widget", "Voice AI", staff name) reads correctly per row.
  > Notes:
- [ ] Stylist login (maya@demo.test) sees her own day, not the whole salon's management view.
  > Notes:
- [ ] Empty state: a day with no bookings reads as a designed empty state, not a blank hole.
  > Notes:

## 3. Calendar (Marble)

- [ ] Day view: one column per stylist, service-colored blocks, breaks/time-off hatched, buffer tails visible; current-time indicator sensible.
  > Notes:
- [ ] Concurrent bookings (two stylists, same time) render side by side without overlap glitches; the demo data includes same-time bookings.
  > Notes:
- [ ] Week view: layout holds, overlapping blocks lane correctly (no hidden bookings), navigation between weeks works.
  > Notes:
- [ ] Click an empty slot → the create-booking form opens prefilled with that stylist + time.
  > Notes:
- [ ] Click a booking → detail modal: items, client, status history timeline, reschedule + status actions present.
  > Notes:
- [ ] Stylist filter pills narrow the day view; a stylist account sees the personal calendar variant.
  > Notes:
- [ ] Live polling: create a booking in a second window — it appears within ~5s without a manual refresh.
  > Notes:

## 4. Appointments + check-in (Marble)

- [ ] Appointments list: search by client name and phone, date-range filter, status filter — all combine correctly.
  > Notes:
- [ ] Status actions use the right VERBS and each destructive/terminal action asks for confirmation with specific copy (cancel, no-show).
  > Notes:
- [ ] Reschedule: select-then-confirm flow, offered times are genuinely free (try to collide with an existing booking — it must not offer it), multi-service visits move together, and the history notes the change.
  > Notes:
- [ ] Check-in tab: today-only queue; arrived → in service → completed transitions with one tap each; toasts confirm each move.
  > Notes:
- [ ] A multi-service visit shows as its linked services (one visit), not as unrelated rows.
  > Notes:
- [ ] GHL sync pill: a manager sees a sync-failed indicator on a booking whose push failed (if any exist), with the retry panel in settings.
  > Notes:
- [ ] Stylists see ONLY their own appointments here; front desk sees the whole day.
  > Notes:

## 5. Clients (Marble)

- [ ] Directory: search (name/phone), sensible ordering, stats/summary correct against the seeded 18 clients.
  > Notes:
- [ ] Inline create + modal edit save correctly; validation errors are clear and next to the field.
  > Notes:
- [ ] Client profile: visit history lists their real bookings; preferences (preferred stylist, contact method, birthday) display; notes can be added and stick.
  > Notes:
- [ ] Allergy banner: a client with allergies (e.g. Alena Gomez, Megan Wu) shows the allergy prominently on their profile and wherever staff need to see it during booking.
  > Notes:
- [ ] Formula notes render on the profile for the seeded clients that have them.
  > Notes:

## 6. Services (Marble)

- [ ] List shows every service with duration, PRICE (formatted in the salon currency), color chip, active state, and its qualified stylists.
  > Notes:
- [ ] Create a service: name, duration, price, stylist assignment at create; it appears with a distinct auto palette color.
  > Notes:
- [ ] Edit: change price/duration/stylists; per-stylist duration override (Maya's 35-min cut is seeded) displays and is editable; deactivating removes it from booking flows without deleting history.
  > Notes:
- [ ] Buffers stay hidden unless the salon's `stylist_buffers` feature flag is on (Settings → Features); flag on → buffer override column appears.
  > Notes:

## 7. Staff (Marble)

- [ ] List shows role + staff type per member; invite flow prints a temp password panel; roles/types are editable; anti-escalation holds (a salon admin cannot mint an owner).
  > Notes:
- [ ] Deactivating a stylist removes them from booking/availability surfaces but keeps their history.
  > Notes:
- [ ] Stylist bio editing (their own profile) works.
  > Notes:

## 8. Availability (Marble)

- [ ] Staff card grid: own card first for stylists; summary lines match the seeded hours (Maya Mon–Fri 9–5 + lunch, Sofia Tue–Sat, Jonah Wed–Sun, Elise Mon–Thu).
  > Notes:
- [ ] Drawer: weekly hours tab — per-day rows, split shifts, the copy-times popover; saving persists and the calendar reflects it.
  > Notes:
- [ ] Date-specific tab: Jonah's seeded time off and Maya's short Saturday show; add a date-specific override and a full-day off; both appear hatched on the calendar and remove slots from the widget.
  > Notes:
- [ ] Permissions: a stylist edits only their own hours; front desk cannot edit; owner/admin edit anyone.
  > Notes:

## 9. Reports (Marble)

- [ ] Presets (week / month / 30 days / custom) change the numbers; totals, no-show rate, and estimated revenue look right against the demo data.
  > Notes:
- [ ] Source mix: voice AI / widget / in-app bars with the AI share called out — matches the seeded spread.
  > Notes:
- [ ] Staff activity and top services sections populate; the unpriced-services caveat shows when applicable.
  > Notes:
- [ ] Manager-only: a stylist login cannot open Reports.
  > Notes:

## 10. Settings (Marble) — every tab

- [ ] General: business profile fields save; timezone and currency changes stick (currency reflects in Services and Reports).
  > Notes:
- [ ] Booking policy: walk-ins, same-day, max advance days, min notice; auto-no-show toggle + grace minutes; auto-complete toggle — each saves and the copy reads clearly.
  > Notes:
- [ ] Features: flags toggle and persist (try `stylist_buffers`).
  > Notes:
- [ ] Branding: accent preset pills + custom hex; contrast warning appears for a mid-tone accent (try #7F7F7F) and for accent≈background; App theme picker shows Marble + Classic live and Velvet/Gazette/Fern locked "Coming soon"; picking Classic returns the ENTIRE old look, Marble comes back.
  > Notes:
- [ ] Integrations (GHL): PIT field is write-only (never echoes back), test connection reports honestly, master calendar + staff mapping load from GHL, webhook secret + URL display, disconnect works. (Full sync behavior = section 15.)
  > Notes:
- [ ] Voice API token: generate shows the token once; regenerate invalidates the old one.
  > Notes:
- [ ] Onboarding wizard (`/setup` on a fresh salon): steps track real state, go-live summary lists what's missing, completing marks the salon live and the Setup nav item disappears.
  > Notes:
- [ ] My calendar (salon-side, every member): generate the private feed link, subscribe (or curl the .ics), see ONLY your own bookings; revoke kills the URL. Confirm it is gone from the app-host profile settings.
  > Notes:

## 11. Salon app — mobile, a11y, feel

- [ ] Phone width: the nav collapses to the top bar + drawer (opens, traps focus, closes on Esc/scrim) on Today, Calendar, Appointments, Clients, Settings.
  > Notes:
- [ ] Tables (appointments, clients, staff) scroll or stack on mobile — no horizontal page scroll anywhere.
  > Notes:
- [ ] Keyboard: tab through login and one form screen — focus ring visible everywhere (coral under Marble); skip-to-content link appears on first tab.
  > Notes:
- [ ] Text contrast feels comfortable under Marble on paper and cards (it is AA-computed; flag anything that reads faint to you anyway).
  > Notes:
- [ ] Every destructive action you meet (delete service, remove logo, cancel booking, delete widget…) confirms first, with specific copy.
  > Notes:

## 12. Agency console (landing colors, not Glacier)

- [ ] Log in as an agency owner — console renders in the landing palette (white + plum), matching the public site.
  > Notes:
- [ ] Nav order: Dashboard → Salons → Reporting → Users, with "+ New salon" pinned at the bottom as the primary action; "Agency console" wording is gone (it is "Dashboard").
  > Notes:
- [ ] Dashboard: salon count + user count cards, salon table with status pills, edit links.
  > Notes:
- [ ] Salons: create a salon end to end (profile, slug, policies) — it appears, is reachable at its subdomain, and slug collisions/reserved names are rejected.
  > Notes:
- [ ] Reporting: presets change the range; totals + no-show rate; revenue grouped PER CURRENCY (never mixed); agency-wide source mix; sortable per-salon table with "Most active" highlights; empty state on a dead range.
  > Notes:
- [ ] Users: both sections (agency operators + salon staff) with roles, salons, Invited/Active status; search, scope filter, role filter, salon filter all work.
  > Notes:
- [ ] Access: a salon owner or stylist hitting any `/agency` URL gets 403.
  > Notes:

## 13. Booking widgets

Widgets area (salon side):

- [ ] Widgets nav item opens the area; the seeded widget lists with its type label ("Booking widget").
  > Notes:
- [ ] "+ New widget" opens the TYPE picker: Booking selectable; Chat / Lead form / Reviews blurred + "Coming soon" and not clickable.
  > Notes:
- [ ] Create a second widget; give it its own name, accent/background/font, and a logo — save, preview: it renders ITS look while the first widget keeps the salon default.
  > Notes:
- [ ] Widget theme picker: Marble live + coming-soon locked cards.
  > Notes:
- [ ] Each widget shows its own embed snippets (script with `data-bookthestyle-widget`, plain iframe) with distinct public ids; deleting the second widget works (and the last one cannot be deleted).
  > Notes:

The widget itself (open the preview URL, and once embedded, on a real page):

- [ ] The branded split shell: logo + salon name + running visit summary on the left, steps on the right; solid branded background; readable text whatever the background color (try a dark background widget).
  > Notes:
- [ ] Per-service loop: pick a service → pick THAT service's stylist (or Any) → the inline calendar shows only days with real openings for that service+stylist (past days disabled, month prev/next bounded) → pick a day → real time chips → picking one ADDS the service.
  > Notes:
- [ ] After adding: "Add another service" loops; the left summary grows (service, its own stylist, its own time, running total); a service can be removed from the summary.
  > Notes:
- [ ] Independent times: book a morning haircut and an afternoon nails in ONE visit (gap between them) — allowed; the same stylist double-booked at overlapping times is NOT offered / refused at finalize naming the service.
  > Notes:
- [ ] Finalize → details (name/phone required, email optional) → confirmation lists every service with its stylist and time.
  > Notes:
- [ ] The bookings land in the salon (Appointments/Calendar) as one visit group, tagged "Booking widget", and queue a GHL push per service (visible in sync state once GHL is live).
  > Notes:
- [ ] Availability truth: fully book a stylist, confirm the widget stops offering those days/times; a date-specific day off removes its slots.
  > Notes:
- [ ] Embed test: paste the script snippet into a plain local HTML file — the iframe injects, auto-sizes as you move through steps, and books.
  > Notes:
- [ ] Widget on a phone: split stacks (summary on top), calendar full-width and tappable, chips comfortable.
  > Notes:

## 14. Bluejaypro website (public, landing palette)

- [ ] Home (`lvh.me`): hero, the three offerings (BookTheStyle / Loopflo / SEO), the calendar + dashboard + widget showcases (dashboard in the site's white/plum look), reviews widget, booking embed at `#book`.
  > Notes:
- [ ] Services: three sections with anchors (#bookthestyle / #loopflo / #seo); Loopflo presented as a PRODUCT ("Loopflo CRM", Product badge); "GoHighLevel" appears NOWHERE on the public site.
  > Notes:
- [ ] Features: icon-forward grid (icons + one-liners, not paragraphs); the large dashboard showcase; CTA.
  > Notes:
- [ ] Contact: details column (address, (916) 894-8575 only, hello@bluejaypro.com), the contact-form placeholder slot, and the booking calendar full-width beneath both.
  > Notes:
- [ ] Register page (`register.lvh.me`): the live booking calendar embed loads, wide.
  > Notes:
- [ ] GHL embeds actually render (booking calendar on Home/Contact/Register, Google reviews on Home) — no CSP blocks in the browser console. (Needs internet; on production also verify once deployed.)
  > Notes:
- [ ] Header uses the BookTheStyle logo; nav (Home/Services/Features/Contact + Book a call + Log in) works incl. the mobile disclosure menu; footer details correct on every page.
  > Notes:
- [ ] Responsive pass on all four pages at phone width.
  > Notes:

## 15. Voice-AI booking + live GHL (deploy-dependent — mark N/A until the app has a public URL)

- [ ] [needs deploy] GHL Custom Actions configured against the deployed URL with the salon's voice API token; the availability action returns real slots; the create action books (date + time slot shape).
  > Notes:
- [ ] [needs deploy] A voice/chat-created booking appears in the app tagged Voice AI / Chat widget, with client upsert.
  > Notes:
- [ ] [needs deploy] Live GHL smoke test (the Phase 7 gate): connect a real location → map staff → availability sync pushes schedules → book app→GHL (appointment + contact + reminder fire) → book GHL→app (webhook inbound) → check-in echo does NOT loop → reconcile repairs an induced drift.
  > Notes:
- [ ] Local-now: the API endpoints refuse a bad/expired token (401) and rate-limit; the webhook rejects a wrong secret.
  > Notes:

## 16. Cross-cutting

- [ ] Theme boundaries: salon app = the salon's selected theme (Marble default, Classic exact-old-look); login + salon picker + agency = landing palette; widgets = per-widget. Switch a salon to Classic and back — nothing else changes.
  > Notes:
- [ ] Tenant isolation spot-check: while logged into salon A, hand-edit a URL to salon B (`http://other-slug.lvh.me/...`) — 403/404, never data. Try a booking/client id from another salon in a URL.
  > Notes:
- [ ] Role matrix spot-check: stylist (own things only, no settings/reports/widgets), front desk (bookings + check-in, no settings), salon admin/owner (everything salon), agency roles (console per assignment).
  > Notes:
- [ ] Emails: trigger each mailable you can locally (account created, temp password, reset, staff invite, salon added) and eyeball the branded rendering (log transport is fine).
  > Notes:
- [ ] Factory reset (LAST, after you finish everything above — it wipes the demo data): `php artisan app:factory-reset` → only abdullah@bluejaypro.com remains; log in, console shows zero salons; create the first real salon end to end; re-seed the demo afterwards if you want it back.
  > Notes:

---

When you're done: hand this back with your notes. We fix everything captured, delete this file, and deploy.
