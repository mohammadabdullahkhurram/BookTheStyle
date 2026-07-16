# BookTheStyle

Multi-tenant booking platform for hair/beauty salons, operated by one **agency** (Bluejaypro) that manages many **salons**. Each salon runs its own bookings, stylists, services, embeddable booking widget, and its own GoHighLevel (GHL) sub-account integration. **Scheduling only — no payments.**

**Live in production:** bookthestyle.com (Hostinger Cloud, PHP 8.4, MySQL, Cloudflare-fronted).

| Doc | Purpose |
|---|---|
| [`SPEC.md`](SPEC.md) | Domain spec: tenancy, roles, booking/status model, availability rules |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The hard parts: GHL sync design, wire quirks, voice API, tenant isolation |
| [`docs/DEPLOY.md`](docs/DEPLOY.md) | Operations runbook: deploy, update, rollback, cron, Cloudflare |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Onboarding a salon end-to-end (agency team runbook) |
| [`docs/BACKUPS.md`](docs/BACKUPS.md) | Production backup/restore + git restore points |
| [`docs/STATUS-and-ROADMAP.md`](docs/STATUS-and-ROADMAP.md) | Honest state of play: shipped / outstanding / deferred |
| [`DESIGN-TOKENS.md`](DESIGN-TOKENS.md) | The design system contract (exact tokens, build to it) |
| [`CLAUDE.md`](CLAUDE.md) | Working agreement for AI-assisted changes |

## Architecture in brief

- **Multi-tenant, subdomain-per-salon.** Four hostnames off one Laravel app: apex (marketing), `app.` (auth, agency console, `/cal` feeds, `/webhooks`, voice API), `register.` (public book-a-call), `{slug}.` (salon tenants). The active salon resolves from the request Host; `ResolveSalon` middleware + a `salon_id` global scope enforce isolation server-side.
- **Two-way GHL sync per salon.** Each salon connects its own GHL sub-account via a Private Integration Token (encrypted at rest). Bookings push out (reminders, voice AI, chat live in GHL); GHL-side bookings flow back in via webhook; an hourly reconcile repairs drift. Echo-loop protection is the load-bearing piece — see `docs/ARCHITECTURE.md`.
- **The app is the booking engine.** GHL's voice AI books through the app's own `/api/v1/booking/*` endpoints (per-salon bearer token); the embeddable widget books through slug-scoped public endpoints. One slot engine serves every path.
- **Theme system.** Salon app: Marble (default) or Classic per salon; agency/auth surfaces use the brand palette; widgets carry per-widget branding. Registry: `app/Support/ThemeRegistry.php`.
- **Shared-hosting-shaped ops.** Database queue drained by a single per-minute cron (no supervisor); assets built locally and committed (no Node on the server); Cloudflare terminates TLS.

## Stack

- **PHP 8.4** · **Laravel 13** (pinned `13.15.0` — see CLAUDE.md) · **Fortify** auth (no public self-registration; staff are invited) · 2FA + passkeys
- **Livewire 4** (+ Blaze) · **Flux UI** (free components only) · **Alpine** (bundled) · **Tailwind 4** · **Vite**
- Self-hosted fonts (Fraunces + Hanken Grotesk — no CDN); bespoke Livewire calendar (polling, no external calendar library)
- **MySQL** in production; **SQLite** for local dev/tests
- **Pest** (tests) · **Pint** · **Larastan** level 7

## Local development

Local dev uses **`lvh.me`** (wildcard DNS to `127.0.0.1` — no `/etc/hosts` edits). `*.localhost` does not work: browsers refuse a `Domain` cookie there, which breaks the cross-subdomain session.

```bash
composer setup                              # first time: install + .env + key + migrate + build
php artisan db:seed                         # Bluejaypro agency + 3 agency accounts
php artisan db:seed --class=DemoSalonSeeder # full demo salon at demo.lvh.me (additive, idempotent)
composer dev                                # server + queue worker + log tail + Vite, one command
```

**Never `migrate:fresh` / `migrate:refresh` / `db:wipe`** — additive `php artisan migrate` only (CLAUDE.md rule 10; production refuses destructive commands outright). To get demo data back after `app:factory-reset`, re-run the DemoSalonSeeder.

| Area | URL |
|---|---|
| Marketing (public) | `http://lvh.me:8000/` |
| Book a call (public) | `http://register.lvh.me:8000/` |
| Login / app / agency console | `http://app.lvh.me:8000/login` · `/dashboard` · `/agency` |
| Demo salon | `http://demo.lvh.me:8000/` |

**Seeded accounts** (all passwords `password`): `agency@bookthestyle.test` (Agency Owner), `admin@bookthestyle.test` (Agency Admin), `user@bookthestyle.test` (Agency User, no salons). The DemoSalonSeeder adds `owner@demo.test`, `frontdesk@demo.test`, `maya@demo.test` (stylist) on the `demo` salon.

Key env vars (all documented inline in `.env.example`, including the production block at the top): `APP_DOMAIN`, `SESSION_DOMAIN` (leading dot — shares the session across subdomains), `TRUSTED_PROXIES`, `REGISTER_EMBED_FRAME_SRC`, retention knobs.

## Project layout

```
app/
  Actions/           # write operations, one class per use case (Bookings, Salons, Staff, …)
  Console/Commands/  # bookings:close-elapsed, ghl:reconcile, app:factory-reset, ghl repair
  Enums/             # roles, staff types, BookingStatus/Source, AvailabilityKind
  Http/Middleware/   # ResolveSalon (tenant boundary), TrustCloudflareClientIp, SecurityHeaders, …
  Jobs/              # queued GHL sync (bookings, availability, webhook processing)
  Models/            # Concerns/BelongsToSalon + Scopes/SalonScope = tenant isolation
  Policies/          # AgencyPolicy, SalonPolicy (all authorization)
  Services/
    Booking/         # slot engine + booking policy (the one engine every surface uses)
    BookingApi/      # voice-AI booking API (VoiceBookingApi, VoiceInput wire tolerance)
    Calendar/        # calendar data + ICS feed generation
    Ghl/             # client, pushers, inbound sync, reconcile, integration checks
  Support/           # ThemeRegistry, AccentPalette, WidgetBranding, PublicUrl, HelpDocs, …
resources/views/
  components/ui/     # the design-system primitives (build screens from these)
  pages/             # Livewire single-file components (agency, salon, settings, marketing)
  partials/          # ghl-connection-card, ghl-scopes, integration-check, head
routes/              # web.php (host-split groups), settings.php, console.php (scheduler)
public/build/        # committed Vite output — the server has no Node (docs/DEPLOY.md)
```

## Tests, formatting, static analysis

```bash
php artisan test            # Pest; suite runs on SQLite
composer test               # CI parity: pint --test + phpstan + tests
```

CI also migrates the full schema from scratch against real MySQL 8 on every push (`mysql-migrations` job) — SQLite silently ignores MySQL-only schema semantics. Every phase of work keeps at least one tenant-isolation test green.
