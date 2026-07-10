# BookTheStyle — end-to-end audit report

_Read-only audit of `main` @ `a2d9147` (2026-07-11). Suite: 491 passed / 6 skipped; Pint clean; PHPStan (level-7 larastan) clean; CI green (quality + ci 8.4 required; ci 8.5 experimental). No code was changed for this audit._

---

## 1. Executive summary

BookTheStyle is a multi-tenant salon-scheduling platform: one agency (Bluejaypro) operates many salons on wildcard subdomains, each with services → qualifying stylists → app-managed availability → bookings that roll up to a master calendar, mirror bidirectionally to GoHighLevel (reminders, voice AI, chat widget), and fan out to staff personal calendars via ICS feeds. Scheduling only — no payments, no public self-registration.

The build is substantially **feature-complete against SPEC.md phases 0–6**. Tenancy, RBAC, the booking engine and status model, both calendar views, the availability system (recently rebuilt as cards → drawer with date-specific overrides), the full GHL integration (outbound push, inbound webhook with hardened echo-loop protection, source tagging, reconciliation, sync-error surfacing, and app→GHL availability mirroring), transactional email, and ICS feeds are implemented and covered by a healthy test suite. Code quality is consistently good: business logic in actions/services, server-side authorization everywhere, encrypted secrets, structured logging with no PII.

The gap to production is **Phase 7, not features**. The three most important outstanding items: (1) a real deployment foundation — Hostinger deploy script, wildcard DNS + SSL, the one-line cron, production `.env` with MySQL and a real mail transport; (2) a **live-GHL smoke test of the availability-schedules API and reconciliation events feed** — the only significant subsystem verified purely against GHL's published OpenAPI spec and mocked HTTP, never against a real location from this codebase (the webhook inbound path, by contrast, *was* debugged against real payloads); and (3) the SPEC's audit log, backups, and a security/config pass. Secondary: the suite runs on SQLite only (prod is MySQL — one full-suite run against MySQL is cheap insurance), and `STATUS-and-ROADMAP.md` is badly stale (it still lists Phase 6 as not started).

---

## 2. Feature inventory

### Tenancy & platform

| Feature | Status | Notes |
|---|---|---|
| Multi-tenancy (agency → salons) | ✅ | `agencies`/`salons`/`salon_memberships`; `BelongsToSalon` trait + `SalonScope` global scope (deliberately a no-op in queue/console — jobs scope explicitly, verified in code). |
| Subdomain routing | ✅ | Four-way host split (apex marketing / `app.` / `register.` / `{slug}.`); `ResolveSalon` middleware enforces active + membership; reserved-slug blocklist; `lvh.me` local parity; sessions shared via parent-domain cookie. |
| Tenant isolation | ✅ | Enforced server-side on every query reviewed; extensive anti-IDOR tests (forged ids across salons 403/404 in bookings, availability, staff, time off, sync retries, webhooks). Calendar Livewire polling re-scopes explicitly since middleware doesn't run on Livewire updates. |
| Agency console | ✅ | Overview, salon CRUD (full business profile, subdomain slug w/ uniqueness + reserved-list, policies, accent, optional GHL fields), deactivate toggle, agency users CRUD with per-salon scoping; all actions authorize against the actor's own agency. |

### Auth & people

| Feature | Status | Notes |
|---|---|---|
| Auth (Fortify) | ✅ | Login pinned to `app.` host; 2FA + passkeys enabled; public registration disabled by design (tests assert it); login/2FA/passkey rate limiters. Email verification feature off (accounts are provisioned pre-verified) — the 6 skipped tests are these Fortify feature gates. |
| Forced password change | ✅ | Temp password on provisioning, `must_change_password` + `EnsurePasswordChanged` middleware, dedicated change screen, cleared on update. |
| RBAC | ✅ | Agency roles (owner/admin/user w/ salon assignment) × salon roles (owner/admin/user) × staff types (stylist/front-desk/manager) as PHP enums; `SalonPolicy` gates (`manage`, `manageBookings`, `accessBookings`, `manageGhlConnection`, `viewMasterCalendar`, …); anti-escalation on invites (can't grant a role you can't hold authority over). Matches the SPEC matrix; the "Manager" third staff type from the parked item is built. |
| Staff management | ✅ | Invite (new user w/ temp password, or attach existing user), role/type editing, admin password reset, active toggle, bio on profile. |

