# CLAUDE.md — BookTheStyle

Operating rules for Claude Code on this repo. **The app is LIVE in production** (bookthestyle.com — Hostinger Cloud, MySQL, Cloudflare-fronted; `docs/DEPLOY.md`). Read this and `SPEC.md` before doing any work; `docs/ARCHITECTURE.md` holds the GHL sync design and wire quirks — do not re-derive or "simplify" those. If a prompt conflicts with these rules, stop and ask.

## Project
BookTheStyle — a multi-tenant salon booking platform. One **agency** operates many **salons** (sub-accounts). Each salon has services → qualifying stylists → app-managed availability → bookings that roll up into one master calendar, mirror to GoHighLevel (reminders + voice AI + chat), and fan out to staff personal calendars via ICS feeds. **Scheduling only — no payments.** Domain rules: `SPEC.md`.

## Stack (do not deviate without asking)
- PHP 8.4+ · **Laravel 13** (pinned `13.15.0` — 13.16.0 breaks artisan) · **Livewire 4** (+ Volt SFC pages) · **Tailwind 4** · Alpine (bundled) · **Flux UI — FREE components only**.
- Auth: Laravel built-in (**Fortify**). DB: **MySQL** in production; SQLite for local dev/tests.
- Calendar UI: bespoke Livewire views. Never FullCalendar Scheduler/resource views (paid/GPL).
- Tooling: Pest, Pint, Larastan level 7. CI runs the suite on SQLite **plus** a from-scratch MySQL migration job.
- Verify current stable versions before installing (web search / Packagist is fine).

## Golden rules (non-negotiable)
1. **Tenant isolation is sacred.** Every salon-scoped query filters by `salon_id` + verified membership. **No IDOR** — never trust an ID from the request without an ownership/membership check. Agency cross-salon access only via explicit assignment.
2. **Ask before adding ANY dependency** beyond the stack above.
3. **Never commit secrets.** Keys/tokens live in `.env` (gitignored); keep `.env.example` current; **encrypt GHL tokens at rest** in the DB.
4. **Flux FREE only.** If a needed component is Flux Pro (paid), build it with plain Tailwind instead. No paid licenses, ever.
5. **Write tests** with every change; always include at least one tenant-isolation test; run the suite before declaring done.
6. **Production is real.** Changes ship to a live system with real data. No destructive data operations, no breaking the deploy contract (committed assets, additive migrations, the one cron line).
7. **GHL:** you may web-search current GHL API docs, but verify against live docs and surface assumptions — never hardcode unverified endpoints. The wire quirks in `docs/ARCHITECTURE.md` §2 are hard-earned; never regress them.
8. **You may commit and push to GitHub. You may NOT deploy** — server pulls are manual and triggered by the owner.
9. **No public self-registration.** Staff are created by admins: invite → temporary password → forced change on first login.
10. **Never mint hostnames at runtime.** The origin (Hostinger shared) holds certificates ONLY for subdomains a human created in hPanel — wildcard origin SSL is VPS-only — and Cloudflare runs Full (strict), so a code-generated subdomain answers 525 for every visitor (this shipped once: the demo minted per-visitor hosts and was unreachable). New hostnames come from a human in hPanel, never from code; anything per-visitor or dynamic lives on an existing host, path- or session-scoped (the demo resolves from the session on the static `demo.` host). `HostnameGuardTest` pins the route-host allowlist — extend it only for a host a human already created.
11. **Never reset the database.** `migrate:fresh`, `migrate:refresh`, `db:wipe` and any destructive reset are FORBIDDEN in prompts, scripts, docs and workflows — only additive `php artisan migrate`. Production actively refuses destructive commands, and CI guards migration ordering against MySQL. Data changes in migrations are additive/backfill-safe. Demo data: `php artisan db:seed --class=DemoSalonSeeder` (additive, idempotent, refuses to run in production).

## Architecture conventions
- Tenancy tables: `agencies`, `salons`, `users`, `salon_memberships`. Salon-scoped models use `BelongsToSalon` + a global scope; `ResolveSalon` middleware resolves the active salon from the request host and enforces membership.
- RBAC via PHP enums (salon roles + staff types; agency roles), enforced with Gates/Policies. No spatie/permission.
- Business logic in service/action classes, not controllers or Livewire components. Keep components thin.
- Migrations are the schema source of truth; everything reversible; **never `->after()` a column created later in the sequence** (SQLite ignores it, MySQL fails — `MigrationOrderTest` guards this).
- Blade/SFC pitfalls that recur: inside `<x-…>` component-tag attributes Blade never compiles `@js()` and double quotes break the tag compiler — use `{{ Js::from(__('single-quoted')) }}`; never mix block `@php…@endphp` into a file using inline `@php(...)`; no literal `</script>` in SFC views.

## Design tokens (light mode — enforce everywhere)
**`DESIGN-TOKENS.md` is the authoritative source** — exact hexes, type scale, spacing, radii, component specs; `resources/css/app.css` maps Tailwind `@theme` + Flux to it. Fraunces (headings) + Hanken Grotesk (body), self-hosted, no CDN. Themes via `App\Support\ThemeRegistry` (salon: Marble default + Classic; agency/auth: brand palette); a salon's branding accent recolors any theme via `App\Support\AccentPalette`. Build screens from the `x-ui.*` primitives (`components/ui/`) and `.bts-*` classes so they inherit the system. Sentence case; no emoji; light mode only. **Do not ship a default starter look.**

## Workflow
- **Work directly on `main`** — the single active branch. No feature branches or PRs unless the owner explicitly asks.
- Small, clear commits; push to GitHub; keep CI green (quality + tests + mysql-migrations).
- Frontend changes: `npm run build` locally and **commit `public/build`** — the server has no Node.
- Deploys are owner-triggered (`docs/DEPLOY.md`): pull → `composer install --no-dev` → `migrate --force` → re-cache.
- End of each task: report what changed, how to run/test it, and any assumptions.
