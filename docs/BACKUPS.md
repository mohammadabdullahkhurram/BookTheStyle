# Backups & restore

Two different things live here: **production data backups** (the thing that matters now) and **git restore points** (code snapshots from the build).

## Production database backup

The database is the only state that can't be rebuilt from the repo (code + built assets are all in git; uploaded media lives under `storage/` and `public/how-to-documentation/`).

**Backup (on the server, or from cron):**

```sh
mysqldump --single-transaction --routines \
  -u <DB_USERNAME> -p<DB_PASSWORD> <DB_DATABASE> \
  | gzip > ~/backups/bookthestyle-$(date +%F).sql.gz
```

Recommended: a daily cron line for the dump + a retention sweep (`find ~/backups -name '*.sql.gz' -mtime +14 -delete`), and periodically copy a dump **off the server**. Status: not yet automated — tracked in STATUS-and-ROADMAP.

**Restore (drill this before you need it):**

```sh
php artisan down
gunzip < ~/backups/bookthestyle-<date>.sql.gz | mysql -u <DB_USERNAME> -p <DB_DATABASE>
php artisan migrate --force        # replays anything newer than the dump — additive only
php artisan up
```

Also back up the server's `.env` (it holds APP_KEY — **without APP_KEY, encrypted GHL tokens in the dump are unrecoverable**) — store a copy somewhere safe off-server.

## Git restore points (code only)

Frozen tags/branches from the build. **They are NOT deploy rollbacks** — production rollback is `git reset --hard <last-good-sha>` per `docs/DEPLOY.md`, and **never force-push `main`** now that production pulls from it. These exist to inspect or salvage old code (`git checkout <tag>`, or branch from it).

| Restore point (tag = branch) | Commit | State captured |
|---|---|---|
| `pre-prelaunch-v1` / `backup-phases-0-6-complete` | `cb1f3e3` | Core build complete (tenancy → GHL sync) |
| `v1.0-prelaunch-complete` / `backup-v1-prelaunch-complete` | `903b295` | + pre-launch features (prices, profiles, reports) |
| `v1.1-preflight` / `backup-v1.1-preflight` | `3f57c7a` | + clients directory, voice-AI API, contact sync |
| `v1.2-oldui-final` / `backup-oldui-final` | `ee74c42` | + wizard, widget — the OLD UI, preserved |
| `v1.3-functional-complete` / `backup-functional-complete` | `c79c084` | + all functional/a11y batches, before the visual refresh |

Never commit to a backup branch; all work continues on `main`. Add a row here if a new checkpoint is ever cut.
