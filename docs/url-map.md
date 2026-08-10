# URL & route map

_Read-only inventory, generated 2026-08-10 from `php artisan route:list` plus a
reference census over `app/`, `resources/`, `routes/`, and `tests/`. **This
document changes nothing** — it exists so any future route cleanup can be
planned with the blast radius visible. Context: two production outages came
from route/view-name changes; there is no staging._

**How to read the reference columns:** `code` = files under
`app/ + resources/ + routes/` containing the exact route-name string
(`route('…')`, redirects, mails, checks); `tests` = test files containing it.
A rename must update every one of those **plus** re-run `route:cache` on
deploy. Livewire pages are additionally pinned by their `pages::…` component
name (census at the bottom) — renaming a **view/component name** is the other
outage class and has its own blast radius, independent of the URL.

Totals: **101 registered routes** — 85 named app routes, 16
framework/vendor-internal (Livewire assets/update, Flux assets, `/up`,
`/storage`, dev-only `_boost`). Hosts resolve off `APP_DOMAIN` (production
`bookthestyle.com`; local `lvh.me` shown here as `{domain}`).

---

## Host model & tenancy resolution

| Host | Role | Tenancy / auth resolution |
|---|---|---|
| `{domain}` (apex) | Marketing site only | None — public static views |
| `app.{domain}` | Auth, agency console, personal settings, machine endpoints (`/api/v1/booking`, `/webhooks`, `/cal`, `/widget.js`), demo entry | Session auth (Fortify). Machine endpoints are sessionless: the booking API's bearer token embeds the salon id; the webhook resolves the salon from the payload's `locationId`; the ICS feed from a hashed token |
| `register.{domain}` | Public book-a-call page | None |
| `{salon}.{domain}` | One host per salon: the tenant app + the public widget | `ResolveSalon` (Livewire **persistent** middleware) maps the slug → salon, 404s unknown/inactive/reserved slugs, 403s non-members, binds `currentSalon`; `SalonScope` global scope + policies behind it. The widget's public endpoints resolve the slug in-controller (no session, no membership) |

Reserved slugs (`api`, `cal`, `webhooks`, `app`, `register`, `demo`, …) can
never be salon names, so app-host paths can't be shadowed. Hostnames are
hand-created in hPanel — nothing mints subdomains at runtime
(`HostnameGuardTest` pins the allowlist).

---

## 1. Apex (marketing) — public, no auth

| Method | URI | Name | Handler | code | tests |
|---|---|---|---|---|---|
| GET | `/` | `home` | ViewController | 5 | 5 |
| GET | `/services` | `marketing.services` | ViewController | 4 | 1 |
| GET | `/features` | `marketing.features` | ViewController | 4 | 1 |
| GET | `/contact` | `marketing.contact` | ViewController | 2 | 1 |
| GET | `/demo` | — (unnamed) | `DemoController@redirectToEntry` → `demo.enter` | — | — |

`register.{domain}/` → `book-call` (ViewController, code 6 / tests 2).

## 2. app-host — authentication (Fortify + passkeys)

All on `app.{domain}`. Vendor-controlled names (Fortify/passkeys) — renaming
these is not realistically on the table; listed for completeness.

| Method | URI | Name | code | tests |
|---|---|---|---|---|
| GET/POST | `/login` | `login` / `login.store` | 9+1 | 17+7 |
| POST | `/logout` | `logout` | 2 | 2 |
| GET/POST | `/forgot-password` | `password.request` / `password.email` | 1+1 | 4+0 |
| GET/POST | `/reset-password[/{token}]` | `password.reset` / `password.update` | 1+1 | 1+2 |
| GET/POST | `/password/change` | `password.change` / `.update` (forced first-login change) | 2+3 | 3+3 |
| GET/POST | `/user/confirm-password`, `/user/confirmed-password-status` | `password.confirm*` | 3 | 9 |
| GET/POST | `/two-factor-challenge` + `/user/two-factor-*` (7 routes) | `two-factor.*` | ~1 | ~2 |
| GET/POST/DELETE | `/passkeys/*`, `/user/passkeys*`, `/.well-known/passkey-endpoints` | `passkey.*`, `well-known.passkeys` | ~3 | 0 |