### Salon operations

| Feature | Status | Notes |
|---|---|---|
| Salon settings | ✅ | Five-panel page (general/policy/features/branding/integrations); editable timezone; accent presets + hex; feature flags; owner/admin gated. |
| Services | ✅ | CRUD, duration, active toggle, auto-assigned distinct palette colors (12-color hue-spaced `ServicePalette`, never reshuffled), stylist assignment at create + edit. |
| Per-stylist duration/buffer overrides | ✅ / 🟡 | Full UI on the services page via `service_stylist.duration_override`/`buffer_override` + `DurationResolver`. **Buffers are dormant** behind the `stylist_buffers` salon feature flag by design — engine treats buffer as 0 until a salon opts in. |
| Availability | ✅ | Rebuilt July 2026: staff-card grid (own card first, derived summary lines) → right-docked drawer (teleported, a11y) with Weekly hours / Date-specific hours tabs; read view for every salon member, editing gated by `AvailabilityAccess` (owner/admin anyone, stylist self only); compact per-day editor rows w/ split shifts + copy-times popover; date-specific calendar modal (multi-date, available-hours overrides or full-day off). `time_off.kind` distinguishes off vs hours-override; SlotEngine treats hours entries as that date's schedule. |
| Slot engine | ✅ | Pure interval engine: weekly windows − breaks − time off − existing bookings (+ cleanup buffers), date-hours overrides replace the weekly day, 15-min grid, DST-safe wall-clock math, booking-policy gate, `ignoreBookingId` for reschedules. Well tested incl. a regression tying grid input → bookable slots. |
| Booking creation | ✅ | Multi-service lines, explicit stylist per line (the SPEC's "any available" was **removed by owner decision**), real slot pills cross-filtered against in-form picks, walk-in mode (policy-gated, books "now"), client attach/quick-create, authoritative re-validation under a concurrency lock in `CreateBooking`. One booking **per service line**, composed visits linked by `visit_group_id`. |
| Status model | ✅ | Booked → Checked in (arrived) / No-show / Cancelled; Completed is automatic only (`bookings:close-elapsed` every 5 min: elapsed booked→no-show, elapsed checked-in→completed); auto-confirm on create (GHL `confirmed`). `Confirmed`/`InService` retained as documented legacy enum cases for historical rows — no app path writes them. Status history timeline w/ system notes. |
| Check-in tab | ✅ | Today-only queue, status actions, gated `manageBookings` (owner/admin/front desk). |
| Appointments tab | ✅ | Full searchable list (name/phone, date range, status), stylists see own only, status actions + reschedule on both tabs, GHL sync-failed pill for managers. |
| Reschedule | ✅ | Modal on both tabs + calendar detail; SlotEngine-validated; shifts all items; notes the change in history; GHL update (not duplicate). |
| Calendar | ✅ | Custom-built column calendar (not Toast UI — SPEC explicitly allowed a custom fallback; there is **no** Toast UI dependency in `package.json`): master day view (column per stylist, filter pills) + week view; personal view for stylists; service-colored blocks, breaks/time-off hatching, buffer tails, click-empty-slot → prefilled create, 5s Livewire polling, full detail modal with transitions/history. |
| Dashboard | ✅ | Today stats (total/waiting/completed/no-shows, per-stylist spread), filterable today table, role-scoped. |
| Clients | ✅ | Single page: search, inline create, modal edit, tenant-scoped. `ghl_contact_id` linkage is populated/used by the sync engine but intentionally invisible in this UI. |

### GHL integration (Phase 6, all sub-phases shipped)

| Feature | Status | Notes |
|---|---|---|
| Connection (PIT) | ✅ | Per-salon location id + PIT + master calendar id; token **encrypted at rest**, write-only in the UI (never rendered back), test-connection + disconnect; owner/admin only. |
| Two-tier staff mapping | ✅ | Stylists → calendar team members (`stylist_profiles.ghl_user_id`, routes bookings); other staff → location users (identity only); email auto-match; forged/cross-tier ids rejected. |
| Outbound push (6b) | ✅ | Queued `SyncBookingToGhl` (database queue, drained by scheduler): contact upsert, 1:1 booking↔appointment, payload-hash diff (unchanged = no API call), cancel keeps a cancelled record, per-booking sync status. Client throttled 90/10s per location with 429/5xx retries. |
| Inbound webhook (6c) | ✅ | `POST /webhooks/ghl`: salon by locationId, per-salon shared secret via `hash_equals`, replay dedupe (successful twins within 1h), CSRF-exempt, IP rate-limited, 202 + queued processing. Parser hardened against **real** payload shapes (GHL's misspelled `appoinmentStatus`, `calendar.id` vs `calendar.appointmentId`, offset-less times in `selectedTimezone`). |
| Echo-loop protection | ✅ | The #1 correctness risk per SPEC §7.4, and it took real-payload debugging to get right: state-equality echo check, last-change-wins on timestamped events, `ghl_last_pushed_status` recording on every push, timestamp-less echo/stale gates (equal-to-last-pushed → echo; lifecycle regression → stale), structured per-event decision log, `ghl:repair-sync-state` command. The full check-in loop is pinned by tests (exactly one outbound call, no flip-back). |
| GHL-originated bookings | ✅ | Voice/chat/manual appointments become app bookings: reverse provider mapping, contact resolve/create, best-effort service match with an inactive "Imported from GoHighLevel" fallback, unplaceable events flagged for review (never dropped). |
| Source tagging (6d) | ✅ | `bookings.source` (in_app / voice_ai / chat_widget / ghl_manual / ghl_other) derived from customData → contact tags → created/updated-by metadata; shown throughout the UI. Voice/chat detection depends on the GHL workflow passing `customData.source` or tagging contacts — configure the template accordingly. |
| Reconciliation (6d) | ✅ | `ghl:reconcile {salon?} {--days=7}` hourly: pulls calendar events, replays drift through the same inbound pipeline, imports unknowns (w/ contact enrichment + source), flags vanished appointments; idempotent, pre-filters matches to keep runs cheap. |
| Sync-error surfacing (6d) | ✅ | Per-booking synced/pending/failed + reason + last attempt; owner/admin "Sync issues" panel with retry; "Sync failed" pills on rows/detail; availability sync has the same per-stylist panel. |
| Availability sync app→GHL (6e) | 🟡 | Implemented and unit/payload-tested end-to-end: per-stylist GHL **user availability schedules** (weekly `wday` rules + `date` overrides incl. the new available-hours kind, salon TZ, conservative never-over-offer mapping), calendar slot settings (max duration / 15-min interval / flag-gated buffer), change-triggered + manual sync, hash idempotency, adopt-don't-duplicate, per-stylist status + retry. **Yellow only because the `/calendars/schedules` API family has been verified against GHL's published OpenAPI spec + marketplace docs, not against a live location from this app** — one real-world smoke test is a must before relying on it (see §8). |

### Communications & feeds

| Feature | Status | Notes |
|---|---|---|
| Transactional email | ✅ / 🟡 | Five branded queued markdown mailables (account created, temporary password, password reset, staff invite, salon added) wired to their triggers; violet theme + auto plain-text; fail-safe `rescue()` so a dead transport never blocks provisioning (temp password still shown in-app); nothing secret logged. 🟡: **production transport is unconfigured** (`MAIL_MAILER=log`) — Phase 7. Note: this deliberately supersedes SPEC §5.1's "resets via GHL" — login-critical mail is app-direct by later owner decision. |
| ICS personal feeds | ✅ | `/cal/{token}.ics`: 32-byte token, **hash-only storage**, show-once, rotate/revoke, rate-limited, ETag/304, no-validity-leak 404; per-stylist bookings across salons (−7d/+183d), cancelled marked; hand-rolled RFC 5545 writer; connect page with per-platform instructions. One-way by design (SPEC's open question resolved de facto). Master feed for owner/front-desk from SPEC §6 is **not** built — feeds are per-stylist-own only. |
| Help-docs system | 🟡 | Solid framework (registry, trigger pill, video modal w/ graceful "coming soon"), but exactly **one** registered topic (calendar-sync), used on one page, and **no video files shipped** (media dir is git-ignored scaffolding). Content work outstanding. |
| Public pages | 🟡 | Marketing landing + `register.` book-a-call page exist; the register page's GHL booking-widget embed is a literal `XXXX` placeholder awaiting the real embed code (CSP frame-src already configurable). No public booking anywhere — intentional. |

---

## 3. Incomplete / TODO / stubbed

The codebase is remarkably clean of markers — one real TODO, a few dormant-by-design items:

1. **`app/Actions/Staff/ResetStaffPassword.php:21`** — `TODO: GHL-routed reset (Phase 6)…`. **Stale**: the later decision was app-direct transactional mail (a2d9147), so this TODO should be deleted/reworded, not implemented.
2. **`resources/views/register.blade.php:50–52`** — GHL booking widget src/id are `XXXX` placeholders. Needs the real embed code (tracked in STATUS-and-ROADMAP "smaller open items").
3. **Buffers feature flag** (`stylist_buffers`, `config/salon_features.php`) — dormant by design; UI + engine + GHL slot-buffer mapping all activate when a salon enables it. Nothing to build; just a launch decision per salon.
4. **Help-docs content** — framework built, one topic registered, zero videos on disk (`public/how-to-documentation/` has only `.gitkeep`/README).
5. **Audit log (SPEC §4/§5.11)** — not built at all; no `AuditLog` model. Partial substitutes exist (`booking_status_events` with actor + system notes; `webhook_events` with full inbound audit trail), but settings/staff/login changes have no trail. Phase 7 scope.
6. **Master ICS feed** for owner/front-desk (SPEC §6) — only per-stylist own-bookings feeds exist.
7. **No FIXME/HACK/not-implemented markers anywhere.** No stubbed methods found.

---

## 4. Dead / unnecessary code & files (recommend — nothing removed)

Safe-to-remove candidates:

1. **`App\Support\Permissions\AvailabilityAccess::canManageAny()`** — zero callers since the availability page opened to all members (sidebar no longer uses it either). Remove method.
2. **`isManager()` computed** in `resources/views/pages/salon/availability/index.blade.php` — not referenced by the markup anymore; only one test asserts it. Remove + drop the assertion.
3. **`updatedSelectedStylistId()`** in the same file — the wire-model picker it served was removed; unreachable in production (only tests `->set()` it). Remove + migrate the handful of tests to `openPanel()`.
4. **Commented-out `git-auto-commit-action` step** in `.github/workflows/lint.yml:44–48` — delete (or keep deliberately; it's the only commented-out block in the repo).
5. **`time_off.type` column + `TimeOffType::Vacation/Sick`** — vestigial since the modal stopped collecting type (always `'blocked'` now); still rendered in the read view for old rows. Either drop column + enum cases (small migration) or restore a type picker — currently it's a label that will always read "Blocked" for new entries.
6. **`booking_ghl_appointments`** — transient table created by migration 000003 and dropped by 000005 (data folded into booking columns); no model, no runtime use. Keep the migrations as history; nothing to do.
7. **Stale docs**: `STATUS-and-ROADMAP.md` predates all of Phase 6 (lists it as "remaining"); `.env.example:124` still says tokens live on `salons.ghl_token` (they moved to `salon_ghl_connections`). Update both.
8. **Diagnostic logging: none left over.** All six `Log::` calls in the GHL services are intentional structured logs (ids + statuses only — the inbound decision log is a deliberate debugging instrument). Nothing logs tokens, emails, phone numbers, or payload bodies. `app/Http` has zero log calls. Raw inbound payloads are stored in `webhook_events.payload` (DB, tenant-scoped) — intentional per SPEC, but note it contains contact PII; a retention/pruning policy is worth adding in Phase 7.
9. **Blade components**: all in use; none orphaned. Toast UI appears only as a stale local `node_modules/.vite` cache artifact — **not** in `package.json`; nothing to remove from the repo.

---

## 5. Tests & CI

- **491 passed / 6 skipped / 0 failed** across 64 files (~1,979 assertions, ~40s locally). Pint clean; PHPStan level-7 (larastan) clean via `composer types:check`.
- **The 6 skips are intentional** Fortify feature gates (registration disabled by design; email verification off) — not rot.
- **Strengths**: tenant isolation (dedicated tests in nearly every suite), the whole GHL matrix (79+ tests: push, webhook parsing against real payload shapes, echo loop, reconcile, availability rules incl. DST fall-back and conservative rounding, client-surface drift guard), slot engine, RBAC, auth e2e (reset/2FA/passkeys/forced change), transactional mail incl. mail-down lockout safety, security headers, host-split routing, seeder.
- **Coverage gaps (candid)**:
  - **Live GHL behavior**: everything HTTP is `Http::fake()`. The **inbound** side earned real-payload hardening during debugging; the **outbound schedules/availability API (6e)** and the **reconcile events feed** have never been exercised against a real location from this code. Spec-verified ≠ live-verified (GHL is notoriously drifty).
  - **SQLite-only**: CI and local tests run SQLite; production targets MySQL. Migrations use some SQLite-conscious patterns, but the suite has never run on MySQL.
  - **No browser tests**: Alpine-heavy behaviors (drawer focus trap, teleport, copy popover, calendar drag targets) are asserted via rendered HTML, not a real browser.
  - **ICS feed content** has tests; the calendar *page's* visual math (positioning) is effectively untested beyond data assembly.
- **Flakiness**: one CI incident (2026-07-10) where the failing matrix job hopped 8.4 → 8.5 across an identical re-run and never reproduced locally (3 random-order runs) — environmental. Date-brittleness is well-managed (frozen clocks; the one date-sensitive suite computes future Mondays; the migration-rollback depth test is self-adjusting).
- **CI shape**: `tests.yml` (8.4 required; **8.5 is `continue-on-error`** forward-signal) + `lint.yml` (Pint). PHPStan runs inside the tests job. Note: `gh` CLI is no longer on the dev machine; CI status is checked via the public GitHub REST API.

---

## 6. Data model & migrations

- **41 migrations; `migrate:fresh` runs clean** (verified this audit on in-memory SQLite, 0001…→2026_07_17). All reversible; the one hairy pair (one-booking-per-stylist → split-per-service) carries data backfills both ways.
- **Schema (current)**: `agencies`, `salons` (+slug, business profile, branding, policy, feature_flags), `users` (+tenancy cols, 2FA, must_change_password), `salon_memberships` (role, staff_type, ghl_location_user_id), `agency_salon_assignments`, `services` (+color_key), `service_stylist` (+salon_id, duration/buffer overrides), `stylist_profiles` (bio, ghl_user_id, ghl_schedule_id + availability-sync bookkeeping), `availabilities` (weekly work/break), `time_off` (+kind: off|hours), `clients` (+ghl_contact_id), `bookings` (status, source, booked_by, visit_group_id, walk-in, full GHL sync-state column family), `booking_items` (service, stylist, times, buffer_min), `booking_status_events`, `salon_ghl_connections` (encrypted PIT + webhook secret, calendar id, verification), `calendar_connections` (ICS token hash), `webhook_events`, plus framework tables (jobs, cache, passkeys).
- **Additive-column audit**: everything added in Phase 6 is read somewhere (verified) — `ghl_last_pushed_status`, `ghl_last_attempt_at`, `ghl_payload_hash`, availability-sync columns, `time_off.kind`, `source`. The only vestige is **`time_off.type`** (see §4.5).
- **Backfill safety**: consistently good — new columns nullable or defaulted to the historical meaning (`kind='off'`, `source='in_app'`), self-correcting sync columns, repair command for tracking drift.
- **Hygiene watch-items**: the transient `booking_ghl_appointments` create/drop pair is fine as history; SQLite-specific quirks were handled (dropIndex before dropColumn), but the **schema has never been built on MySQL** — run migrations + suite against MySQL before launch.

---

## 7. Security & production-readiness

**Solid today:**
- GHL PIT **and** webhook secret `encrypted` casts at rest; token write-only in UI; `#[SensitiveParameter]` on the client; no secret ever logged (verified by grep + tests asserting the token never renders).
- Webhook: per-salon shared secret compared with `hash_equals`, schema-tolerant parsing, replay dedupe, per-IP throttle (120/min), 401 uniform for unknown location/secret.
- Tenant isolation: no holes found in this audit; global scope + explicit scoping off-request + policy checks + widespread anti-IDOR tests.
- Headers: CSP everywhere, frame-src relaxed only on `register.`; session cookies parent-domain scoped, HttpOnly/SameSite; CSRF exempt only for `webhooks/*`.
- Rate limits: login, 2FA, passkeys, calendar feed (60/min/IP), webhook (120/min/IP). GHL client self-throttles 90/10s per location.
- ICS: capability tokens hashed at rest, show-once, rotate/revoke, no enumeration.
- Temp passwords: cryptographically random, forced change, appear only in email body + one-time UI.

**Gaps for Phase 7 (must-do):**
1. **Deploy foundation**: deploy script (`composer install --no-dev`, `migrate --force`, config/route/view cache, `storage:link`), hPanel Git, wildcard DNS `*.bookthestyle.com` + `app`/`register`, **wildcard TLS**, prod `.env` (`APP_DEBUG=false`, `APP_DOMAIN`, `SESSION_DOMAIN=.bookthestyle.com`, MySQL, secure cookies), the single crontab line `* * * * * php artisan schedule:run` (drives queue, close-elapsed, hourly reconcile).
2. **Real mail transport** (`MAIL_*`) + SPF/DKIM — temp passwords and resets are login-critical; currently they'd go to the log.
3. **Backups** — scheduled `mysqldump` cron; nothing exists.
4. **Audit log** — SPEC §5.11 unbuilt (see §3.5); decide build-now vs consciously defer.
5. **HSTS** — CSP is in place; confirm HSTS once TLS terminates properly.
6. **webhook_events retention/pruning** (contains contact PII in raw payloads) and `failed_jobs` monitoring.

**On the X-GHL-Signature / X-WH-Signature migration (July 2026)**: this applies to **GHL Marketplace-app webhooks**, which BookTheStyle does not use. Our inbound is a **workflow custom-webhook action** where *we* define the auth: the per-salon `X-Webhook-Secret` header GHL echoes back. The deprecation therefore doesn't break us — but it's worth a one-time check during the live smoke test that workflow webhook deliveries still carry custom headers unchanged, and the shared-secret approach remains weaker than a signed HMAC; if GHL ever exposes payload signing for workflow webhooks, adopt it.

---

## 8. Known issues / risks

1. **6e availability API is spec-verified, not live-verified.** The `/calendars/schedules` family matched GHL's published OpenAPI + marketplace docs at build time, but no request from this app has hit a real location. Response-shape drift (e.g. the schedule id key) is defensively handled and errors surface with retry, but treat the first live sync as a smoke test, not a formality. Same for `GET /calendars/events` (reconcile) — including whether it paginates on large windows (spec shows no pagination; the code assumes none).
2. **Availability is one-way app→GHL by decision.** Any availability edited directly inside GHL will be overwritten on the next push (hash-triggered). Salons must treat the app as the only place to edit hours — worth a line in onboarding docs.
3. **Conservative GHL mapping under-offers by design**: slot duration = the longest active service (short services show fewer GHL start times); windows ending 24:00 become 23:59; per-stylist-per-service durations can't map (GHL slot settings are calendar-level). Documented, intentional, but salon owners may ask why GHL shows fewer slots than the app.
4. **Timestamp-less GHL workflow webhooks** forced heuristic echo/stale gates (equal-to-last-pushed, lifecycle regression). They're well-tested, but they are heuristics; a genuinely instant GHL-side revert to "confirmed" right after a check-in would be classified stale. Acceptable trade-off; the decision log makes disputes diagnosable.
5. **Queue realities**: production drains via the every-minute scheduler (`queue:work --stop-when-empty` — fresh process each run, so deploys are picked up). **Locally**, `composer dev`'s `queue:listen` keeps code in memory — after pulling changes the worker must be restarted or jobs run stale code (this caused the "undefined method" confusion during 6e; a partial/stale deploy shows the same signature).
6. **Editing a legacy partial-day time-off entry** in the new modal prefills as "unavailable all day" (lossy) — only affects pre-`kind` rows that weren't full-day; new entries round-trip correctly.
7. **`bookings:close-elapsed` auto-no-shows anything still "Booked" after end time** — front desk must check people in or completed visits will be marked no-show after the fact. Behavioral, by design, worth training.
8. **`register.` page ships with a dead `XXXX` embed** — harmless but publicly visible if DNS goes live before the embed code is pasted.
9. **Sync-issue retry on a vanished GHL appointment** re-pushes to the stored id and will fail again if GHL truly deleted it (flag is informational; clearing the id for re-create is a possible follow-up).
10. **Docs rot**: `STATUS-and-ROADMAP.md` and one `.env.example` comment materially misdescribe the current system (see §4.7) — a future audit or new contributor would be misled.

---

## 9. Recommended pre-launch punch list

**Must do before launch (Phase 7 core):**
1. Deploy foundation: deploy script + hPanel Git, wildcard DNS + wildcard SSL, production `.env` (MySQL, `APP_DEBUG=false`, domains/cookies), crontab line, `storage:link`, config caching.
2. Run the **full migration set + test suite against MySQL** once; fix any dialect issues.
3. Configure a **real mail transport** + SPF/DKIM; send-and-receive test of all five transactional emails.
4. **Live GHL smoke test** on a real (template) location: connect → map staff → sync availability (verify schedules in GHL UI) → create/reschedule/cancel a booking both directions → check-in echo test → run `ghl:reconcile` → confirm voice/chat widget offers only valid slots and that a widget booking arrives with the right source. Paste the register-page embed code while in there.
5. **Backups**: nightly `mysqldump` cron + restore drill.
6. Security pass: HSTS, confirm rate limits in prod, `webhook_events`/`failed_jobs` retention, review the shared-secret webhook posture (item §7).
7. Decide the **audit log**: build the SPEC §5.11 table (recommended — small: one model + writes from ~8 actions) or explicitly de-scope for v1.
8. Update `STATUS-and-ROADMAP.md` + `.env.example` comment to reflect reality.

**Should do (cheap, high value):**
9. Dead-code cleanup from §4 (items 1–5) + delete/reword the stale GHL-reset TODO.
10. Onboarding notes for salons: availability is edited in-app only; check-in discipline (auto-no-show); GHL under-offering rationale.
11. Add a MySQL matrix job (or a scheduled MySQL run) to CI.

**Nice to have (post-launch):**
12. Master ICS feed for owner/front-desk (SPEC §6 gap); help-doc videos + more topics; `time_off.type` simplification; browser-level tests for the drawer/calendar; pagination guard for the reconcile events feed; per-salon buffer flag rollout.
