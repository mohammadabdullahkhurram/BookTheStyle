# BookTheStyle
A multi-tenant booking platform for hair/beauty salons, operated by an agency (one agency account) that manages many salons (sub-accounts). Each salon runs its own bookings, stylists, services, and its own GoHighLevel (GHL) sub-account integration.

## Tenancy is subdomain-based

Each salon is served from its own subdomain: `{slug}.bookthestyle.com` in production. The active salon is resolved from the request **Host** (the subdomain), and central/system pages (marketing, login/logout, account settings, the agency console) live on the apex domain `bookthestyle.com`. See `SPEC.md` §3.1 for the full decision.

## Local development

Local dev uses **`lvh.me`** — a registrable domain whose wildcard DNS resolves `lvh.me` and every `*.lvh.me` to `127.0.0.1`. **No `/etc/hosts` edits and no extra tooling needed.** We use it instead of `*.localhost` because browsers refuse to set a `Domain` cookie for `localhost`/`*.localhost`, so the login session can't be shared from the apex to a salon subdomain there; `lvh.me` is a normal registrable domain, so the shared session works like production. (`localtest.me` is an equivalent fallback.)

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # SQLite is fine locally: set DB_CONNECTION=sqlite
npm install && npm run dev     # asset bundling / HMR
php artisan serve              # serves on http://lvh.me:8000
```

The relevant `.env` values (already in `.env.example`):

```dotenv
APP_URL=http://lvh.me:8000
APP_DOMAIN=lvh.me               # central/apex domain; salons live at {slug}.APP_DOMAIN
SESSION_DOMAIN=.lvh.me          # leading dot → the login session is shared across subdomains
```

### URLs

| Area | URL |
|---|---|
| Marketing / landing | `http://lvh.me:8000/` |
| Login (all auth) | `http://lvh.me:8000/login` |
| Salon picker (after login) | `http://lvh.me:8000/dashboard` |
| Agency console | `http://lvh.me:8000/agency` |
| Account settings | `http://lvh.me:8000/settings/profile` |
| **Demo Salon** dashboard | `http://demo.lvh.me:8000/` |
| Demo Salon appointments / book / clients / staff / services / availability / settings | `http://demo.lvh.me:8000/{appointments,book,clients,staff,services,availability,settings}` |
| **Other Salon** (tenant-isolation check) | `http://other.lvh.me:8000/` |

Log in at `http://lvh.me:8000/login`, then open `http://demo.lvh.me:8000/` — you stay logged in (the session cookie is scoped to `.lvh.me`). Generated links already include the `:8000` port (taken from `APP_URL`).

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