## 3. app-host — dashboard, agency console, personal settings (auth)

| Method | URI | Name | Component (`pages::`) | code | tests |
|---|---|---|---|---|---|
| ANY | `/` | — | redirect → `/dashboard` | — | — |
| GET | `/dashboard` | `dashboard` | `dashboard` | 7 | 19 |
| GET | `/agency` | `agency.overview` | `agency.overview` | 2 | 6 |
| GET | `/agency/salons/create` | `agency.salons.create` | `agency.salons.create` | 2 | 5 |
| GET | `/agency/salons/{salon}/edit` | `agency.salons.edit` | `agency.salons.edit` | 1 | 10 |
| GET | `/agency/reports` | `agency.reports` | `agency.reports` | 2 | 3 |
| GET | `/agency/users` | `agency.users.index` | `agency.users.index` | 4 | 5 |
| GET | `/agency/users/create` | `agency.users.create` | `agency.users.create` | 1 | 2 |
| GET | `/agency/users/{user}/edit` | `agency.users.edit` | `agency.users.edit` | 1 | 1 |
| GET | `/agency/docs/{doc?}` | `agency.docs` | `agency.docs` | 2 | 2 |
| ANY | `/settings` | — | redirect → `/settings/profile` | — | — |
| GET | `/settings/profile` | `profile.edit` | `settings.profile` | 3 | 6 |
| GET | `/settings/security` | `security.edit` | `settings.security` | 2 | 2 |
| GET | `/demo` | `demo.enter` | `DemoController@enter` | 4 | 1 |
| GET | `/_capture/login` | `capture.login` | local-env only, never registered in production | 1 | 1 |

## 4. app-host — machine endpoints (sessionless)

| Method | URI | Name | Auth / throttle | code | tests |
|---|---|---|---|---|---|
| POST | `/api/v1/booking/availability` | `api.booking.availability` | `btsk` bearer · 60/min per token | 5 | 8 |
| POST | `/api/v1/booking/create` | `api.booking.create` | 〃 | 4 | 6 |
| POST | `/api/v1/booking/cancel` | `api.booking.cancel` | 〃 | 2 | 1 |
| POST | `/api/v1/booking/reschedule` | `api.booking.reschedule` | 〃 | 2 | 1 |
| POST | `/webhooks/ghl` | `webhooks.ghl` | `X-Webhook-Secret` · 120/min per IP | 4 | 9 |
| GET | `/cal/{token}.ics` | `cal.feed` | hashed token · 60/min per IP | 2 | 2 |
| GET | `/widget.js` | `widget.script` | public, cached 1h | 2 | 4 |

**External contract warning:** the four `api.booking.*` URLs are pasted into
GHL Custom Actions in every salon's sub-account, `webhooks.ghl` into every
GHL workflow, `cal.feed` into staff members' personal calendar apps, and
`widget.script` into salon websites. These URL *paths* are effectively
frozen regardless of internal naming — changing them means re-configuring
every live GHL account and embed.

## 5. Salon tenant host — staff app (auth via `AuthenticateUnlessDemo` + `ResolveSalon`)

