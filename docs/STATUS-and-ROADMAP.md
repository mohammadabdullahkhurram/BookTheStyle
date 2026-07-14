# BookTheStyle — Status & Roadmap

_Updated 2026-07-14 (pre-launch cleanup). Reflects `main` (Phases 0–6 complete + widgets/themes/reporting/marketing waves, CI green). Deeper detail: `docs/AUDIT-REPORT.md` (code audit) and `docs/PRODUCT-RESEARCH-REPORT.md` (market/gap analysis)._

## ✅ Complete (Phases 0–6)

- **Phase 0–1 — platform**: multi-tenant schema (agency → salons → memberships), Fortify auth (no public registration, 2FA + passkeys), forced password change, agency console, staff invites with temp passwords, salon settings + booking policy + feature flags, RBAC (agency roles × salon roles × staff types incl. Manager).
- **Phase 2 — core data**: services (auto palette colors, stylist assignment at create), per-stylist duration/buffer overrides (buffers dormant behind `stylist_buffers` flag), availability + time off.
- **Phase 3 — bookings**: pure slot engine (DST-safe, policy-gated), multi-service bookings split per service line (`visit_group_id`), walk-ins, check-in workflow, status timeline, reschedule, concurrency lock, today dashboard.
- **Phase 3.5/3.7 — tenancy routing**: subdomain-per-salon, four-way host split (apex / app / register / {slug}), `lvh.me` local parity.
- **Phase 4 — calendar**: custom master (column-per-stylist) + personal calendars, service-colored blocks, breaks/time-off hatching, 5s polling, detail modal.
- **Phase 5 — ICS feeds**: per-user tokenized feeds (hashed, show-once, rotate/revoke), connect page with per-platform instructions.
- **Phase 6 — GoHighLevel sync (all sub-phases)**:
  - **6a — connection**: per-salon PIT (encrypted at rest, write-only UI), test-connection, two-tier staff mapping (stylists → calendar team members; other staff → location users).
  - **6b — outbound push**: queued booking create/update/cancel to GHL, contact upsert, payload-hash idempotency, per-location throttling.
  - **6c — inbound webhook**: per-salon shared secret, replay dedupe, hardened parsing against real payload shapes, **echo-loop protection** (state-equality + last-change-wins + timestamp-less gates, per-event decision log), GHL-originated bookings (voice AI / chat widget / manual) become app bookings.
  - **6d — source tagging + reconciliation**: `bookings.source` throughout the UI; hourly `ghl:reconcile` drift repair; per-booking sync status + retry panels.
  - **6e — availability push**: per-stylist GHL schedules (weekly rules + date overrides), calendar slot settings, change-triggered + manual sync. _Spec-verified only — needs a live-location smoke test (see Phase 7)._
- **Availability redesign (July 2026)**: staff-card grid → docked drawer, weekly grid editor with split shifts + copy-times, date-specific hours/off entries (`time_off.kind`), read view for all members, editing gated by `AvailabilityAccess`.
- **Transactional email**: five branded queued mailables (account created, temp password, reset, staff invite, salon added), app-direct by decision (never GHL), fail-safe if the transport is down.
- **Design system**: tokens, shell, all app screens, public/auth pages, theme-aware logo.

## ✅ Pre-launch gap fixes (shipped)

- [x] Auto-no-show configurable per salon (grace period / opt-out) — settings booking policy.
- [x] Display-only service prices (services.price_cents + currency; reporting + widget totals).
- [x] Client profiles: notes, preferences (allergies/formulas/preferred stylist/birthday), visit history.
- [x] Reporting v1: salon reports + AGENCY-wide reports (totals, per-salon breakdown, source mix).

## ✅ Also shipped since the audit

- Embeddable booking widget: per-service loop flow, inline availability calendar, month endpoint, multi-widget per salon (own branding/theme/embed id), widget types registry.
- Theme system: Marble app-wide (default) + Classic preserved + Brand (landing palette) on auth/agency; registry with coming-soon entries.
- Agency console: Dashboard rename, agency Reporting, full Users directory; personal calendar feed moved salon-side.
- Bluejaypro marketing site (Home/Services/Features/Contact) with GHL booking/reviews embeds + CSP; register page carries the live embed.
- DemoSalonSeeder (additive, idempotent); destructive DB resets forbidden (CLAUDE.md rule 10).

## 🔜 Phase 7 — hardening + deploy (the launch gate)

- [ ] Deploy foundation: deploy script + hPanel Git, wildcard DNS + TLS (`*.bookthestyle.com`), production `.env` (MySQL, cookies, `APP_DEBUG=false`), the one-line cron.
- [ ] Real mail transport + SPF/DKIM; send-test all five mailables.
- [ ] **Live GHL smoke test** on a real template location: connect → map staff → availability sync → both booking directions → check-in echo test → reconcile → voice/chat slot validity. Paste the register-page embed code.
- [ ] Run migrations + full suite against MySQL once (suite is SQLite-only today).
- [ ] Backups (nightly `mysqldump` + restore drill), HSTS, `webhook_events`/`failed_jobs` retention.
- [ ] Audit log (SPEC §5.11) — build or consciously defer.

## 🧩 Smaller open items

- [ ] Record how-to videos (framework built, one topic registered, no media shipped).
- [ ] Master ICS feed for owner/front-desk (feeds are per-stylist-own only).
- [ ] Final design polish pass.

## Habit notes

- New column/table in a report → run `php artisan migrate` locally before testing.
- After pulling changes, restart any local `queue:listen` worker (stale code in memory).
- Availability is edited in the app only — GHL-side edits are overwritten on the next push.
