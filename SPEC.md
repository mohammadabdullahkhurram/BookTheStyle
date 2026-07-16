# BookTheStyle — Domain Specification

What the system does and the rules it obeys. Deep integration mechanics live in `docs/ARCHITECTURE.md`; operations in `docs/DEPLOY.md` / `docs/OPERATIONS.md`.

## 1. What BookTheStyle is

A multi-tenant booking platform for hair/beauty salons, operated by an **agency** (one agency account) managing many **salons** (sub-accounts). Per salon: salon → services → each service has qualifying stylists → each stylist has app-managed availability → all bookings roll up into one master calendar, mirror to the salon's GoHighLevel sub-account, and fan out to staff personal calendars via ICS feeds.

**Division of responsibility (load-bearing):**
- **BookTheStyle is the system of record and the booking engine** — who booked, services on a visit, check-in status, availability, tenancy. Every booking surface (in-app, embeddable widget, GHL voice AI, GHL chat) ultimately books through the app's one slot engine, so policy and conflicts are enforced in exactly one place.
- **GHL is the conversation layer** — reminders, the voice AI, chat, contacts/CRM. It receives a mirror of every booking (that's what makes reminders fire) and can originate bookings that flow back in.
- **Scheduling only — no payments.** Deposits/payments are each salon's own business in GHL/Stripe, outside this app.

## 2. Tenancy, roles, permissions

Two scopes, mirroring GHL's agency/sub-account structure. Enforced server-side on every request and query (`SalonPolicy`, `AgencyPolicy`, `ResolveSalon`, the `BelongsToSalon` global scope).

**Agency scope:** Owner (everything; **exactly one, ever** — never grantable, untouchable by anyone else, edits only their own account) · Admin (near-everything, but cannot touch the owner) · User (only explicitly assigned salons, no console administration).

**Salon scope:** three roles — `salon_owner` | `salon_manager` | `stylist` (front desk was absorbed into manager: functionally identical). **The role carries the permissions; `staff_type` survives as the orthogonal BOOKABILITY flag** (`stylist` or none): a stylist-role member always carries it, a manager never does, and an **owner may also carry it** — the owner-who-cuts-hair case — toggled only by the owner themself on their own row in salon → Users.

| Capability | Owner | Manager | Stylist |
|---|---|---|---|
| Today + master calendar / all bookings, check-in, create bookings | ✓ | ✓ | Today + own calendar column, own appointments only |
| Manage clients / services / users / reports / settings / widgets / setup | ✓ | ✓ | ✗ (403 server-side) |
| Edit availability | anyone's | anyone's | own |
| GHL connection + integration checks | ✓ | ✓ | ✗ |
| Bookable (takes bookings) | optional (self-toggled) | never | always |
| Touch the salon OWNER (edit/demote/deactivate/reset/delete) | ✗ (self-account only) | ✗ | ✗ |
| Delete users | managers + stylists | managers + stylists (never the owner) | ✗ |
| Delete own account | ✓ | ✗ (salon-managed) | ✗ (salon-managed) |
| Delete/deactivate the salon | — (no in-app delete; policy reserves it) | ✗ | ✗ |
| Personal ICS feed | ✓ | ✓ | ✓ |

A stylist's reachable salon surface is exactly {Today, calendar (own view), own appointments, own availability, own account} — everything else 403s (`StylistScopeTest` pins the full route matrix). Adding a salon user asks exactly: name, email, phone, role (manager or stylist); owner is never grantable through user management.

**Salon owner** is provisioned automatically at salon creation from the contact person (existing accounts are linked; new ones get a temp password via the standard invite mails) and is never grantable through staff management. **Agency owners/admins retain the platform override** (deactivate salons, full salon reach via the policy `before` hook) — deliberately, so the agency can always operate its own platform; the one thing even they cannot do is touch an existing salon owner's membership or mint a second agency owner.

### 2.1 Host-based routing

Four hostnames, one app (`routes/web.php`; `APP_DOMAIN` is the apex):

| Host | Role | Auth |
|---|---|---|
| apex | Public marketing site | none |
| `app.` | Login, account settings, agency console, salon picker, `/cal/{token}.ics`, `/webhooks/ghl`, `/api/v1/booking/*` | session / token per route |
| `register.` | Public book-a-call page (GHL calendar embed; the one CSP frame-src relaxation) | none |
| `{slug}.` | Salon tenant app + the public widget (`/widget`, `/api/widget/*`) | session + membership / public rate-limited |

Explicit host groups register before the `{slug}` wildcard; reserved slugs (`app`, `register`, `www`, `api`, `cal`, `webhooks`, …) are rejected at both routing and validation (`App\Rules\SalonSlug`, `App\Support\ReservedSlugs`). Sessions share across subdomains via a leading-dot `SESSION_DOMAIN`. Local dev mirrors all of this on `lvh.me` (see README).

## 3. Domain model

`agency_id`/`salon_id` propagate down; every salon-scoped model uses the `BelongsToSalon` trait + `SalonScope`. Migrations are the schema source of truth — this is the shape, not the column list.

- **Agency** → **Salon** (slug, timezone, currency, booking policy + automation, branding, app theme, API token hash, integration-check results) → **SalonMembership** (role, staff type, active) ← **User** (agency_id, optional agency_role, must_change_password, 2FA/passkeys).
- **Service** — duration, display-only price (`price_cents`, salon currency), auto palette color, active flag. **ServiceStylist** pivot carries per-stylist `duration_override` / `buffer_override`.
- **StylistProfile** — per (user, salon): bio, GHL mapping (`ghl_user_id`), availability-sync state (`ghl_schedule_id`, status/error/hash/synced_at).
- **Availability** — weekly windows per stylist, `kind` = work | break; split shifts are two work windows. **TimeOff** — dated overrides, `kind` = off | hours (date-specific hours replace the weekly schedule for that date).
- **Client** — per salon; profile (allergies, formula notes, preferred stylist/contact, birthday), GHL contact link + push state. **ClientNote** — timestamped notes.
- **Booking** — ONE service performed by ONE stylist. Multi-service visits persist as separate bookings linked by `visit_group_id` (this makes the GHL mirror a clean 1:1 — see ARCHITECTURE). Carries status, `booked_by_type` + `booked_by_user_id`, `source`, notes, walk-in flag, and its GHL sync state (`ghl_appointment_id`, status/error/payload-hash/last-pushed-status). **BookingItem** — the service line (service, stylist, starts/ends, buffer). **BookingStatusEvent** — the status timeline.
- **Widget** — per salon, multiple; own `public_id`, branding, theme, `type` (registry: booking live; chat/lead_form/reviews are locked placeholders).
- **SalonGhlConnection** — per salon: location id, encrypted PIT, master calendar id, webhook secret, last-verified.
- **CalendarConnection** — per user: hashed ICS feed token (shown once, rotate/revoke).
- **WebhookEvent** — inbound GHL event log (payload, hash, processing outcome); pruned after 30 days (never PENDING rows).

## 4. Booking & status model

`BookingStatus` (enum is the source of truth): active flow is **booked → arrived (checked in) | no_show | cancelled**; rescheduling is a time change, not a status. `confirmed`, `in_service`, `completed` remain as legacy states so history stays valid. Transitions are server-enforced (`allowedTransitions`), destructive ones confirm in the UI with specific copy, every transition is timeline-logged and mirrored to GHL.

Automation (per-salon policy): auto-no-show after a grace period (opt-in) and auto-complete of checked-in visits, both applied by the scheduled `bookings:close-elapsed`.

`source` ∈ in_app · web_widget · voice_ai · chat_widget · ghl_manual · ghl_other; `booked_by_type` adds the staff flavor. Both are shown throughout the UI and set automatically per surface.

## 5. Availability & the slot engine

- **App-managed availability is the single source of truth.** Weekly work/break windows + dated overrides per stylist, edited only in the app (a GHL-side edit is overwritten on the next push — one-way by design, so there is exactly one authority and no merge problem).
- The **slot engine** (`app/Services/Booking/`) computes bookable slots on a 15-minute grid from: work windows minus breaks/time-off, existing booking items + buffers, per-stylist service duration overrides, and the salon booking policy (walk-ins, same-day, max advance days, min notice). DST-safe (salon timezone, UTC storage).
- "Any available" stylist selection assigns the least-busy qualifying stylist. Multi-service visits validate each service line independently (independent times allowed; same-stylist overlaps refused at finalize, named per service).
- Booking writes re-validate the slot under a lock — the engine is authoritative for every surface, including GHL-originated bookings pushed with `ignoreFreeSlotValidation` (the app already validated).

## 6. Personal calendars (ICS)

Per-user private feed at `/cal/{token}.ics`: token hashed at rest, shown once, rotate/revoke; serves only the user's own bookings, live from the DB. One-way and read-only — stated in the UI, with per-app connect instructions (copy-link-first; Apple gets the one honest `webcal://` shortcut). External clients poll on their own schedule (Google can lag hours) — the in-app calendar is the real-time surface.

## 7. GHL integration contract (summary)

Full design + rationale: `docs/ARCHITECTURE.md`. The contract:

- **Per-salon PIT** (encrypted, write-only in the UI) scoped to that salon's GHL location; required scopes listed in `config/ghl.php` and surfaced at every token entry point. No global key exists.
- **Outbound**: booking create/update/cancel → the salon's master GHL calendar (one appointment per booking, assigned to the mapped provider), contact upsert, availability schedules per mapped stylist, calendar slot settings. Queued, hash-idempotent, per-location throttled.
- **Inbound**: `/webhooks/ghl` (shared secret header, replay-deduped) turns GHL-side appointment/contact events into app bookings/clients — with echo-loop protection so the app's own pushes never bounce back as changes.
- **Reconcile**: hourly drift repair reading GHL's events feed (±7 days) per connected salon.
- **Voice AI**: GHL custom actions call the app's `/api/v1/booking/availability` + `/create` with the salon's bearer token — the app answers with speakable messages and real slots.
- Contacts: inbound contact events are tag-gated (`config('ghl.client_tag')`) so GHL's lead firehose never floods the client directory; app-side real clients are auto-tagged.

## 8. Security invariants

- **Tenant isolation is sacred**: `salon_id` + membership verified on every query; no IDOR; agency cross-salon reach only via explicit assignment. At least one tenant-isolation test in every suite area.
- Secrets: GHL tokens + webhook secrets encrypted at rest; API/feed tokens stored hashed, plaintext shown once; nothing sensitive logged.
- Sessions HttpOnly + SameSite=lax, secure in production; CSRF everywhere except the token-authenticated server-to-server surfaces (`webhooks/*`, `api/*`); Argon2id; forced first-login password change; strong password policy in production.
- Every public endpoint is rate-limited (login, feeds, webhook, widget API per IP+salon, booking API per token); real client IPs come from Cloudflare (`TrustCloudflareClientIp`).
- Strict CSP (self-hosted everything); the two deliberate relaxations are the register-page embed frame-src and the widget page's `frame-ancestors *` (it exists to be iframed).
- Destructive schema commands are prohibited in production; migrations are additive (CLAUDE.md rule 10).
