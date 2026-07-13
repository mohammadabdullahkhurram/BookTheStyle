# CLAUDE.md — BookTheStyle

Operating rules for Claude Code on this repo. **Read this and `SPEC.md` (the full spec) before doing any work.** If a prompt conflicts with these rules, stop and ask.

## Project
BookTheStyle — a multi-tenant salon booking platform. One **agency** operates many **salons** (sub-accounts). Each salon has services → qualifying stylists → app-managed availability → bookings that roll up into one master calendar, mirror to GoHighLevel (reminders + the voice AI + chat widget), and fan out to staff personal calendars via ICS feeds. **Scheduling only — no payments.** Full detail lives in `SPEC.md`.

## Stack (do not deviate without asking)
- PHP 8.4+ (Laravel 13 pulls Symfony 8.1, which requires PHP ≥ 8.4.1) · **Laravel 13** · **Livewire 4** (+ Volt single-file components) · **Tailwind 4** · Alpine (bundled) · **Flux UI — FREE components only**.
- Auth: Laravel built-in (**Fortify**). **Not** WorkOS.
- DB: **MySQL** (production target); SQLite allowed for local dev/tests.
- Calendar UI: **Toast UI Calendar** (MIT). Never use FullCalendar Scheduler/resource views (paid/GPL).
- Tooling: Pest (tests), Pint (formatting), Laravel Boost (AI-assisted dev) enabled.
- Verify current stable versions before installing (web search / Packagist is fine).

## Golden rules (non-negotiable)
1. **Tenant isolation is sacred.** Every salon-scoped query filters by `salon_id` + verified membership. **No IDOR** — never trust an ID from the request without an ownership/membership check. Agency cross-salon access only via explicit assignment.
2. **Ask before adding ANY dependency** beyond the stack above.
3. **Never commit secrets.** Keys/tokens live in `.env` (gitignored); keep `.env.example` current; **encrypt GHL tokens at rest** in the DB.
4. **Flux FREE only.** If a needed component is Flux Pro (paid), build it with plain Tailwind instead. No paid licenses, ever.
5. **Write tests** every phase; always include at least one tenant-isolation test; run the suite before declaring done.
6. **One phase at a time.** Do only what the current phase prompt asks; don't scaffold future features early.
7. **GHL:** you may web-search the current GHL API docs, but verify against live docs and surface assumptions — never hardcode unverified endpoints.
8. **You may commit and push to GitHub. You may NOT deploy** — Hostinger deploys are manual and triggered by the owner.
9. **No public self-registration.** Staff are created by admins: invite → temporary password → forced change on first login.
10. **Never reset the database.** `migrate:fresh`, `migrate:refresh`, `db:wipe` and any other destructive reset are FORBIDDEN in prompts, scripts, docs and workflows — only additive `php artisan migrate`. The owner's local data (salon, GHL setup, widgets) must survive every migration; data changes in migrations are additive/backfill-safe, never drop-and-recreate. To restore demo data, run `php artisan db:seed --class=DemoSalonSeeder` (additive, idempotent).

## Architecture conventions
- Tenancy tables: `agencies`, `salons`, `users`, `salon_memberships`. Salon-scoped models use a `BelongsToSalon` trait + global scope; a `ResolveSalon` middleware sets the active salon (route/session) and enforces membership.
- RBAC via PHP enums: salon roles (`salon_owner` | `salon_admin` | `user`) + `staff_type` (`stylist` | `front_desk`); agency roles (`agency_owner` | `agency_admin` | `agency_user`). Enforce with Gates/Policies. (No spatie/permission unless approved.)
- Business logic in service/action classes, not controllers or Livewire components. Keep components thin.
- Migrations are the schema source of truth; everything reversible.

## Design tokens (light mode — enforce everywhere)
**`DESIGN-TOKENS.md` is the authoritative source** for exact hexes, type scale, spacing, radii, and component specs — build to it. Map Tailwind 4 `@theme` **and** the Flux theme to these (see `resources/css/app.css`). Light mode only; sentence case; no emoji.

- Fonts (self-hosted via `@fonts`, no CDN): headings/display **Schibsted Grotesk** (400–800, `--font-display`/`--font-serif`), body/UI **Hanken Grotesk** (400–700, `--font-sans`).
- **Accent is four swappable tokens** (`--accent` / `--accent-hover` / `--accent-tint` / `--accent-ink`) — **violet** default (`#6555E4`/`#5544CC`/`#ECEAFB`/`#4B3FA0`); **sage** and **terracotta** are presets (`<html data-accent="sage|terracotta">`). A salon's branding accent overrides these four via `App\Support\AccentPalette` (preset name or hex; head partial emits the inline override). Utilities `bg-accent` / `text-accent` / `bg-accent-soft`(=tint) map to them.
- Surfaces: app bg `#F6F5F3` (`paper`) · card `#FFFFFF` · muted/track `#EFEDE8` · field `#FCFBF9`. Text: ink `#1C1B1A` · body `#56534C` · secondary/muted `#6B6862` · faint `#9C9890`. Borders: card `#EAE8E3` (`border`) · input `#E0DDD6` · row `#F4F2ED`.
- Status pills + stat tones (exact bg/text in DESIGN-TOKENS §"Status pills"): booked grey · arrived `#E3EDF6`/`#356088` · in-service `#FBEFD6`/`#8A5A1E` · completed `#E7EFE4`/`#3E5C3A` · no-show `#F8E3E3`/`#A23A3A` · cancelled. Stat-number tones: info/success/danger.
- Pastel families (`App\Support\PastelPalette`, rotate by stylist): green/pink/amber/violet — calendar blocks + client avatars.
- Radii (DESIGN-TOKENS): segment 8 · chip 9 · input 11 · button 12 · nav 13 · stat 16 · list 18 · modal 20 · pill 99. Subtle shadows (`0 1px 2px /.04`; button `0 2px 10px /.12`). Spacing 6·9·12·16·18·22·24·28·32.
- Reusable primitives: `x-ui.button` (primary/secondary), `x-ui.status-pill`, `x-ui.avatar`, `x-ui.stat-card`, `x-ui.booked-by` + `.bts-*` classes. Build new screens from these so they inherit the system. **Do not ship the default starter look.**

## Workflow
- **Work directly on `main`.** Commit and push straight to `main`; do **not** create feature branches or pull requests unless the owner explicitly asks. (`main` is the single active branch — the repo was consolidated onto it.)
- Develop locally; commit in small, clear increments; push to GitHub.
- End of each phase: report what changed, how to run + test it, seeded credentials, and any assumptions or flags.
