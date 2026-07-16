# Architecture — the hard parts

The knowledge that took months to earn: the GHL sync design, the wire quirks, the voice API, and the tenancy machinery. Each section names the code that owns it — the code is the truth; this explains **why** it's shaped that way.

## 1. The GHL sync

### 1.1 Why this division exists (load-bearing)

**The app is the booking engine; GHL is the conversation layer.** GHL's own calendar/booking rules cannot express per-stylist service durations, buffers, multi-service visits, or salon booking policy — so letting GHL decide slots would fork the source of truth. Instead every surface (voice AI included) books through the app's engine, and GHL receives a mirror. The mirror is not optional decoration: **GHL reminders only fire for appointments that exist in GHL**, and the voice AI/chat live there.

### 1.2 Per-salon tokens, not one agency key (load-bearing)

Each salon connects its own GHL **location** via a Private Integration Token (`salon_ghl_connections`, encrypted at rest, write-only in the UI). Why not one agency-level key: blast radius (one leaked token = one salon), per-location rate limits (GHL throttles per location — `GhlClient` keeps under 100 req/10 s per location), and clean off-boarding (disconnect deletes one token). Required scopes are the list in `config/ghl.php`; they're surfaced at every token entry point (`partials/ghl-scopes.blade.php`).

### 1.3 Outbound push — `GhlBookingPusher`

- **One booking = one service = one stylist = one GHL appointment.** Multi-service visits persist as separate bookings linked by `visit_group_id` precisely so this mapping stays 1:1 — no grouping logic, no partial-update ambiguity.
- Pushes are **queued** (`SyncBookingToGhl`), **idempotent by payload hash** (unchanged booking = zero API calls; changed = update, never duplicate), and **stateful on the booking row** (`ghl_appointment_id`, `ghl_sync_status`, `ghl_sync_error`, `ghl_payload_hash`, `ghl_last_pushed_status`).
- Slots are pushed with `ignoreFreeSlotValidation: true` — the app's engine already validated; GHL must not re-reject against its cruder rules.
- Cancelled bookings **cancel** the GHL appointment (never delete — GHL keeps the record, reminders stop).
- Failures surface in Settings → Integrations → Sync issues with per-booking retry. Nothing outbound ever blocks or fails a booking.

### 1.4 Inbound webhook — `GhlWebhookController` → `ProcessGhlWebhook` → `GhlInboundSync`

`POST /webhooks/ghl` is central and sessionless. The salon resolves from the payload's GHL location id; authenticity is the per-salon `X-Webhook-Secret` header (`hash_equals`; GHL workflow webhooks carry no platform signature, so the shared secret IS the auth). Unknown location, missing or wrong secret → one uniform 401. The endpoint acks fast (202) and queues the real work.

**Replay dedupe:** GHL workflow bodies carry no nonce, so an identical body within 1 hour whose twin was *successfully* processed is dropped (`ignored_replay`). Events that ended pending/review/error never block reprocessing — otherwise one bad run deadlocks that appointment.

### 1.5 Echo-loop protection (the #1 correctness risk)

Every outbound push triggers GHL's own workflows, which fire the inbound webhook **back at the app**. Without protection, app→GHL→app becomes an infinite ping-pong or, worse, stale-state overwrites. The defense is layered (in `GhlInboundSync` / `GhlContactSync`), and none of it depends on timestamps GHL doesn't reliably send:

1. **State equality**: if the inbound event equals current app state, it's our own echo → `ignored_echo`.
2. **Last-pushed comparison**: the booking stores `ghl_last_pushed_status` (and clients store `ghl_pushed_hash`) — an event matching what we last sent is recognizably ours even if app state has since moved on.
3. **Last-change-wins with stale gates**: an inbound change older than the app's own last change is `ignored_stale`.
4. Every decision is recorded on the `WebhookEvent` row (`applied` / `created_booking` / `ignored_echo` / `ignored_stale` / `ignored_replay` / `review` — never silently dropped).

**Do not "simplify" any layer away** — each catches cases the others miss (e.g. an echo arriving after a second app-side change defeats state-equality but not last-pushed).

### 1.6 Reconcile — `ghl:reconcile` (hourly)

Webhooks get missed (GHL retries are finite; the app can be mid-deploy). The reconcile command reads each connected salon's GHL events feed (±7 days, one throttled call per salon), and repairs drift through **the same inbound pipeline** — synthetic `ghl.reconcile` WebhookEvents — so echo suppression and last-change-wins apply identically. It applies missed changes, imports unknown appointments (enriching client data via the contact API), and flags vanished ones.

### 1.7 Availability push — `GhlAvailabilityPusher` (one-way, by design)

Each mapped stylist's weekly hours + time off mirror into a per-user GHL **schedule** (created/adopted/updated, id stored on the stylist profile, hash-idempotent), applied to the master calendar; calendar-level slot settings are set to the worst case (longest service duration incl. overrides, longest cleanup buffer) so GHL under-offers, never over-offers. **One-way because two sources of truth for availability is a merge problem nobody wins**: the app is authoritative, and a GHL-side edit is simply overwritten on the next push. Sync state is visible per stylist in Settings, with read-back verification ("Verify in GoHighLevel") that confirms the schedule actually exists in GHL.

