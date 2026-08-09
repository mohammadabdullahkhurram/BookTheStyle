# Repository reorganization plan

_Written 2026-08-07. Audit of the full tree (excluding `vendor/`, `node_modules/`).
**Only Tier 1 may be executed without staging.** Tiers 2–3 are plans and
recommendations: the app is live, two recent outages came directly from
structure/route/view changes, and no staging environment exists yet. Every
Tier 2 item below records its blast radius — what resolves the path — so the
work can be sequenced safely later._

**Prerequisite for any Tier 2 execution:** a staging environment (same host
shape: subdomain tenancy, cached routes/config/views, opcache) plus the
verification recipe in the appendix.

---

## Inventory at a glance

| Area | Contents | State |
|---|---|---|
| Root | `README.md`, `CLAUDE.md`, `SPEC.md`, `DESIGN-TOKENS.md` + tooling configs (`composer.json`, `phpstan.neon`, `pint.json`, `phpunit.xml`, `vite.config.js`, `.gitguardian.yaml`, …) | Clean — every file is a charter doc or a fixed-path tool file |
| `app/` (175 PHP files) | Actions / Enums / Http / Jobs / Models / Policies / Services / Support (27 files) / Console | Conventional; a few grab-bag and size issues (Tier 2.6) |
| `routes/` | `web.php` (226 lines, all four hosts), `settings.php`, `console.php` | Works, but one file carries four host groups (Tier 2.1) |
| `resources/views/` (94 blade files) | `pages/` (Volt SFCs), `components/ui/`, `layouts/`, `partials/`, mail | Three oversized SFCs (Tier 2.2–2.3) |
| `resources/views/docs/` | The in-app agency Documentation system (native Blade pages on the `x-docs.*` kit; the earlier markdown pipeline was removed) | Working; overlaps *in name only* with the help-video system (Tier 2.4) |
| `public/how-to-documentation/` | In-app help VIDEOS, registry in `config/help.php` | Served content — public path, do not move casually (Tier 2.4) |
| `video/` | A complete, self-contained Remotion film project (40 tracked files + its own local `node_modules`) | Belongs out of this repo (Tier 2.5) |
| `scripts/` + `docs/launch-video/` | The screenshot-capture harness (deliberately maintained) | Alive — see Tier 3 |
| `docs/` | ARCHITECTURE, DEPLOY, OPERATIONS, BACKUPS, STATUS-and-ROADMAP, UI-UX-AUDIT, launch-video, this plan | Consolidated by the 2026-08-06 hygiene pass |

---

## Tier 1 — Zero-risk doc moves (executable now)

**Finding: none are executable.** The 2026-08-06 hygiene pass already
consolidated everything loose; each remaining root doc is pinned in place by
a code/framework/tooling reference, so under the "any reference → plan, not
move" rule they all stay:

| File | Proposed destination | Why it must NOT move (the reference) |
|---|---|---|
| `README.md` | root (stays) | Repo-root convention; explicitly required to stay |
| `CLAUDE.md` | root (stays) | Read by AI tooling at this fixed path every session |
| `SPEC.md` | `docs/SPEC.md` (deferred) | `CLAUDE.md` instructs reading it at root; dozens of `SPEC §n` code-comment references assume the known name |
| `DESIGN-TOKENS.md` | `docs/DESIGN-TOKENS.md` (deferred) | `CLAUDE.md` names it authoritative at root; referenced from `resources/css/app.css` and `config/mail.php` comments |
| `video/SCRIPT.md` | with the video project | Part of the `video/` project — moves with Tier 2.5, not alone |
| `public/*/README.md` (2) | in place | In-folder documentation for served public asset directories |

If `SPEC.md`/`DESIGN-TOKENS.md` are ever moved, it is still near-zero-risk —
but it requires editing `CLAUDE.md` + `README.md` + the two comment
references in the same commit, so it is deferred rather than silently done
here.

---

## Tier 2 — Structural changes (PLAN ONLY — staged execution later)

### 2.1 Split `routes/web.php` by host

