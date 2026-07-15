# Deploying to Hostinger Cloud Startup

Managed hosting: SSH + cron available, **no supervisor** (no always-on queue
worker), **no Node** (assets are built locally and committed), PHP-FPM behind
Hostinger's TLS-terminating proxy, web root is a `public_html` folder.

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
