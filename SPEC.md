# BookTheStyle — Project Specification

> Canonical reference for the build. Lives in the repo root; feed it to Claude Code as context. The plan is intentionally extensible — new features and per-salon variations are expected over time, so the architecture is built to absorb them (multi-tenant, feature-flagged) rather than assume a fixed scope. Sections marked **[OPEN]** are the only unsettled items.

---

## 1. What BookTheStyle is

A multi-tenant booking platform for hair/beauty salons, operated by an **agency** (one agency account) that manages many **salons** (sub-accounts). Each salon runs its own bookings, stylists, services, and its own GoHighLevel (GHL) sub-account integration. The product is sold/deployed as a duplicatable template: a new salon = a new sub-account in BookTheStyle + a cloned GHL sub-account.

**Per salon, the model is:** salon → services → each service has qualifying stylists → each stylist has app-managed availability → all bookings roll up into one **master calendar** (owner/receptionist view) → every booking is mirrored to GHL (backbone for reminders, voice AI, and chat widget) and out to the relevant people's personal calendars.

BookTheStyle is the **system of record for app concerns** (who booked, check-in status, services on a visit, availability, audit, tenancy). GHL is the **calendar of record + reminders + the home of the voice AI and chat widget**. The two stay in sync.

---

## 2. Hard constraints