**Now:** one 226-line file interleaves four host groups (apex marketing,
`register.`, `app.` — auth/agency/feeds/webhooks/voice API — and the `{slug}`
tenant wildcard) plus a local-only capture-login block.

**Proposal:** `routes/web/` with one file per host — `marketing.php`,
`register.php`, `app.php`, `tenant.php`, `system.php` (feeds/webhooks/api) —
required from a slim `web.php`, preserving today's registration ORDER
(app-host groups must register before the tenant wildcard, or `app.` resolves
as a salon named "app").

**Blast radius:**
- `bootstrap/app.php` route registration (if switched from `web.php` include
  to per-file loading).
- **`route:cache`** — this is the exact class of change that took production
  down before; the cached route order must be diffed (`route:list`) pre/post.
- `tests/Feature/Demo/HostnameGuardTest.php` pins the route-host allowlist —
  it must stay green, and is the safety net for ordering mistakes.
- Route NAMES must not change (26 `pages::` registrations, `route(...)` calls
  across ~90 views, `wire:navigate` links) — this split moves lines only.

### 2.2 Pages/views layout + the oversized SFCs

**Now:** `resources/views/pages/**` Volt SFCs are resolved by string
(`Route::livewire('users', 'pages::salon.users.index')`). Two files still
carry too much: `salon/users/index.blade.php` (~850 lines) and
`agency/salons/edit.blade.php` (~650).

**✅ Done (2026-08-08): Settings.** `salon/settings.blade.php` was shrunk in
place exactly per this recipe — tab bodies extracted to scope-sharing
partials under `resources/views/partials/settings/` (deliberately NOT a
`pages/salon/settings/` directory, which would shadow the component name),
and the Integrations tab reorganized into a guided five-step flow. Route
and view name byte-identical; the same recipe remains for Users (2.3) and
the agency salon editor (profile / ownership / GHL cards).

**Proposal (remaining):** do NOT flatten or rename the pages tree (names are
load-bearing everywhere); shrink the remaining big SFCs in place.

**Blast radius for ANY page rename:** the 26 route strings in `routes/`,
**63 test files** referencing `pages::…`, and Blade `@include`/component
references. A rename is never zero-risk; extraction of partials WITHIN a page
is the low-risk variant (only the page file + new partial files change).

### 2.3 Split the Users page (852 lines) — the concrete plan

`resources/views/pages/salon/users/index.blade.php` holds: invite flow, edit
modal, member-details modal (+ shared-account guard), owner-transfer flow,
takes-bookings switches, temp-password panel, and two list layouts.

**Proposed split (keeps the page name and route untouched):**
1. Extract the five modals into `pages/salon/users/partials/`:
   `add-modal`, `edit-modal`, `details-modal`, `transfer-modal`,
   `temp-password-modal` (mirrors the existing `partials/row-actions.blade.php`
   precedent — pure `@include`s, zero resolution risk).
2. Move the PHP orchestration into two Livewire **Form objects**
   (`MemberEditForm`, `OwnerTransferForm`) or a backing class — new classes,
   additive autoload, no route/view rename.
3. The table/cards markup stays; the SFC drops to ~200 lines of wiring.

**Blast radius:** the page file itself, the new partials, and the **10 test
files** that call `pages::salon.users.index` methods/properties by name —
public property/method names (`startEdit`, `saveOwnerDetails`,
`transferChoice`, …) are pinned by tests and must survive the split (Form
objects change property paths: `$form.editRole` — tests and `wire:model`
strings update together, which is exactly why this is staged, not done now).

### 2.4 Consolidate the "two doc systems" (naming, not merging)

**Now:** two systems that overlap in name only:
- `resources/views/docs/` → the agency **Documentation tab** (native Blade
  pages on the `x-docs.*` kit, `App\Support\AgencyDocs` registry; the
  markdown + `public/docs-assets/` pipeline was removed when the docs went
  native).
- `public/how-to-documentation/` → in-app contextual **help videos**
  (`config/help.php` registry, `x-ui.help-trigger`/`help-modal`).