| Method | URI | Name | Component (`pages::`) | code | tests |
|---|---|---|---|---|---|
| GET | `/` | `salon.show` | `salon.dashboard` | 6 | 20 |
| GET | `/calendar` | `salon.calendar` | `salon.calendar` | 3 | 9 |
| GET | `/appointments` | `salon.appointments` | `salon.appointments.index` | 3 | 10 |
| GET | `/appointments/all` | `salon.appointments.all` | `salon.appointments.all` | 3 | 7 |
| GET | `/book` | `salon.bookings.create` | `salon.bookings.create` | 6 | 8 |
| GET | `/clients` | `salon.clients` | `salon.clients.index` | 2 | 10 |
| GET | `/clients/{clientId}` | `salon.client` | `salon.clients.show` | 6 | 4 |
| GET | `/users` | `salon.users` | `salon.users.index` | 3 | 14 |
| ANY | `/staff` | — | 301 redirect → `/users` (legacy) | — | — |
| GET | `/services` | `salon.services` | `salon.services.index` | 3 | 10 |
| GET | `/availability` | `salon.availability` | `salon.availability.index` | 3 | 7 |
| GET | `/reports` | `salon.reports` | `salon.reports` | 2 | 7 |
| GET | `/settings` | `salon.settings` | `salon.settings` | 4 | 13 |
| GET | `/settings/check-connections` | `salon.check-connections` | `salon.check-connections` | 3 | 3 |
| GET | `/widgets` | `salon.widgets` | `salon.widgets` | 2 | 4 |
| GET | `/account` | `salon.account` | `salon.account` | 2 | 3 |
| GET | `/setup` | `salon.onboarding` | `salon.onboarding` | 4 | 3 |

## 6. Salon tenant host — public widget + authenticated preview

| Method | URI | Name | Notes | code | tests |
|---|---|---|---|---|---|
| GET | `/widget/{widget?}` | `salon.widget` | public iframe page | 3 | 6 |
| GET | `/api/widget/services` | `salon.widget.services` | public JSON · 30/min per IP+host | 2 | 3 |
| GET | `/api/widget/availability` | `salon.widget.availability` | 〃 | 2 | 2 |
| GET | `/api/widget/month` | `salon.widget.month` | 〃 | 2 | 1 |
| POST | `/api/widget/book` | `salon.widget.book` | 〃 + bot gate | 2 | 1 |
| GET | `/widgets/preview/{widget?}` | `salon.widget.preview` | staff-auth twin | 2 | 0 |
| GET | `/api/widget-preview/availability` | `salon.widget.preview.availability` | 〃 (test records visible) | 2 | 1 |
| GET | `/api/widget-preview/month` | `salon.widget.preview.month` | 〃 | 2 | 0 |
| POST | `/api/widget-preview/book` | `salon.widget.preview.book` | 〃 (never persists for real salons) | 2 | 0 |

---

## Inconsistencies (concrete findings)

1. **Route-name ↔ URI mismatch on `salon.bookings.create` → `/book`.** The
   name says `bookings.create`, the URI is `/book`, and the component is
   `salon.bookings.create` — three spellings of one thing. Same pattern:
   `salon.onboarding` → `/setup` (name ≠ path ≠ what the UI calls it, "Setup
   wizard"), and `salon.show` → the salon *dashboard* (component
   `salon.dashboard`, name `show`).

2. **Singular/plural drift in salon route names.** `salon.clients` (list) vs
   `salon.client` (detail) — the rest of the app uses `.index`-style
   components but flat names: `salon.users` has no `.index`, while the agency
   side DOES use `agency.users.index`. Two naming families for the same
   shape.

3. **Agency names are prefixed by group (`agency.users.index`), salon names
   are flat (`salon.users`).** Neither is wrong, but they disagree, so
   nobody can guess a route name without looking it up.

4. **`settings/check-connections` is a URI/name/label triple mismatch.** The
   path says `check-connections`, the page's title and nav say "Health
   check", and the salon Settings page itself hosts an "Integrations" tab —
   three vocabularies for the diagnostics surface. (The name predates the
   health-check rebuild.)

5. **`/settings` means two different things by host.** On `app.` it is
   PERSONAL settings (redirect → `settings/profile`, names `profile.edit` /
   `security.edit` — a third naming family with no `settings.` prefix); on
   `{salon}.` it is SALON settings (`salon.settings`). Deliberate (the
   settings.php comment says so) but a standing source of confusion, and the
   personal-settings route names don't mention settings at all.

6. **Widget preview paths fork the pattern.** Public: `/widget/…` +
   `/api/widget/…`. Preview: `/widgets/preview/…` (plural! under the staff
   `widgets` area) + `/api/widget-preview/…` (hyphen). Three prefixes for
   two surfaces; the plural/singular flip between `/widget` (public page)
   and `/widgets` (staff page) is easy to mistype.