- **$0 in new spend.** Hostinger Premium/Business Web Hosting (already owned, runs PHP/MySQL), existing GHL, free GitHub. Every dependency must be free **and** commercial-use licensed.
- **No paid third-party services.** GHL handles all reminders/notifications. The app sends exactly **one** email type directly (temporary password); everything else routes through GHL.
- **No payments in scope.** Scheduling only. Any deposits/payments are handled by each salon directly in GHL/Stripe — out of BookTheStyle entirely.
- **Multi-tenant from day one.** Strict tenant isolation (no salon can ever see another salon's data).
- **Hardened against intrusion** (Section 9). Storing GHL tokens + personal-calendar feeds means security is first-class.
- **Code on the user's GitHub**, repo already cloned. Develop locally, push to GitHub, deploy to Hostinger at milestones.
- **Claude Code:** filesystem access + can web-search from the terminal (with the user's approval) — so it *can* look up GHL API specs itself. It **cannot** control the laptop or deploy on its own; the user approves actions and triggers deploys.
- **Light mode, modern, not "AI slop."** Enforced by a design-token system (Section 11).

---

## 3. Tenancy, roles & permissions

Two scopes, mirroring GHL's structure.

**Agency scope** (the operator — you):
- **Agency Owner** — full control of the agency and every sub-account.
- **Agency Admin** — manage sub-accounts, users, settings; near-full.
- **Agency User** — access limited to assigned sub-accounts.

**Sub-account (Salon) scope:**
- **Salon Owner** — full control of their salon; sees master calendar + everything; connects the salon's GHL; manages staff/services/policy.
- **Salon Admin** — manager/front-desk lead; master calendar, bookings, check-in, manage staff/services (no GHL/billing-level settings unless granted).
- **Salon User** — a staff member, with a **staff type**:
  - **Stylist** — has a calendar, availability, services, and a personal-calendar connection; sees own bookings.
  - **Front desk (receptionist)** — sees master calendar, creates bookings, checks clients in; no personal stylist calendar.

Agency users may span multiple salons; salon users belong to one or more salons via membership. Permissions = role × scope × staff type, enforced **server-side on every request and every query**.

**Permission matrix (sub-account scope):**

| Capability | Salon Owner | Salon Admin | Stylist | Front desk |
|---|---|---|---|---|
| View master calendar (all stylists) | ✓ | ✓ | ✗ | ✓ |
| View own calendar | ✓ | ✓ | ✓ | — |
| Create / edit / cancel any booking | ✓ | ✓ | own | ✓ |
| Check clients in | ✓ | ✓ | own | ✓ |
| Manage services | ✓ | ✓ | ✗ | ✗ |
| Manage staff & service assignments | ✓ | ✓ | ✗ | ✗ |
| Set availability | any | any | own | ✗ |
| Booking policy & salon settings | ✓ | ✓ | ✗ | ✗ |
| Connect / configure salon's GHL | ✓ | ✗ | ✗ | ✗ |
| Connect own personal calendar | ✓ | ✓ | ✓ | ✓ |
| View audit log | ✓ | ✓ | ✗ | ✗ |

Agency Owner/Admin inherit all sub-account capabilities across their assigned salons.

---

## 4. Domain model

Multi-tenant. `agency_id` and `salon_id` propagate down; **every salon-scoped query filters by `salon_id` + verified membership** (the tenant-isolation boundary).

- **Agency** — name, settings, branding.
- **Salon** (sub-account) — `agency_id`, name, timezone, branding, GHL `location_id`, **encrypted GHL token**, `booking_policy`, feature flags.
- **User** — `agency_id`, email, `password_hash`, `must_change_password`, optional `ghl_user_id`, `active`, optional `agency_role` (owner/admin/user).
- **SalonMembership** — `user_id`, `salon_id`, `salon_role` (owner/admin/user), `staff_type` (stylist | front_desk | null), `active`.
- **StylistProfile** — per (user, salon): bio, `ghl_calendar_id`, `ics_feed_token`, settings.
- **Service** — `salon_id`, name, `duration_min`, color, `active`. *(No price.)*
- **ServiceStylist** — `service_id` ↔ stylist (which stylists perform which service).
- **Availability** — per stylist: weekly working hours + breaks. **TimeOff** — one-off blocks/overrides.
- **Client** — `salon_id`, name, phone, email, `ghl_contact_id`.
- **Booking** (a client visit) — `salon_id`, `client_id`, `status`, **`booked_by_type`** (salon_owner | salon_admin | stylist | front_desk | voice_ai | chat_widget), `booked_by_user_id` (nullable), **`source`** (in_app | voice_ai | chat_widget | ghl_manual), `ghl_appointment_id`, `notes`, `is_walkin`, `last_synced_at`, timestamps.
  - `status` ∈ {booked, confirmed, arrived, in_service, completed, cancelled, no_show}
- **BookingItem** — `booking_id`, `service_id`, stylist (`user_id`), `starts_at`, `ends_at`. **A booking has one or more items** (multi-service visits; items may have different stylists).
- **BookingPolicy** (on Salon) — `allow_walkins`, `allow_same_day`, `max_advance_days`, `min_notice_minutes`.
- **CalendarConnection** — per user: `ics_feed_token` (always), plus future OAuth token fields (encrypted) for two-way.
- **FeatureFlag / SalonSetting** — per-salon toggles so features can diverge per salon over time.
- **WebhookEvent** — inbound GHL events log (event id, payload hash, `processed_at`) for idempotency + debugging.
- **AuditLog** — `agency_id`, `salon_id`, `actor_user_id`, action, entity, entity_id, meta (JSON), ip, ts.

---

## 5. Features by area

### 5.1 Auth & user provisioning
- Staff are created by Agency or Salon admins (mirrors how GHL adds users). On creation the app sends **one** email: a **temporary password** the user must change on **first login**.
- After that, password-reset requests are triggered by the app but **delivered through GHL** (API/workflow), not directly. Built behind a swappable "email sender" so the temp-password path can also move to GHL if Hostinger deliverability is poor.
- Email + password auth; Argon2id hashing; secure cookies; CSRF; login rate-limiting/lockout.
- RBAC + tenant scoping on every route/query.

### 5.2 Dashboard (landing — owner/admin/front desk)
- **Today's bookings**, full breakdown: time, client, service(s), stylist(s), status, **who booked it**, source.
- Day stats: total bookings, arrived/waiting, completed, no-shows, per-stylist load. *(No revenue — no pricing.)*
- Filters (stylist, service, status) + date switcher; clear arrived / late / no-show states.

### 5.3 Appointments page (check-in)
- One-tap **"Mark arrived"**, then in_service → completed transitions; no-show handling. Search by client name/phone. Per-booking status timeline.

### 5.4 Calendar
- **Master calendar** (owner/admin/front desk): all stylists, color-coded; day/week; click for full detail. Real-time (app DB is source of truth).
- **Per-stylist calendar** (stylist): own bookings + availability editing.
- **Personal external calendars**: see Section 6.

### 5.5 Booking create / edit
- Flow: pick service(s) → app shows qualifying stylists → choose **specific stylist** (default) **or "any available"** (assigns least-busy qualifying stylist) → app shows free slots from availability + existing bookings → choose time → attach/create client → confirm.
- **Multi-service**: a visit can bundle several services, each with its own stylist and time block.
- **`booked_by` is captured automatically** from the authenticated user.
- **Booking policy enforced** per salon: walk-ins, same-day, max advance days, min notice.
- On save: write to app DB **and** push to GHL (Section 7). Edit/cancel mirror to GHL.

### 5.6 Walk-ins
- If the salon's policy allows: front desk/owner create an immediate booking and check the client in in one step.

### 5.7 Availability (stylist)
- Weekly hours + breaks + one-off time off. **App-managed = source of truth for bookable slots.** Pushed to the stylist's GHL calendar so voice/chat only offer valid slots (Section 7).

### 5.8 Services & staff management (owner/admin)
- CRUD services (duration, color). CRUD staff (invite, set role + staff type). Assign stylists ↔ services.

### 5.9 Salon settings (owner/admin)
- Booking policy, branding, feature flags, and the **GHL connection** (encrypted token, calendar mapping, webhook secret, test-connection).

### 5.10 Agency console (agency owner/admin)
- Create/manage salons (sub-accounts), assign agency users to salons, onboarding (provision a salon + link its GHL sub-account), cross-salon overview. New-salon onboarding mirrors the GHL template-duplication flow.

### 5.11 Audit log
- Immutable who-did-what across bookings, status changes, settings, logins; scoped by salon (agency sees across).

---

## 6. Personal-calendar sync (ICS-first)

**Goal (per the spec): a booking lands in the assigned stylist's, the owner's, and the front desk's personal calendars — on whatever platform they use (Google / Outlook / Apple) — and they connect it inside BookTheStyle.** Because both app-origin and GHL-origin bookings end up in the app DB, the app is the single source these feeds are generated from.

**Mechanism — per-user private ICS subscription feeds:**
- Every user gets a "Connect my calendar" page with a private, tokenized subscribe URL (`/cal/{token}.ics`) and copy-paste instructions for Google, Outlook, and Apple.
- A **stylist** subscribes to their own feed (their bookings). **Owner** and **front desk** subscribe to the **master** feed (all salon bookings). One tap, any platform, free, no OAuth, minimal attack surface.
- Feeds are generated live from the app DB, so every booking — whether made in-app or by the voice AI / chat widget via GHL — appears automatically.

**Honest limitations (state these to salons):**
- **One-way (app → personal calendar).** Personal events do not flow back to block availability.
- **Refresh latency.** External calendar clients poll ICS feeds on their own schedule (Google can lag hours), so same-day/walk-in bookings may appear in the *external* calendar with delay. The **master calendar inside BookTheStyle is always real-time** and is what front desk works from.
- **Token security.** Feed URLs are private capability links — rotate-able per user; never expose; serve over HTTPS only.

**Roadmap (not v1): two-way OAuth sync** — Google Calendar API + Microsoft Graph so a stylist's personal busy-times block availability and updates are near-real-time. Apple two-way is CalDAV-only (app-specific passwords) and a stretch goal. **[OPEN]** Confirm ICS-one-way is correct for v1.

---

## 7. GHL integration

GHL is built as a **standardized template sub-account**, duplicated per salon. The app integrates per salon via that salon's **Private Integration Token (PIT)**, scoped to its location (simplest + most secure; OAuth marketplace app is the scale-up path).

### 7.1 GHL template (built once, cloned per salon)
The template should contain, mapping cleanly to the app:
- **Per-stylist calendars** (recommended) so GHL availability is per stylist — required so the voice AI and chat widget only offer slots the stylist is actually free for. *(Alternative: one shared calendar with the stylist as a field + post-hoc conflict handling — riskier; not recommended.)*
- **Tags** to record booking source (e.g. `src-voice-ai`, `src-chat`, `src-frontdesk`, …) per your convention — this is how non-human bookings get a correct **`booked_by`**.
- **Workflows**: (a) outbound reminders (GHL's job entirely), (b) an **inbound webhook** on appointment booked/updated/cancelled → BookTheStyle.
- Custom field for `app_booking_id` to aid matching.

### 7.2 Outbound (App → GHL) — mandatory
On booking create/update/cancel: call GHL to create/update/delete the appointment on the mapped stylist calendar; **store `ghl_appointment_id` immediately**. This is what makes **GHL reminders fire** and keeps the calendar-of-record correct. Also push **availability** to each stylist's GHL calendar so voice/chat book valid slots only.

### 7.3 Inbound (GHL → App) — voice AI & chat widget
The voice AI and chat widget book **inside GHL**. A GHL workflow fires a **webhook** to `/webhooks/ghl` on booking events. The handler:
1. **Upserts by `ghl_appointment_id`** (dedupe), mapping GHL calendar→stylist and contact→client.
2. Sets **`source`/`booked_by_type` from GHL tags** (per template convention). *Recommended: have the workflow also pass `source` explicitly in the webhook body so the app doesn't have to re-query tags — more reliable.*
3. The app's ICS feeds then reflect it automatically → flows to personal calendars.

### 7.4 Idempotency / echo-loop (the #1 correctness risk)
Outbound pushes also trigger the inbound workflow, so the webhook fires for app-created bookings too. Protect with: upsert by `ghl_appointment_id` (already stored on push → updates, never duplicates), a `WebhookEvent` log to drop replays, and `last_synced_at`/version comparison to prevent ping-pong. **Build and test this explicitly.**

### 7.5 Reminders
100% GHL. The app sends none. Only works because every booking reaches GHL (7.2).

### 7.6 Async on shared hosting
Sync runs via Laravel's **`database` queue driven by a 1-minute cron** (no always-on worker on shared hosting). Salon volume tolerates this easily; inbound webhooks process near-instantly on arrival.

---

## 8. Async & real-time notes (shared-hosting realities)
- **Queues:** `database` driver + 1-min cron (`schedule:run` → `queue:work --stop-when-empty`). Used for GHL sync, with retries + failure logging.
- **Live UI:** dashboard/calendar use **Livewire polling** (every few seconds) for near-real-time without WebSockets. Smooth for a front-desk view; a VPS upgrade later enables true push if ever needed.

---

## 9. Security (first-class)

Bake into `CLAUDE.md` as non-negotiable rules:

- **Tenant isolation:** every salon-scoped query filtered by `salon_id` + verified membership/role. Use a current-tenant middleware + Eloquent global scopes. **No IDOR** — no user can reach another salon's records by changing an ID. Agency cross-salon access only via explicit assignment.
- **Transport:** HTTPS only (Hostinger free SSL) + HSTS.
- **Passwords:** Argon2id; temp passwords single-use + forced change on first login.
- **Sessions/CSRF:** HttpOnly + Secure + SameSite cookies; CSRF on all state-changing requests.
- **Injection/XSS:** ORM/parameterized queries only; validate all input; escape all output.
- **Secrets:** GHL tokens + app keys in `.env` (gitignored, outside web root); commit only `.env.example`; **encrypt GHL tokens at rest** in the DB.
- **Webhooks:** verify shared-secret/signature, validate schema, reject forgeries, idempotent, IP-allowlist if feasible.
- **ICS feeds:** private capability tokens, rotate-able, HTTPS only, no enumeration.
- **Headers:** CSP, frame-ancestors/X-Frame-Options, X-Content-Type-Options, Referrer-Policy.
- **Rate-limit** login + reset; **audit log** everything; scheduled **DB backups** (cron `mysqldump`).
- **Dependencies:** Claude Code must **ask before adding any dependency**.

---

## 10. Tech stack (locked) + deploy

- **Backend:** PHP 8.2+ / **Laravel** (built-in auth, CSRF, ORM, RBAC middleware, validation, queues).
- **UI:** **Livewire + Alpine.js + Tailwind** — reactive, single-host, no separate JS build/deploy.
- **DB:** MySQL (Hostinger).
- **Calendar UI:** **Toast UI Calendar** (MIT, free commercial, flexible multi-stylist views). Custom component is the fallback if it limits us. *(Avoid FullCalendar Scheduler/resource views — paid/GPL.)*
- **Personal calendar:** ICS feeds (Section 6).
- **Fonts:** Google Fonts.
- **Deploy flow:** develop locally (`php artisan serve`) → push to GitHub → deploy to Hostinger at milestones via hPanel Git (auto-pull webhook) **wrapped in a deploy script** that runs `composer install`, `php artisan migrate --force`, config/route/view cache, `storage:link`. A bare `git pull` is **not** a working deploy for Laravel. Set up in Phase 7.

---

## 11. Design direction (light, anti-slop) — BookTheStyle

Defense against generic AI looks = a **design-token system enforced from file #1**, not vibes. Lock tokens in `CLAUDE.md`.

- **Aesthetic:** refined, boutique, editorial. Generous whitespace, confident type, restrained palette, subtle depth (thin borders + soft low-opacity shadows, **no** heavy gradients), **no emoji in UI**, dense-but-breathable tables, one accent color.
- **Starter tokens (tune to brand):**
  - Surface: warm off-white/paper (~`#FAF8F5`), not pure white; cards a touch lighter.
  - Ink: near-black (~`#1A1A1A`); muted secondary text.
  - Accent: one confident jewel/earth tone — **avoid** default indigo/violet.
  - Type: intentional pairing — characterful serif/display for headings (e.g. Fraunces / Instrument Serif) + clean sans for UI (Inter/Geist used deliberately).
  - 4px spacing scale; consistent radii (8–12px); subtle layered shadows; purposeful micro-motion only.
- Follow the `frontend-design` discipline when building screens; reject stock component looks.

---

## 12. Build phases (one focused Claude Code session each, with acceptance criteria + tests)

| Phase | Deliverable |
|---|---|
| **0 — Scaffold** | Laravel install, repo structure, `CLAUDE.md`, design tokens, `.env.example`, `.gitignore`. Multi-tenant schema + migrations (agency/salon/user/membership). Auth + RBAC + tenant-isolation middleware skeleton. |
| **1 — Tenancy & users** | Agency console (create salons, assign users), staff invite + temp-password + first-login change, salon settings + booking policy + feature flags. |
| **2 — Core data** | Services, stylists, service↔stylist assignments, availability + time off. |
| **3 — Bookings + dashboard** | Multi-service bookings with `booked_by` + status + walk-ins + policy enforcement; today's-bookings dashboard + breakdown; appointments page + check-in. (Local only.) |
| **4 — Calendar UI** | Master + per-stylist calendars (Toast UI) wired to bookings. |
| **5 — Personal calendar (ICS)** | Per-user ICS feed generation + "Connect my calendar" pages (Google/Outlook/Apple instructions). |
| **6 — GHL sync** | Per-salon PIT storage; outbound push (create/update/cancel + availability); inbound `/webhooks/ghl` + tag/source mapping; idempotency/echo-loop; queue + cron. *(Claude Code can web-search GHL specs; verify against live docs.)* |
| **7 — Hardening & deploy** | Security pass (headers, CSP, rate limits, tenant-isolation audit), audit log, backups, deploy script + hPanel Git, end-to-end QA of both booking paths. |
| **Roadmap (post-v1)** | OAuth two-way calendar (Google/Microsoft), per-salon feature divergence, reporting, OAuth marketplace GHL app for scale. |

---

## 13. Remaining decisions

- **[OPEN]** Confirm ICS one-way personal-calendar sync for v1 (Section 6). Two-way is roadmap.
- **[OPEN]** GHL template calendar shape — per-stylist calendars (recommended, Section 7.1) confirmed for your template?
- Everything else locked: name (BookTheStyle), stack, multi-tenant agency model, multi-service bookings, specific-stylist-default + "any available", no pricing, per-salon booking policy, tag-based source tracking, temp-password email rule.

---

## 14. Driving Claude Code with this doc
- Commit as the repo's spec; create a tight **`CLAUDE.md`** with: stack, security rules (Section 9, esp. tenant isolation), design tokens (Section 11), domain model (Section 4), conventions, and **"ask before adding any dependency; never commit secrets."**
- Work **one phase at a time** with explicit acceptance criteria; have Claude Code write tests; review every diff.
- Claude Code can web-search GHL API specs from the terminal — but verify against live GHL docs (GHL behavior is finicky) and keep the user in the loop for approvals/deploys.
- Secrets in `.env` (gitignored); Claude Code gets only `.env.example`.