### 1.8 Contacts — `GhlContactSync`

Outbound: booking clients upsert to GHL contacts (matched server-side by email/phone), id stored on the client, real clients auto-tagged with `config('ghl.client_tag')` (merge-only tag ADD — never a tags overwrite). Inbound contact events are **tag-gated**: an unknown contact only becomes a client if it carries the tag — this keeps GHL's lead/form-fill firehose out of the client directory. Updates to already-linked clients apply regardless of tags, with the same echo protection as bookings.

### 1.9 Integration checks — `IntegrationChecks`

Seven on-demand verifications behind the Test/Verify buttons (Settings → Integrations + the setup wizard): connection, contact scopes, calendar+mapping (per-stylist, by name), availability read-back, a **self-cleaning booking round-trip** (creates a clearly-titled far-future test appointment through the real push path, reads it back, deletes it), webhook self-ping (the controller answers a test payload without recording an event), and voice-API end-to-end. Rate-limited, results persisted on the salon, URL-dependent checks show an honest "needs live URL" state locally.

## 2. GHL wire quirks (hard-earned; do not regress)

All tolerated in `App\Services\BookingApi\VoiceInput` + the voice controller:

| Quirk | Reality | Handling |
|---|---|---|
| Query-string bodies | GHL custom actions send parameters as a query string with an **empty body** | `$request->input()` merges both; never read the body alone |
| Double URL-encoding | `"Hair Cut"` can arrive as literal `Hair%20Cut` (encoded twice) | defensive percent-decode on every string param |
| ISO datetime rejection | GHL agents mangle combined ISO datetimes | the create action takes **`date` + `time` as separate params** (primary shape); combined `datetime` is the tolerated fallback |
| Client fields | arrive nested (`client[name]`) or flattened (`client_name`) | `VoiceInput::client()` accepts both |
| Appointment-id shapes | create responses vary (`id` / `appointmentId` / nested) | defensive extraction in `GhlBookingPusher`; the calendar id is never accepted as an appointment id |
| Version headers | GHL API families need different `Version` headers (calendars 2021-04-15; users/contacts 2021-07-28) | constants in `GhlClient` |

## 3. Voice-AI booking API

`POST /api/v1/booking/availability` and `/create` on the app host. Authenticated by a **per-salon bearer token** which also *resolves* the salon — nothing tenant-identifying is ever taken from URL or body. The token is stored **hashed** (sha256), shown once at generation; rate limiting keys on the hashed bearer (falling back to real client IP for probes). Responses are JSON with a **speakable `message`** (the voice agent reads it out) — failures included; never a stack trace. Tuning knobs (days ahead, max slots/day, alternatives, rate limits) live in `config/booking_api.php`. The embeddable widget books through the same engine via slug-scoped `/api/widget/*` endpoints (rate-limited per IP+salon host, bot-gated on submit).

## 4. Tenant isolation mechanics

Three cooperating layers — all server-side:

1. **Host resolution**: `ResolveSalon` middleware maps `{slug}.` to a salon, 404s unknown/inactive/reserved slugs, 403s non-members (agency reach only via explicit assignment). Binds `currentSalon` for the request.
2. **Query scope**: salon-scoped models use `BelongsToSalon` + the `SalonScope` global scope; cross-tenant ids in URLs/params hit `firstOrFail` inside the scoped query (anti-IDOR).
3. **Authorization**: `SalonPolicy`/`AgencyPolicy` gate every capability (role × staff type); Livewire actions re-authorize server-side regardless of what the UI showed.

The webhook and voice API are deliberately **outside** the session/scope system (no session exists); they resolve their salon from the secret/token itself and query explicitly by `salon_id`.

## 5. Runtime shape

- **Queue**: `database` driver drained by the per-minute scheduler (`queue:work --stop-when-empty --max-time=55 --tries=3`) — no supervisor exists on the host. GHL syncs land within ~1 minute of the triggering action; that latency is by design.
- **Scheduler** (`routes/console.php`, one cron line drives all): queue drain (1 min), `bookings:close-elapsed` (5 min), `ghl:reconcile` (hourly), `model:prune` + `queue:prune-failed` (daily retention).
- **Proxies**: Cloudflare → Hostinger → PHP-FPM. `TrustCloudflareClientIp` adopts `CF-Connecting-IP` (spoof-proof through the edge) before any throttle; `TRUSTED_PROXIES` is config-pinnable; production forces https URL generation.
- **Live UI**: Livewire polling (calendar ~5 s) — no WebSockets on this host.
- **Themes**: `ThemeRegistry` (salon: Marble/Classic; agency: brand/glacier; coming-soon placeholders are locked cards). Classic salons render the pre-rollout "lumen" look on two proof routes (`LumenTheme`) — intentional, tested. A salon's branding accent recolors any theme via `AccentPalette` CSS-variable slots.
