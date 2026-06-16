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

## Architecture conventions
- Tenancy tables: `agencies`, `salons`, `users`, `salon_memberships`. Salon-scoped models use a `BelongsToSalon` trait + global scope; a `ResolveSalon` middleware sets the active salon (route/session) and enforces membership.
- RBAC via PHP enums: salon roles (`salon_owner` | `salon_admin` | `user`) + `staff_type` (`stylist` | `front_desk`); agency roles (`agency_owner` | `agency_admin` | `agency_user`). Enforce with Gates/Policies. (No spatie/permission unless approved.)
- Business logic in service/action classes, not controllers or Livewire components. Keep components thin.
- Migrations are the schema source of truth; everything reversible.

## Design tokens (light mode — anti-slop; enforce everywhere)
Map Tailwind 4 `@theme` **and** the Flux theme to these. The accent is **per-salon brandable** (salons can override it later), so keep it a single variable.

- Fonts: headings **Fraunces** (serif), UI/body **Inter** (both Google Fonts). Optional mono: JetBrains Mono.
- Colors: page bg `#FAF8F5` · card `#FFFFFF` · muted surface `#F2EEE8` · ink `#1C1B19` · secondary text `#6B6660` · border `#E7E1D8` · accent `#1F6F6B` · accent-hover `#185A56` · accent-soft `#E3F0EE`.
- Status: arrived/success `#2F855A` · late/warning `#B7791F` · no-show/danger `#B23A2E` · info `#2B6CB0` (keep muted/earthy, never neon).
- Radii 6 / 10 / 16px · subtle layered low-opacity shadows · 4px spacing scale.
- No emoji in UI. Generous whitespace. Thin borders + soft shadows, never heavy gradients. **Do not ship the default starter look — customize to these tokens.**

## Workflow
- **Work directly on `main`.** Commit and push straight to `main`; do **not** create feature branches or pull requests unless the owner explicitly asks. (`main` is the single active branch — the repo was consolidated onto it.)
- Develop locally; commit in small, clear increments; push to GitHub.
- End of each phase: report what changed, how to run + test it, seeded credentials, and any assumptions or flags.