7. **The `/v1/` API version segment exists on exactly one API family.** The
   voice API is `/api/v1/booking/*` but the widget API is unversioned
   `/api/widget/*`, and the webhook is `/webhooks/ghl` (no `/api`, no
   version). Documented gotcha: Custom Actions missing `/v1/` 404 — the
   inconsistency actively bites operators. (Keeping `/v1/` is right for the
   external contract; the inconsistency is that its siblings never got a
   version.)

8. **Legacy residue.** `/staff` 301 → `/users` (the old Staff page) —
   harmless but permanent; and `Route::livewire('/', 'pages::salon.dashboard')`
   named `salon.show` is the fossil of a pre-dashboard era.

9. **Unnamed routes exist for real pages.** The apex `/demo` redirect and
   both `RedirectController` entries (`app./` → `/dashboard`, `app./settings`
   → profile) carry no names — fine for redirects, but they're invisible to
   `route()` audits.

10. **`profile.edit` / `security.edit` name pages that aren't "edit" forms**
    (they're full settings screens) — a Breeze-inherited convention nothing
    else in the app uses.

---

## Recommendation (NOT implemented — blast radius per change)

If a cleanup is ever staged, the safe order is: (a) never touch §4's
machine paths (external contract); (b) rename **route names** before URIs
(names are internal — URI changes additionally break bookmarks); (c) one
route per commit, updating every reference in the same commit, suite green
between commits; (d) full rebuild + `route:cache` + opcache reset on deploy.

| Current | Suggested | Blast radius (code files + test files + notes) |
|---|---|---|
| `salon.show` → `/` | `salon.dashboard` | 6 + 20 — biggest single rename; name-only |
| `salon.clients` / `salon.client` | `salon.clients.index` / `salon.clients.show` | 2+10 / 6+4 — aligns with agency family |
| `salon.users` | `salon.users.index` | 3 + 14 |
| `salon.bookings.create` → `/book` | keep URI, name `salon.book` — or accept the mismatch | 6 + 8; URI change would also touch nav links |
| `salon.check-connections` → `settings/check-connections` | name `salon.health`, URI `settings/health-check` | 3 + 3 + the health-alert mail link; URI change is user-visible |
| `profile.edit` / `security.edit` | `settings.profile` / `settings.security` | 3+6 / 2+2 — matches their component names exactly |
| `salon.widget.preview*` paths | unify under `/widget-preview/*` | 8 files total, tests thin — lowest-risk URI fix |
| `/staff` redirect | drop after access logs show zero hits | trivial |
| Apex `/demo` redirect | name it (`demo.redirect`) | trivial, additive |

**Not recommended:** renaming any `pages::` component name. The census below
shows why — `salon.users.index` alone appears in 68 places, `salon.settings`
in 58. Component renames were the second outage class; they buy nothing
user-visible.

### `pages::` component-name census (mentions across routes/ + tests/)

`salon.users.index` 68 · `salon.settings` 58 · `salon.availability.index` 34 ·
`salon.calendar` 22 · `salon.onboarding` 21 · `agency.salons.create` 19 ·
`salon.appointments.all` 19 · `salon.appointments.index` 18 ·
`salon.bookings.create` 18 · `salon.services.index` 18 ·
`salon.clients.index` 17 · `settings.calendar-feed` 16 ·
`agency.salons.edit` 14 · `salon.check-connections` 13 · `salon.widgets` 11 ·
`salon.reports` 6 · `salon.clients.show` 5 · `settings.profile` 5 ·
`settings.delete-user-modal` 5 · `agency.users.index` 4 ·
`settings.security` 4 · `dashboard` 3 · `salon.dashboard` 3 · the rest ≤1.

_66 test files reference a `pages::` name; 27 `pages::` strings are
registered in `routes/`. Regenerate this document after any route work:
`php artisan route:list --json` + a grep census, or just re-run the
inventory prompt._
