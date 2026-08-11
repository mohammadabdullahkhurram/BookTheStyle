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
needed), `bookings:close-elapsed`, `ghl:reconcile`, the hourly read-only
`health:monitor` (records history, emails agency admins on green→red),
`diagnostics:sweep-test-records` (TTL cleanup of the health check's
disposable records), the demo sweep/reset, and the nightly prunes — runs
off a single line (hPanel → Advanced → Cron Jobs, every minute):

```
* * * * * cd ~/bookthestyle && php artisan schedule:run >> /dev/null 2>&1
```

GHL syncs therefore land within ~1 minute of the action that queued them —
expected and fine. **Login-critical mail does not ride this queue**: password
resets, temporary passwords, invites, account-created mail, and health
alerts all send synchronously in the request/command that triggers them, so
they arrive in seconds regardless of queue state. Only GHL syncs and the
courtesy salon-added mail are queued.

**Stall self-healing:** every scheduled job passes an explicit
`withoutOverlapping(N)` expiry. Without one, Laravel's overlap mutex lives
24 hours — if the host hard-kills a run (CloudLinux process killer, OOM, a
mid-deploy restart) the lock is never released and the scheduler silently
skips that job on every following tick while cron itself looks healthy;
this once left queued mail sitting for hours. With the expiry, a stale
queue-worker lock clears within 10 minutes (2 hours for the hourly/daily
jobs), so a stall is bounded, not open-ended. The health check's Queue
check flags a backlog older than 10 minutes.

## Updating (every release)

Assets are built **locally** and committed (the server has no Node):

```sh
# locally
npm run build && git add public/build && git commit -m "build" && git push

# on the server — the FULL sequence, every time, in this order
cd ~/bookthestyle
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
# opcache reset — MANDATORY, see the next section
php artisan migrate --force
php artisan up
```

**Never trim this sequence.** Both recent production outages were deploy-step
omissions: a skipped `route:cache` has taken the site down, and a skipped
opcache reset leaves the web process executing STALE compiled PHP no matter
what was pulled or cached. Clear-then-cache (not cache alone) so nothing
half-stale survives the rebuild.

## Opcache reset — mandatory every deploy

PHP-FPM keeps compiled PHP in opcache; on this hosting a `git pull` does
**not** reliably invalidate it, and the three artisan cache commands don't
touch it either. The symptom is always the same: "I deployed but nothing
changed" — or worse, new views calling old classes. Reset it EVERY deploy,
one of:

```sh
# Option A — one-off reset file, curled once over the app host, then deleted
echo '<?php opcache_reset(); echo "opcache reset\n";' > ~/bookthestyle/public/opcache-reset-once.php
curl -s https://app.bookthestyle.com/opcache-reset-once.php
rm ~/bookthestyle/public/opcache-reset-once.php   # NEVER leave this in place
```

Option B — restart PHP from hPanel (Websites → dashboard → PHP configuration
→ restart / change-and-revert a setting), which recycles FPM and empties
opcache with it.

## Migration rules (hard-won, non-negotiable)

- **Additive only.** `php artisan migrate --force` is the ONLY schema command
  run in production. Never the destructive fresh / refresh / wipe variants —
  production refuses them outright (`DB::prohibitDestructiveCommands()`),
  and they must not appear in scripts or docs either (this guide is
  test-pinned to never spell them out).
- **MySQL-safe.** SQLite (local/CI tests) silently ignores what MySQL
  rejects: never `->after()` a column created later in the sequence, and
  keep data changes additive/backfill-safe. CI's `mysql-migrations` job +
  `MigrationOrderTest` guard this class — but write them right to begin with.
- **No runtime subdomain minting** (see the hostname section above): a
  deploy never creates hostnames; a human does, in hPanel, first.

## Rollback

```sh
cd ~/bookthestyle
php artisan down
git reset --hard <last-good-sha>     # assets included — they're committed
composer install --no-dev --optimize-autoloader
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache
# opcache reset — same as a forward deploy (section above)
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
  - `/api/v1/booking/*` — the GHL voice-AI custom actions (availability,
    create, cancel, reschedule)
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
| "I deployed but nothing changed" / new views hitting old code | **Opcache is stale** — reset it (section above); the artisan cache commands alone never clear it |
| Config/route changes not taking effect | Caches are stale — run the full clear-then-cache sequence after every pull, then reset opcache |
| GHL syncs / queued mail not happening | The cron isn't running (check hPanel → Cron Jobs; run `php artisan schedule:run` by hand and watch output) **or** a stale `queue:work` overlap mutex is skipping the drain — it now self-clears within 10 minutes; `php artisan queue:work --stop-when-empty` by hand drains immediately; inspect `jobs` / `failed_jobs` tables |
| Password-reset / invite emails slow or missing | These send synchronously and never touch the queue — if they're slow or absent the problem is the SMTP transport itself: check `MAIL_*` in `.env` and `laravel.log` for transport errors |
| Styles/JS look stale after deploy | Assets are committed — the LOCAL build step was skipped before push (`npm run build` locally → commit `public/build` → push → pull) |
| `Unknown column` on `migrate --force` | A migration references a column not yet in history — CI's `mysql-migrations` job + `MigrationOrderTest` catch this class; fix the migration, never reorder ones already run |
| Every visitor rate-limited / none are | Proxy trust broken — verify Cloudflare proxying is on and `CF-Connecting-IP` reaches the origin; see `TrustCloudflareClientIp` |
| Voice AI / webhook suddenly failing with challenge pages | A Cloudflare WAF/bot rule stopped skipping the machine paths (list above) |
| Voice AI custom action 404s on every call | The action's URL is missing the **`/v1/`** segment — the full path is `https://app.bookthestyle.com/api/v1/booking/{availability\|create\|cancel\|reschedule}`; `/api/booking/…` does not exist |
| `http://` URLs appearing anywhere | `APP_ENV` isn't `production` or `APP_URL` isn't https in the server `.env` |
| Errors after a rollback | `composer install --no-dev` + the full clear/cache sequence + an opcache reset must re-run after `git reset` |

Logs: `storage/logs/` on the server (`LOG_CHANNEL=daily` recommended so they rotate).
