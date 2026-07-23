# Launch-video asset capture

Everything the launch film shoots comes from this harness. The screenshots
themselves are **never committed** (`assets/` is gitignored — git keeps every
binary version forever); this README + `manifest.json` + the seeder + the
capture script are the committed reproducibility record. After a clean clone,
the pipeline below is the *only* path back to the assets.

**Hard rule: capture is LOCAL ONLY.** Production holds real client PII and
must never appear in a marketing asset. The script refuses any non-local
`--base` (exit 1), the seeder and the `launch:capture` command refuse
non-local environments, and every seeded name, phone, and email is fictional
(`.test` domains, 555 numbers).

## The whole pipeline from a cold checkout

```sh
git clone git@github.com:mohammadabdullahkhurram/BookTheStyle.git && cd BookTheStyle
composer install
cp .env.example .env && php artisan key:generate   # .env defaults ARE the local config (lvh.me, sqlite)
touch database/database.sqlite                     # if it doesn't exist yet
php artisan migrate                                # additive only — NEVER migrate:fresh
php artisan storage:link                           # the seeded placeholder logo is served from the public disk
npm ci
npx playwright install chromium                    # one-time browser download
npm run capture:launch                             # seeds, boots a frozen-clock server, shoots everything
```

Output: `docs/launch-video/assets/*.png` (or `--out`), and the committed
`docs/launch-video/manifest.json` mapping every file to its storyboard beat,
viewport, dimensions, accent hex, theme, and route.

```sh
npm run capture:launch -- --out=/absolute/path/outside/the/repo   # keep binaries out entirely
```

## How local subdomain tenancy resolves (the part that trips up reruns)

The platform is split by **host**, and locally that works because `.env`
uses `lvh.me` — a public domain whose wildcard DNS resolves `lvh.me` and
`*.lvh.me` to `127.0.0.1`. No `/etc/hosts` edits, no extra tooling; you just
need to be online for the DNS lookup.

- `lvh.me:8000` — marketing apex
- `app.lvh.me:8000` — the application (login lives here; Fortify is pinned to this host)
- `marlowe-sage.lvh.me:8000` — the launch salon: `ResolveSalon` reads the
  `{slug}` **subdomain** from the Host header, loads the salon, and enforces
  membership. `php artisan serve` happily serves any Host on the port, so
  subdomains work without vhosts.
- `SESSION_DOMAIN=.lvh.me` — one login cookie shared across all of the above
  (`*.localhost` cannot do this — browsers refuse its Domain cookies; that is
  why the repo uses lvh.me).

The capture script logs in **programmatically** at
`app.lvh.me:8000/_capture/login?email=…` — a route that exists only under
`APP_ENV=local` — then rides that one session cookie across every host.

## The frozen clock (why screenshots reproduce exactly)

`LaunchSalonSeeder` pins every relative date to one anchor —
**`2026-09-15 10:20`, America/Los_Angeles (a Tuesday)** — instead of `now()`,
and the capture server runs with `APP_FAKE_NOW=2026-09-15T10:20:00-07:00` so
the app's "today" agrees with the data. `config/app.php` + AppServiceProvider
apply the freeze **only when `APP_ENV=local`**.

The script boots its own correctly-configured server if the port is free. If
you run your own instead, it must be:

```sh
APP_FAKE_NOW="2026-09-15T10:20:00-07:00" BOOKING_WIDGET_MIN_SECONDS=0 BOOKING_WIDGET_RATE_LIMIT=1000 php artisan serve
```

- `BOOKING_WIDGET_MIN_SECONDS=0` — the widget's bot gate wants the page token
  to *age* before submitting; with a frozen clock its age is pinned at zero,
  so without this the confirmation shot is impossible ("too fast", forever).
- `BOOKING_WIDGET_RATE_LIMIT=1000` — the funnel + accent beat fire more
  widget-API calls per minute than the public per-IP throttle allows.