**Proposal:** they serve different audiences and should stay separate
systems; the confusion is the *names*. Rename
`public/how-to-documentation/` → `public/help-videos/` and update
`config/help.php` in the same commit. Optionally later: fold
`public/docs-assets/` under a common `public/docs/` umbrella.

**Blast radius:** `config/help.php` URLs, any absolute URLs already shared or
cached by browsers/CDN (Cloudflare cache for served videos!), and
`public/`-path resolution — this is a PUBLIC URL change, so it needs either a
redirect story or acceptance that old links die. Cheapest safe first step
(could even be Tier 1 next pass): cross-linking READMEs in both folders
explaining the split — no path change at all.

### 2.5 Extract `video/` to its own repository

**Now:** a complete Remotion project (own `package.json`, own
`node_modules`, own `.gitignore`, `SCRIPT.md`) living at the app repo root.

**Proposal:** `git subtree split` (history preserved) into
`bookthestyle-launch-film`; remove from this repo afterwards. Nothing in the
app resolves into `video/` — verified: the only cross-references point the
*other* way (`docs/launch-video/` is the separate screenshot harness and
stays). Until then it costs repo size and contributor confusion only.

**Blast radius:** none at runtime. Cost is git history surgery and local
tooling habits — do it as its own commit with nothing else.

### 2.6 Smaller app-structure observations (for the same staged effort)

- `app/Support/` is a 27-file grab-bag (permissions, theming, tokens, docs,
  demo mode). Natural sub-namespaces exist (`Support\Permissions` already
  does this) — moving classes = namespace changes = composer autoload +
  imports across app/tests; mechanical but wide.
- `app/Http/Controllers/Dev/CaptureLoginController` is local-only by
  environment guard; fine, but belongs beside the capture harness story
  (see Tier 3) when that is resolved.
- `routes/settings.php` (27 lines) could fold into the app-host group during
  2.1 — same blast radius as 2.1.

---

## Tier 3 — Deletion candidates (recommendations ONLY — nothing deleted)

The sweep found **zero provably-dead files**. Suspects checked, with
evidence of life:

| Candidate | Evidence checked | Verdict |
|---|---|---|
| `resources/views/pages/auth/force-password.blade.php` | Not referenced by any `pages::` route — but rendered by `PasswordChangeController` via `view('pages.auth.force-password')` | **Alive** — false alarm |
| `scripts/capture-launch-assets.mjs` + `CaptureLoginController` + `docs/launch-video/` | `docs/launch-video/README.md` states the film no longer uses captures BUT the harness is deliberately maintained as the reproducible screenshot path for marketing surfaces | **Keep for now** — revisit when marketing assets are final; delete all three together or none |
| `video/` project | Superseded as "in-repo" but is the source of the shipped film | **Not deletion** — extraction (Tier 2.5) |
| `public/how-to-documentation/calendar-sync/` | Served by the live help registry (`config/help.php`) | **Alive** |
| Root tooling files (`.gitguardian.yaml`, `.npmrc`, …) | Tool-read at fixed paths | **Alive** |

Every page view resolves (26 routed + 1 controller-rendered + partials);
no unreferenced blade, class, or asset was found by the reference sweep.

---

## Appendix — sequencing + verification recipe for Tier 2

Recommended order once staging exists (each step = one deploy, fully
verified before the next):

1. **2.5 video/ extraction** (zero runtime surface — proves the process).
2. **2.3 Users-page split** (partials first — zero-risk; Form objects second).
3. **2.2 Settings + agency-editor splits** (same recipe).
4. **2.1 route split** (the highest-risk item — cached-route order).
5. **2.4 help-video rename** (public URLs — needs the redirect decision).
6. **2.6 Support/ namespacing** (wide but mechanical; last).

Per-step verification: full Pest suite + `mysql-migrations` CI job green;
`php artisan route:list` diffed against the pre-change output (names, URIs,
hosts identical); `HostnameGuardTest` green; on staging with **caches built
and opcache reset** (`docs/DEPLOY.md` sequence): login, salon dashboard,
booking create, widget fetch, webhook POST, one agency console screen; then
the same smoke on production immediately after its deploy.
