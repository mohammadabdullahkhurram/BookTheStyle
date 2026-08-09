# BookTheStyle — Status & Roadmap

_Updated 2026-08-09. **The app is LIVE in production**: bookthestyle.com on Hostinger Cloud (PHP 8.4, MySQL), Cloudflare-fronted, cron-driven queue, deployed 2026-07-15. Ops runbook: `docs/DEPLOY.md`._

## ✅ Live in production

- **Platform (Phases 0–6)**: multi-tenant schema (agency → salons → memberships), Fortify auth (no public registration, 2FA + passkeys), forced password change, agency console (Dashboard / Salons / Reporting / Users), staff invites with temp passwords, RBAC (agency roles × salon roles × staff types incl. Manager), services with per-stylist duration/buffer overrides (always on — the feature-flag system was removed), availability (weekly grid + split shifts + date-specific entries), pure slot engine (DST-safe, policy-gated), multi-service visits split per service line, walk-ins, check-in workflow, reschedule, custom master + personal calendars, per-user ICS feeds, and the full two-way GoHighLevel sync (PIT per salon, outbound push, inbound webhook with echo-loop protection, source tagging, hourly reconcile, availability push).
- **Booking surfaces**: embeddable multi-service booking widget (per-widget branding/theme, types registry), Voice-AI booking API (per-salon hashed bearer token, GHL wire-quirk tolerant), in-app booking.
- **Theme system**: Marble (salon default) + Classic, Brand/Glacier on agency/auth; Velvet/Gazette/Fern registered as locked "coming soon".
- **Reporting v1** (salon + agency-wide), client profiles (notes/preferences/history), transactional mail (five branded mailables), display-only pricing, auto-no-show/auto-complete automation.
- **Bluejaypro marketing site** (Home/Services/Features/Contact + register) with GHL embeds; BookTheStyle branding in every tab title; salon-first copy.
- **Onboarding wizard** with per-step integration verification.
- **Integration Test/Verify actions** (Settings → Integrations + wizard): connection, contacts scopes, calendar+mapping, availability read-back, non-destructive booking round-trip (creates → reads back → deletes), webhook self-ping, voice-API end-to-end — each with persisted last-verified results and honest "needs live URL" states.
- **Themed confirm dialog everywhere**: zero native `wire:confirm`/`confirm()` remain (guard test enforces it); one accessible top-layer `<dialog>`.
- **Since the July snapshot (Aug 2026)**: managers can optionally **take bookings** (sticky staff-type, self-toggle + edit-modal checkbox); agency operators gained authority over salon owners with an owner-transfer safeguard; **solo permanent deletes** for stylist/client/appointment/service (owner + agency-admin gated, blast-radius confirm modals, appointments always kept via soft-delete tombstones rendered "(removed)"); the **Health check** page (extensible registry: integrations incl. a live GHL token test, SSL/domain, notifications, scheduler/queue, readiness, data-integrity spot checks) with disposable leak-proof `is_test` records, run history, and an **hourly read-only auto-monitor** emailing agency admins on green→red; the health check's test appointment pinned to the collision-proof far-future slot (28 June 3004, 2:00 PM); **Voice AI cancel + reschedule endpoints** (`/api/v1/booking/cancel|reschedule`) with one shared format-blind phone lookup; a designated **Voice AI test client** exempt (is_test-only) from the booking window; the **native-Blade Documentation tab** (salon-setup SOP + technical integration reference, fill-in pills for GHL specifics); Settings' Integrations tab reorganized into a **guided five-step setup**; "Chair Rental" display terminology.
- **Production/ops hardening**: cron-driven queue worker (`--stop-when-empty --max-time=55 --tries=3`), TrustProxies + Cloudflare real-IP handling (`CF-Connecting-IP`), https URL generation, committed build assets (no Node on the server), `app:factory-reset` (production generates a strong one-time owner password), DemoSalonSeeder refuses to run in production, destructive schema commands prohibited in production, **MySQL migration CI job** (migrates from scratch on MySQL 8 every push) + a migration-order guard test, daily retention pruning for `webhook_events` and `failed_jobs`.

## 🔜 Outstanding (the real list)

- [ ] **Live GHL smoke test** on a real location: connect → map staff → availability sync → both booking directions → check-in echo test → reconcile → voice/chat slot validity. The Settings → Integrations Test/Verify buttons are the tooling for this.
- [ ] **Real mail transport** on the server (`MAIL_MAILER`) + SPF/DKIM; send-test all five mailables. (Code-side done; temp passwords/resets are login-critical.)
- [ ] **Backups**: nightly `mysqldump` + a restore drill.
- [ ] **HSTS** (app or Cloudflare-level).
- [ ] Full test **suite** against MySQL (migrations already run on MySQL in CI; tests are SQLite).
- [ ] **Audit log** (SPEC §5.11) — build or consciously defer.
- [ ] How-to videos (framework built, no media shipped — help modal shows "Video coming soon").
- [ ] Master ICS feed for owner/front-desk (feeds are per-stylist-own only).
- [ ] Final design polish pass.
- [ ] Dependency advisories: `guzzlehttp/guzzle` + `guzzlehttp/psr7` have medium CVEs pending an upgrade window (`composer audit`).

## 🧊 Deliberately deferred

- Undo window for destructive actions (themed confirm is the guard today).
- Google Calendar OAuth (ICS feeds only).
- Staging environment (single production + local dev).
- Velvet / Gazette / Fern themes; Chat / Lead-form / Reviews widget types (registry placeholders, locked in the UI).

## Habit notes

- New column/table → additive `php artisan migrate` only; **never** `migrate:fresh` (CLAUDE.md rule 10; production also refuses destructive commands).
- Deploys: build assets locally → commit → push → `git pull` on the server (`docs/DEPLOY.md`).
- After pulling changes locally, restart any `queue:listen` worker (stale code in memory).
- Availability is edited in the app only — GHL-side edits are overwritten on the next push.