**These are CAPTURE-ONLY overrides.** They exist solely in the environment of
the server the capture script spawns (and the manual command above); no
tracked `.env`, `.env.example`, or config default carries them.
`BOOKING_WIDGET_MIN_SECONDS=0` disables the widget's bot gate outright and
must never reach production — `LaunchCaptureTest` pins the committed defaults
(min age ≥ 4 s, rate limit ≤ 30/min) so a leaked override fails the build.
- The script verifies the freeze (the dashboard must say *Tuesday, 15
  September*) and aborts with instructions if the running server isn't frozen.
- Stop `npm run dev` first — if `public/hot` exists the app asks the Vite dev
  server for assets and captures die; the script checks and refuses.

Everything the script mutates goes through `php artisan launch:capture`
(local/testing-only): `prepare` seeds if missing, resets accent/theme to
baseline, and deletes the sentinel capture client's widget booking so every
run books the identical slot; `style --accent=… --theme=…` drives the accent
and theme beats.

## Shot list → storyboard beats

See `manifest.json` for the authoritative per-file record (route, dimensions,
hex). Summary:

| Asset | Beat |
| --- | --- |
| `widget-01-landing` … `widget-08-confirmed` (mobile 390×844@3x) | Beats 1–8: the client funnel — brand, services, multi-service visit, stylist, inline calendar with a date open, slots, half-filled details, confirmation |
| `widget-desktop-services`, `widget-desktop-calendar` (1440×900@3x) | The split branded container, service + calendar steps |
| `owner-dashboard--marble` | 9 · Today at the salon |
| `owner-dashboard--glacier` | 9 · Same morning in the Glacier theme (labeled) |
| `owner-calendar-week`, `owner-calendar-day` | 10–11 · Master calendar |
| `owner-clients-directory`, `owner-client-profile` | 12–13 · Client book + one profile |
| `owner-reports` | 14 · Charts with real shape |
| `owner-settings-branding` | 15 · Branding, accent picker visible |
| `owner-widgets` | 16 · Widget list + editor |
| `owner-services` | 16b · The service menu — owner-ordered, reorder controls |
| `owner-availability` | 18a · Stylist schedule cards |
| `owner-onboarding-step` | 18 · Guided setup, one clean step |
| `crop-stat-tile`, `crop-appointment-row`, `crop-availability-card`, `crop-embed-code`, `crop-widget-calendar-card` | Element-level callout crops for isolated film moments |
| `owner-dashboard--accent-01…04`, `owner-calendar-week--accent-01…04`, `widget-landing--accent-01…04`, `widget-calendar--accent-01…04` | 19 · **The accent beat** — identical screen/data/scroll, only the accent differs: `#C0613E` terracotta, `#5B3E96` deep violet, `#5C7458` sage, `#211C18` near-black (hexes recorded per-file in the manifest). Cross-dissolve these. The widget *calendar* variants carry the strongest recolor (tinted day circles + slot chips); the landing variants are subtler. |

## Manual interventions / things that resisted automation

- **Glacier is an agency-scoped theme** — the salon theme picker deliberately
  never offers it. The labeled `owner-dashboard--glacier` shot is produced by
  forcing `app_theme=glacier` through `launch:capture style` (the token block
  renders fine); it is a film-only state, not a product-supported salon theme.
- **"Time slot selected"**: the widget's slot chips don't have a persistent
  selected state — clicking a chip immediately adds the service and advances.
  `widget-06-slots` shows the open slots; the post-click state is the
  "added to visit" screen inside `widget-03-multi-service`.
- **The widget bot gate + rate limit** need the env overrides above whenever
  the server is started by hand — pure automation otherwise.
- **No user avatars exist in the product** — stylists render as seeded-pastel
  initial avatars (`x-ui.avatar`), which is what the shots show. No service
  categories exist either; the 10-service menu is a flat list.
- Nothing needed hand-editing after capture; every shot is fully scripted.

## The film itself (video/ — Remotion)

