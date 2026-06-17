# BookTheStyle
A multi-tenant booking platform for hair/beauty salons, operated by an agency (one agency account) that manages many salons (sub-accounts). Each salon runs its own bookings, stylists, services, and its own GoHighLevel (GHL) sub-account integration.

## Tenancy is subdomain-based

Each salon is served from its own subdomain: `{slug}.bookthestyle.com` in production. The active salon is resolved from the request **Host** (the subdomain), and central/system pages (marketing, login/logout, account settings, the agency console) live on the apex domain `bookthestyle.com`. See `SPEC.md` §3.1 for the full decision.

## Local development

Modern browsers route any `*.localhost` host to `127.0.0.1` automatically — **no `/etc/hosts` edits and no extra tooling needed.**

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # SQLite is fine locally: set DB_CONNECTION=sqlite
npm install && npm run dev     # asset bundling / HMR
php artisan serve              # serves on http://localhost:8000
```

The relevant `.env` values (already in `.env.example`):

```dotenv
APP_URL=http://localhost:8000
APP_DOMAIN=localhost            # central/apex domain; salons live at {slug}.APP_DOMAIN
SESSION_DOMAIN=.localhost       # leading dot → the login session is shared across subdomains
```

### URLs

| Area | URL |
|---|---|
| Marketing / landing | `http://localhost:8000/` |
| Login (all auth) | `http://localhost:8000/login` |
| Salon picker (after login) | `http://localhost:8000/dashboard` |
| Agency console | `http://localhost:8000/agency` |
| Account settings | `http://localhost:8000/settings/profile` |
| **Demo Salon** dashboard | `http://demo.localhost:8000/` |
| Demo Salon appointments / book / clients / staff / services / availability / settings | `http://demo.localhost:8000/{appointments,book,clients,staff,services,availability,settings}` |
| **Other Salon** (tenant-isolation check) | `http://other.localhost:8000/` |

Generated links already include the `:8000` port (taken from `APP_URL`), so navigating between the apex and a salon subdomain just works.

### Seeded accounts (`php artisan db:seed`)

All passwords are `password` unless noted. Slugs: **Demo Salon → `demo`**, **Other Salon → `other`**.

| Email | Role | Reaches |
|---|---|---|
| `agency@bookthestyle.test` | Agency Owner | every salon + agency console |
| `admin@bookthestyle.test` | Agency Admin | every salon + agency console |
| `user@bookthestyle.test` | Agency User | Demo Salon only |
| `owner@demo-salon.test` | Salon Owner | `demo.localhost:8000` |
| `frontdesk@demo-salon.test` | Front Desk | `demo.localhost:8000` |
| `stylist@demo-salon.test` | Stylist | `demo.localhost:8000` |
| `newhire@demo-salon.test` | Stylist (temp password `temporary`, forced change) | `demo.localhost:8000` |
| `owner@other-salon.test` | Salon Owner (different agency) | `other.localhost:8000` |

Log in as a Demo Salon user and open `http://other.localhost:8000/` to confirm tenant isolation returns **403**.
