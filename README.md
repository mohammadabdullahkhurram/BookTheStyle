# BookTheStyle

A multi-tenant booking platform for hair/beauty salons, operated by an agency (one agency account) that manages many salons (sub-accounts). Each salon runs its own bookings, stylists, services, and its own GoHighLevel (GHL) sub-account integration. **Scheduling only — no payments.**

`SPEC.md` is the canonical product spec, `CLAUDE.md` holds the operating rules, and `DESIGN-TOKENS.md` is the authoritative design system.

Backups & restore points: every checkpoint tag/branch and its restore command is cataloged in [`docs/BACKUPS.md`](docs/BACKUPS.md).

## Stack

- **PHP 8.4+** · **Laravel 13** (pinned to `13.15.0`) · auth via **Fortify** (no public self-registration — staff are invited)
- **Livewire 4** (+ Blaze) · **Flux UI** (free components only) · **Alpine** (bundled)
- **Tailwind 4** (via `@tailwindcss/vite`) · **Vite** · self-hosted fonts (Schibsted Grotesk + Hanken Grotesk, no CDN)
- Calendar: a bespoke Livewire day/week view (Livewire polling; no external calendar library)
- **MySQL** in production (Hostinger); **SQLite** is fine for local dev/tests
- Tooling: **Pest** (tests), **Pint** (laravel preset), **PHPStan**/Larastan level 7

## Tenancy is subdomain-based

The platform is split four ways by host (production shown; `APP_DOMAIN` is the apex):

| Host | Role |
|---|---|
| `bookthestyle.com` (apex) | Public marketing landing |
| `app.bookthestyle.com` | The application — login/logout, account settings, agency console, salon picker (+ `/cal`, `/webhooks`) |
| `register.bookthestyle.com` | Public "book a call" page (GoHighLevel calendar embed) |
| `{slug}.bookthestyle.com` | Salon tenant subdomains |

The active salon is resolved from the request **Host**. See `SPEC.md` §3.1 for the full decision.

## Local development