The Remotion project lives in `video/` with its **own** package.json — never
entangled with the app's JS build. It reads the captured assets through a
committed symlink (`video/public/assets → docs/launch-video/assets`) and this
manifest; scenes reference assets by manifest key only. `video/SCRIPT.md` is
the VO's single source of truth; `video/src/beats.ts` is the timing sheet
every scene reads from (beats marked `provisional` are timed at ~2.7
words/sec until the real read lands).

```sh
cd video && npm install
npm run dev                       # Remotion studio
npm run render                    # full-res Preview (beats A+B) → ~/Movies/BookTheStyle-Launch/
npm run render -- --draft         # half-res draft for fast iteration
npm run render -- --comp=LaunchFilm --out=/abs/path   # full 78s timeline, custom dir
```

**Voiceover generation** (`video/scripts/generate-vo.mjs`) needs
`ELEVENLABS_API_KEY` in the environment — required ONLY to (re)generate the
read; rendering needs nothing but the mp3 already on disk. The key is never
committed anywhere. The script parses `SCRIPT.md` into per-beat segments
(markers stripped), synthesizes each with prosody continuity
(`previous_text`/`next_text`), and measures real durations into
`video/src/vo-timing.json` — the single timing source the beat sheet reads.
The film's two directed pauses (a beat of silence before "Not ours.", a hard
stop after "somewhere else.") are handled by per-segment PLACEMENT at
explicit offsets (`--assemble` builds `voiceover-final.mp3` with ffmpeg
adelay/amix), not SSML — deterministic and re-timeable. Voice: first
available premade from the shortlist Rachel → Matilda → Sarah → Alice (warm,
conversational, owner-to-owner — not announcer), overridable with
`ELEVENLABS_VOICE_ID`; the chosen id lands in `vo-timing.json` for
reproducibility. Dry-run without the key: `node scripts/generate-vo.mjs
--dry-run`.

Renders NEVER enter git — the render script writes outside the repo
(`--out` / `BTS_RENDER_OUT`, default `~/Movies/BookTheStyle-Launch/`).
Fonts are the app's own self-hosted woff2 binaries, auto-synced from
`public/build/assets` by `video/scripts/sync-fonts.mjs` (runs before
dev/render; requires a root `npm run build` to exist). Audio drop-slots
(gitignored): `video/public/audio/voiceover-final.mp3` (wins),
`voiceover-scratch.mp3`, `music.mp3` — the composition uses whatever exists.

**Widget motion** (the "her side" beat wants real screen motion, not stills):

```sh
node scripts/capture-launch-assets.mjs --motion
```

records the full mobile booking flow (land → service → stylist → calendar →
slot → details → confirm) with human pacing, reduced-motion OFF, the same
frozen clock, and the same local-only guard, writing
`assets/widget-motion.webm` (gitignored) and registering it in the manifest
with its duration. Capture-only env overrides apply exactly as above.

## Known, deferred

Findings from the 3x audit that are real but deliberately NOT fixed in the
launch-video pass:

- **Native file inputs** on Settings → Branding and the Widget editor
  ("Choose File / No file chosen") clash with the design system. A proper
  styled upload control is a bigger job than it looks (drag-drop, previews,
  Livewire upload states); the film frames around it.
- **Glacier reads faint on the salon shell** — glass stat tiles and a
  background bloom, but the sidebar and tables barely change. A theme
  redesign is not a launch-video task; the film's theme moment now uses the
  four-accent cross-dissolve instead of a Marble→Glacier dissolve.
- **The full-lockup PNG** (`public/images/full-logo.png`) still uses the
  scissors-B as the word's first letter; below ~40px it needs the compact
  text lockup (now used by the sidebar). Auth pages (h-10) and marketing
  nav (h-9) sit just above the ambiguity threshold — an asset-level redraw
  is with the brand owner.

## Storage rules

Committed: this README, `manifest.json`, `assets/.gitkeep`, the seeder
(`database/seeders/LaunchSalonSeeder.php`), the command
(`app/Console/Commands/LaunchCapture.php`), and the script
(`scripts/capture-launch-assets.mjs`). Never committed: everything under
`assets/`, and any rendered video (`out/`, `*.mp4`, `*.mov` are gitignored).
