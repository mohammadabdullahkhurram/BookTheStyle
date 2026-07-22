# Deploying to Hostinger Cloud Startup

Managed hosting: SSH + cron available, **no supervisor** (no always-on queue
worker), **no Node** (assets are built locally and committed), PHP-FPM behind
Hostinger's TLS-terminating proxy (fronted by Cloudflare), web root is a
`public_html` folder. Salon onboarding runbook: `docs/OPERATIONS.md`.
Database backup/restore: `docs/BACKUPS.md`.

## Layout

Keep the project OUTSIDE the web root; only `public/` is served.

```
~/bookthestyle          ← git clone (private: .env, vendor, storage, app)
~/domains/bookthestyle.com/public_html  ← must serve ~/bookthestyle/public
```

Preferred: point the domain's document root at `~/bookthestyle/public`
(hPanel → Websites → dashboard → change document root), or replace
`public_html` with a symlink:

```sh
rm -rf ~/domains/bookthestyle.com/public_html
ln -s ~/bookthestyle/public ~/domains/bookthestyle.com/public_html
```

DNS: the app needs the apex + wildcard (`*.bookthestyle.com` → same server)
because salons live on subdomains and the app on `app.bookthestyle.com`.

**Hostnames are hand-created, never runtime-minted.** The DNS wildcard makes
any label resolve, but the ORIGIN only holds certificates for subdomains a
human created in hPanel (wildcard origin SSL is VPS-only on this plan), and
Cloudflare runs Full (strict) — so a hostname the app invents at runtime
answers **525 (SSL handshake failed)** for every visitor. Every served
hostname — apex, `app.`, `register.`, `demo.`, and each salon's slug — must
exist in hPanel BEFORE anything links to it (salon subdomains are part of the
onboarding runbook, `docs/OPERATIONS.md`). Application code must never
generate a subdomain; `tests/Feature/Demo/HostnameGuardTest.php` fails the
build if a route appears on a host outside the hand-created set. The public
demo therefore runs entirely on `app.` (entry `/demo`) + the static `demo.`
host, with the visitor's salon resolved from the session — never from a
hostname.

## First deploy

```sh
git clone git@github.com:mohammadabdullahkhurram/BookTheStyle.git ~/bookthestyle
cd ~/bookthestyle
composer install --no-dev --optimize-autoloader
cp .env.example .env       # then set the PRODUCTION VALUES block (top of file)
php artisan key:generate
php artisan migrate --force          # the ONLY schema command — never fresh/refresh/wipe
php artisan app:factory-reset --force   # pristine start: ONE agency owner; a strong
                                        # random password prints ONCE and must be
                                        # changed at first login
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## The one cron line

Everything scheduled — the database **queue worker** (drained every minute:
`queue:work --stop-when-empty --max-time=55 --tries=3`, no supervisor
needed), `bookings:close-elapsed`, `ghl:reconcile` — runs off a single line
(hPanel → Advanced → Cron Jobs, every minute):

```
* * * * * cd ~/bookthestyle && php artisan schedule:run >> /dev/null 2>&1
```

GHL syncs therefore land within ~1 minute of the action that queued them —
expected and fine.

## Updating (every release)

Assets are built **locally** and committed (the server has no Node):

```sh
# locally
npm run build && git add public/build && git commit -m "build" && git push

# on the server
cd ~/bookthestyle
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

## Rollback

```sh
cd ~/bookthestyle
php artisan down
git reset --hard <last-good-sha>     # assets included — they're committed
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
# migrations are additive/reversible; only if a bad one must come out:
# php artisan migrate:rollback --step=1 --force
```

## Cloudflare (in front of the origin)

Traffic flows client → Cloudflare → Hostinger origin; the app never
terminates public TLS itself.

- SSL/TLS mode **Full (strict)** — the origin must hold a valid certificate.
- Proxy trust: `TRUSTED_PROXIES` (default `*`) + the real visitor IP from
  `CF-Connecting-IP`, so `$request->ip()`, HTTPS detection, and every per-IP
  rate limit work correctly at the edge. To be stricter, pin
  `TRUSTED_PROXIES` to Cloudflare's published ranges
  (https://www.cloudflare.com/ips/) — the trade-off is keeping that list
  current. Keep the origin closed to non-Cloudflare traffic either way.
- **WAF / Bot Fight / challenge rules must SKIP these paths** — they are
  fetched by machines, and a challenge page breaks them:
  - `/webhooks/ghl` — the GHL workflow webhook
  - `/api/v1/booking/*` — the GHL voice-AI custom actions
  - `/api/widget/*` on every `{slug}` subdomain — the embedded booking
    widget's JSON (called from visitors' browsers on third-party sites)
  - `/cal/*` — calendar feed fetchers (Google/Apple/Outlook)
- Disable Rocket Loader / script-rewriting features for the app hosts —
  Livewire and the widget script must arrive byte-identical.

## Production guarantees already in code

- `TrustProxies '*'` + `URL::forceScheme('https')` in production: correct
  scheme/IP behind the proxy; every UI-shown URL (webhook, widget embed,
  voice API, calendar feed) derives from `APP_URL`/`APP_DOMAIN`.
- `DB::prohibitDestructiveCommands()` is armed in production — the
  destructive schema commands (fresh / refresh / wipe) refuse to run there
  at all.
- `APP_DEBUG=false` + `LOG_LEVEL=warning`: errors are logged
  (`storage/logs`), never displayed; tokens/PII are never logged.
- `SESSION_SECURE_COOKIE=true` (with the `.bookthestyle.com` cookie domain)
  keeps the session HTTPS-only across salon subdomains.
- Public endpoints are rate-limited per IP/token/salon (widget, voice API,
  webhook, calendar feed).

## Troubleshooting

| Symptom | Likely cause → fix |
|---|---|
| Config/route changes not taking effect | Caches are stale — `php artisan config:cache && php artisan route:cache && php artisan view:cache` after every pull |
| GHL syncs / emails not happening | The cron isn't running — check hPanel → Cron Jobs; `php artisan schedule:run` by hand and watch output; inspect `jobs` / `failed_jobs` tables |
| Styles/JS look stale after deploy | Assets are committed — the LOCAL build step was skipped before push (`npm run build` locally → commit `public/build` → push → pull) |
| `Unknown column` on `migrate --force` | A migration references a column not yet in history — CI's `mysql-migrations` job + `MigrationOrderTest` catch this class; fix the migration, never reorder ones already run |
| Every visitor rate-limited / none are | Proxy trust broken — verify Cloudflare proxying is on and `CF-Connecting-IP` reaches the origin; see `TrustCloudflareClientIp` |
| Voice AI / webhook suddenly failing with challenge pages | A Cloudflare WAF/bot rule stopped skipping the machine paths (list above) |
| `http://` URLs appearing anywhere | `APP_ENV` isn't `production` or `APP_URL` isn't https in the server `.env` |
| Errors after a rollback | `composer install --no-dev` + the three cache commands must re-run after `git reset` |

Logs: `storage/logs/` on the server (`LOG_CHANNEL=daily` recommended so they rotate).