Local dev uses **`lvh.me`** — a registrable domain whose wildcard DNS resolves `lvh.me` and every `*.lvh.me` to `127.0.0.1`. **No `/etc/hosts` edits and no extra tooling needed.** We use it instead of `*.localhost` because browsers refuse to set a `Domain` cookie for `localhost`/`*.localhost`, so the login session can't be shared from the apex to a salon subdomain there; `lvh.me` is a normal registrable domain, so the shared session works like production. (`localtest.me` is an equivalent fallback.)

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # SQLite is fine locally: set DB_CONNECTION=sqlite
npm install && npm run dev     # asset bundling / HMR
php artisan serve              # serves on http://lvh.me:8000 (and *.lvh.me)
```

- Reset the database at any time with `php artisan migrate:fresh --seed`.
- `composer dev` runs the server, queue worker, log tailer, and Vite together (one command).
- `composer setup` does a first-time install (composer + `.env` + key + migrate + npm build).

The relevant `.env` values (already in `.env.example`):

```dotenv
APP_URL=http://lvh.me:8000
APP_DOMAIN=lvh.me               # apex; app at app.APP_DOMAIN, salons at {slug}.APP_DOMAIN
SESSION_DOMAIN=.lvh.me          # leading dot → the login session is shared across subdomains
REGISTER_EMBED_FRAME_SRC="https://*.leadconnectorhq.com https://*.msgsndr.com"  # book-a-call iframe CSP
```

### URLs

| Area | URL |
|---|---|
| Marketing / landing (public) | `http://lvh.me:8000/` |
| Book a call (public) | `http://register.lvh.me:8000/` |
| Login (all auth) | `http://app.lvh.me:8000/login` |
| App home → salon picker | `http://app.lvh.me:8000/` (auth'd) · `/dashboard` |
| Agency console | `http://app.lvh.me:8000/agency` |
| Account settings | `http://app.lvh.me:8000/settings/profile` |
| **Demo Salon** dashboard | `http://demo.lvh.me:8000/` |
| Demo Salon appointments / book / clients / staff / services / availability / settings | `http://demo.lvh.me:8000/{appointments,book,clients,staff,services,availability,settings}` |
| **Other Salon** (tenant-isolation check) | `http://other.lvh.me:8000/` |

`php artisan serve` binds `127.0.0.1:8000`; `lvh.me` and every `*.lvh.me` resolve there, so all four hosts work on one server. Log in at `http://app.lvh.me:8000/login`, then open `http://demo.lvh.me:8000/` — you stay logged in (the session cookie is scoped to `.lvh.me`).

### Seeded accounts (`php artisan db:seed`)

All passwords are `password` unless noted. Slugs: **Demo Salon → `demo`**, **Other Salon → `other`**.

| Email | Role | Reaches |
|---|---|---|
| `agency@bookthestyle.test` | Agency Owner | every salon + agency console |
| `admin@bookthestyle.test` | Agency Admin | every salon + agency console |
| `user@bookthestyle.test` | Agency User | Demo Salon only |
| `owner@demo-salon.test` | Salon Owner | `demo.lvh.me:8000` |
| `frontdesk@demo-salon.test` | Front Desk | `demo.lvh.me:8000` |
| `stylist@demo-salon.test` | Stylist | `demo.lvh.me:8000` |
| `newhire@demo-salon.test` | Stylist (temp password `temporary`, forced change) | `demo.lvh.me:8000` |
| `owner@other-salon.test` | Salon Owner (different agency) | `other.lvh.me:8000` |

Log in as a Demo Salon user and open `http://other.lvh.me:8000/` to confirm tenant isolation returns **403**.

## Project layout

Where things live (after the latest housekeeping pass):

```
app/
  Actions/          # write operations, one action class per use case
  Concerns/         # shared validation-rule traits
  Enums/            # roles, staff types, booking status/source, …
  Http/
    Controllers/    # the few non-Livewire controllers (e.g. forced password change)
    Middleware/     # ResolveSalon (tenant resolution), EnsurePasswordChanged, SecurityHeaders
  Livewire/Actions/ # Logout
  Mail/             # TemporaryPasswordMail (the one email the app sends directly)
  Models/
    Concerns/       # BelongsToSalon trait
    Scopes/         # SalonScope global scope (tenant isolation)
  Policies/         # AgencyPolicy, SalonPolicy
  Rules/            # SalonSlug
  Services/         # Booking (policy + slot engine), Calendar (calendar data)
  Support/          # AccentPalette, PastelPalette, ReservedSlugs, Permissions/, Notifications/
resources/
  css/app.css       # Tailwind 4 @theme mapped to DESIGN-TOKENS + Flux theming
  js/               # app.js (origin-aware wire:navigate), passkeys.js
  views/
    components/     # x-* primitives; components/ui/* is the design system (button, card, …)
    layouts/        # app (sidebar shell) + auth
    pages/          # screens, namespaced as Livewire pages (agency, salon, auth, settings)
    partials/       # head, settings-heading
    welcome.blade.php / register.blade.php   # public marketing + book-a-call
routes/             # web.php (host-split groups), settings.php, console.php
config/             # standard Laravel + salon_features.php (per-salon feature flags)
database/           # migrations, factories, DatabaseSeeder
public/images/      # brand logos (full-logo.png, icon-logo.png) — see public/images/README.md
design/             # design bundle: screenshots + reference markup (see design/README.md)
```

## Design system

- **`DESIGN-TOKENS.md`** (repo root) is the authoritative source for exact hexes, type scale, spacing, radii, and component specs. `resources/css/app.css` maps Tailwind 4 `@theme` and the Flux theme to it. **Light mode only.**
- **Accent** is four swappable tokens (`--accent` / `--accent-hover` / `--accent-tint` / `--accent-ink`), violet by default. Presets `sage` and `terracotta` apply via `<html data-accent="sage|terracotta">`; a salon's branding accent overrides the four tokens through `App\Support\AccentPalette`.
- Reusable primitives live in `resources/views/components/ui/` (`x-ui.button`, `x-ui.status-pill`, `x-ui.stat-card`, `x-ui.avatar`, …) — build new screens from these so they inherit the system.
- `design/` holds the target screenshots and Claude Design reference markup; see `design/README.md`.

## Key env vars

All live in `.env.example`:

| Var | Purpose |
|---|---|
| `APP_DOMAIN` | apex domain; app at `app.`, register at `register.`, salons at `{slug}.` (local: `lvh.me`) |
| `SESSION_DOMAIN` | leading-dot parent domain so the login session is shared across subdomains (local: `.lvh.me`) |
| `REGISTER_EMBED_FRAME_SRC` | CSP `frame-src` allow-list for the book-a-call iframe (register host only) |
| `DB_CONNECTION` | `mysql` (production) or `sqlite` (local/tests) |
| `BLAZE_DEBUG` | keep `false` — the Blaze debug bar pulls a Google webfont and the app is strictly CDN-free |

There is **no global GHL key** — each salon's Private Integration Token is stored per-salon in the database, **encrypted at rest**.

## Tests, formatting, static analysis

```bash
php artisan test                 # or: ./vendor/bin/pest
./vendor/bin/pint                # format (add --test to check only)
./vendor/bin/phpstan analyse     # Larastan, level 7
composer test                    # CI parity: config:clear + pint --test + phpstan + artisan test
```

Every suite includes at least one tenant-isolation test; keep it green.
