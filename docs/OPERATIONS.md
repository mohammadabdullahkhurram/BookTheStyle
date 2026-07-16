# Operations — onboarding a salon end-to-end

The agency-team runbook: from nothing to a live, GHL-connected, bookable salon. Every step is verifiable in-app — prefer the built-in Test/Verify buttons over assuming.

## 0. Prerequisites

- The salon's GHL **sub-account exists** (cloned from the template), with the staff added as GHL users and a master booking calendar created (each stylist added as a **team member** on that calendar).
- You have an agency owner/admin login at `app.bookthestyle.com`.

## 1. DNS / subdomain

Wildcard DNS (`*.bookthestyle.com`) already points at the origin and Cloudflare proxies it, so a new salon slug needs **no per-salon DNS or hPanel work**. Just make sure the slug you'll pick isn't reserved (the form rejects reserved/duplicate slugs).

## 2. Create the salon

Agency console → Salons → **New salon**: business profile, slug (this becomes `{slug}.bookthestyle.com`), timezone, currency, booking policy. The GHL fields (Location ID, master Calendar ID, PIT) are optional here — the **required scopes list is shown on the form**; if the PIT is ready, paste it now, otherwise connect later from the salon's Settings → Integrations.

Creating the PIT in GHL: sub-account → Settings → Private Integrations → create, tick **exactly the scopes listed in the form** (copy affordance provided), copy the token immediately (GHL shows it once).

## 3. Staff, services, availability (in the salon)

Open `{slug}.bookthestyle.com` → **Setup wizard** (`/setup`) tracks all of this and shows what's missing:

1. **Staff**: invite each member (role + staff type). Each gets a temp password (shown once) and is forced to change it at first login.
2. **Services**: name, duration, display price; assign qualifying stylists; per-stylist duration/buffer overrides where needed.
3. **Availability**: weekly hours per stylist (split shifts supported), date-specific overrides/time off.

## 4. GHL connection + mapping

Settings → Integrations (or the wizard's GHL steps):

1. **Connect**: Location ID + PIT → save → **Test connection** (reads the calendar list with the real token).
2. **Verify contact sync** (proves the contact scopes respond).
3. **Master calendar and staff mapping**: Load from GoHighLevel → pick the master calendar → map each stylist to their GHL team member (email matches are pre-suggested) → save → **Verify mapping** (confirms the calendar exists and every stylist maps to a real team member, by name).
   - *Gotcha:* a stylist missing from the dropdown isn't a team member on that calendar in GHL — add them there (edit calendar → team members), reload, remap.
4. **Inbound webhook**: generate the secret; in GHL create a workflow (triggers: appointment status changed / customer booked appointment, plus contact changed if wanted) with a **Webhook action**: POST, the shown URL, custom header `X-Webhook-Secret` = the shown secret. Publish. Then **Test delivery** (the app pings its own public URL with the secret) and/or trigger a real GHL change and "Check again".
5. **Availability sync**: Sync availability to GoHighLevel → per-stylist status turns synced → **Verify in GoHighLevel** (reads each schedule back).
6. **Outbound booking sync**: **Run round-trip test** — creates one clearly-titled test appointment through the real push path, reads it back, deletes it. If it can't clean up it says so and names the test appointment title.

## 5. Voice AI

1. Settings → Voice AI booking API → **Generate token** (shown once — run **Test booking API** while it's still on screen for the full 200-with-slots proof).
2. In GHL (AI Agents → the voice agent → Custom Actions) configure the two actions exactly as the wizard's "Voice AI custom actions" step lists them (URLs, `Authorization: Bearer <token>`, and **date + time as separate parameters** — GHL rejects combined ISO datetimes).
3. *Gotchas:* regenerating the token invalidates the old one immediately (update the custom actions); Cloudflare must skip WAF/bot challenges on `/api/v1/booking/*` (see DEPLOY.md).

## 6. Booking widget

Salon → Widgets: the default booking widget exists; brand it (accent/background/font/logo) or create additional widgets, each with its own embed. Give the site owner the **script snippet** (`<div data-bookthestyle-salon=… data-bookthestyle-widget=…>` + the `widget.js` tag) — it injects an auto-sizing iframe. Verify a real booking lands in Appointments tagged "Booking widget" and pushes to GHL.

## 7. Go-live check

The wizard's summary lists anything missing; completing it marks the salon live. Sanity pass: book in-app → appears in GHL with a reminder scheduled; book via GHL (voice/chat) → appears in-app tagged with its source; check-in does **not** bounce an echo back; personal ICS feed connects (Settings → My calendar — copy-link flow with per-app steps).

## Known gotchas (all learned the hard way)

- **Nothing in GHL edits availability** — the app overwrites GHL-side schedule edits on the next push (one-way by design).
- Google Calendar ICS subscriptions refresh on Google's schedule (hours). Not a bug; the in-app calendar is real-time.
- GHL sends custom-action params as a **query string with an empty body**, sometimes double-URL-encoded — the API tolerates this; don't "fix" the agent prompt to send JSON bodies.
- A booking's GHL push lands within ~1 minute (cron-drained queue) — instant is not the expectation.
- Sync failures never block bookings; they queue in Settings → Integrations → Sync issues with per-booking retry.
