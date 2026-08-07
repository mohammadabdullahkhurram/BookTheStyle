{{-- Technical reference: BookTheStyle × GoHighLevel. Every BTS-side value
     is verified from this codebase (routes/web.php, BookingApi services,
     BookingApiToken, AuthenticateBookingApi, GhlWebhookController,
     AppServiceProvider rate limiters). GHL-instance specifics render as
     <x-docs.fill-in> slots — they live in GHL, never guessed here. --}}

<x-docs.section id="about" :title="__('About this reference')">
    <p>{{ __('This is the integration reference for the technical team: how GoHighLevel (Loopflo) and BookTheStyle connect, the exact API surface GHL calls, the token and webhook security model, and the runbook for wiring a new salon. BookTheStyle-side values on this page are taken from the codebase and are exact; amber dashed slots are GHL-instance specifics to be filled from the live Loopflo account.') }}</p>
    <ul>
        <li><strong>{{ __('Audience') }}</strong> — {{ __('engineers and technically-minded operators wiring or debugging the integration.') }}</li>
        <li><strong>{{ __('Scope') }}</strong> — {{ __('the GHL ↔ BTS boundary: booking API, tokens, webhook, contact sync, provisioning. It does not cover in-app salon features (see the SOP) or full deploy operations (docs/DEPLOY.md in the repo).') }}</li>
        <li><strong>{{ __('Non-technical?') }}</strong> {{ __('The salon-setup SOP under the SOPs group walks the same ground in plain language.') }}</li>
    </ul>
    <p class="text-[12.5px] text-faint">{{ __('Revision: 2026-08-07 · reflects the codebase as of that date.') }}</p>
</x-docs.section>

<x-docs.section id="prerequisites" :title="__('Prerequisites & assumptions')">
    <ul>
        <li>{{ __('An agency login on app.bookthestyle.com with any agency role (this page itself is agency-only).') }}</li>
        <li>{{ __('Admin access to the Loopflo/GHL agency account and the salon\'s sub-account.') }}</li>
        <li>{{ __('The salon already exists in BookTheStyle with services, bookable staff, and working hours — the booking API answers from real availability, so an empty salon returns no slots.') }}</li>
        <li>{{ __('The salon\'s hostname exists in hPanel (hostnames are human-created — see the runbook below).') }}</li>
        <li>{{ __('Assumed model: one GHL sub-account/location per salon; one booking API token per salon; BTS is the single source of truth for the calendar.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="architecture" :title="__('Architecture')">
    <p>{{ __('Two systems with a deliberate division of responsibility:') }}</p>
    <ul>
        <li><strong>BookTheStyle (BTS)</strong> — {{ __('the booking engine and source of truth for salons, staff, services, availability, and appointments. Multi-tenant: each salon is a tenant resolved by subdomain ({salon}.bookthestyle.com); central surfaces (agency console, auth, this API) live on app.bookthestyle.com. One slot engine serves every booking path — in-app, the embeddable widget, and this API — so a slot offered anywhere is a slot that genuinely exists.') }}</li>
        <li><strong>GoHighLevel (GHL — Loopflo white-label)</strong> — {{ __('the CRM, communications (SMS / email / voice), workflow automation, and the Voice AI conversational layer. One sub-account / location per salon.') }}</li>
    </ul>
    <p><strong>{{ __('Rule of thumb: BTS owns the calendar; GHL owns the conversation.') }}</strong> {{ __('GHL never stores or mirrors the calendar — its Voice AI and Workflows ask BTS every time via Custom Actions (HTTPS calls to the booking API), and BTS pushes booking changes back out to GHL (reminders, voice AI context, chat) with tag-gated contact sync in both directions. The echo-loop protection that keeps push + webhook from bouncing events forever is documented in the repo\'s docs/ARCHITECTURE.md.') }}</p>
    <x-docs.figure :title="__('System overview')">
        @include('docs.partials.diagram-architecture')
    </x-docs.figure>
    <p>{{ __('Adjacent surfaces on the same host, for orientation (not part of the GHL path): the per-salon embeddable booking widget (slug-scoped public endpoints on the salon\'s subdomain), personal-calendar ICS feeds at GET /cal/{token}.ics (route cal.feed), and the GHL inbound webhook covered in its own section below.') }}</p>
</x-docs.section>

<x-docs.section id="booking-sequence" :title="__('The booking sequence, end to end')">
    <p>{{ __('Voice AI is the conversation layer only ("Architecture 4"): the AI holds the phone conversation and delegates every availability lookup and booking decision to BTS. That is what guarantees one source of truth and no double-booking — the AI never invents a slot.') }}</p>
    <x-docs.figure :title="__('A call, end to end')">
        @include('docs.partials.diagram-booking-sequence')
    </x-docs.figure>
    <p>{{ __('Note the two realities of mid-call booking the API is built for: races (the offered slot was taken while the caller decided → 409 with alternatives, see the endpoint reference) and retries (the same create replayed → the same booking back, see idempotency).') }}</p>
</x-docs.section>

<x-docs.section id="endpoints" :title="__('Endpoint reference')">
    <p>{{ __('Two endpoints, both on the central app host, both POST, both authenticated by the per-salon bearer token — the salon is resolved from the token, never from the URL or body (nothing tamperable). Verified from routes/web.php and VoiceBookingController. There are no other booking-API endpoints today: cancel and reschedule happen in-app, not over the API.') }}</p>

    <h3 id="endpoint-availability">{{ __('Check availability') }}</h3>
    <div class="overflow-x-auto">
        <table>
            <tbody>
                <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/availability</code></td></tr>
                <tr><th>{{ __('Route name') }}</th><td><code>api.booking.availability</code></td></tr>
                <tr><th>{{ __('Auth header') }}</th><td><code>Authorization: Bearer btsk_…</code> {{ __('(see the token lifecycle)') }}</td></tr>
            </tbody>
        </table>
    </div>
    <p>{{ __('Parameters (JSON body or query string — both accepted):') }}</p>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Required') }}</th><th>{{ __('Meaning') }}</th></tr></thead>
            <tbody>
                <tr><td><code>service</code></td><td>{{ __('yes') }}</td><td>{{ __('Service name or id. Fuzzy name matching is applied; multiple services can be requested for a combined visit.') }}</td></tr>
                <tr><td><code>stylist</code></td><td>{{ __('no') }}</td><td>{{ __('A preferred stylist by name or id; omitted = any qualified stylist.') }}</td></tr>
                <tr><td><code>date</code></td><td>{{ __('no') }}</td><td>{{ __('A day (natural formats tolerated); omitted = the coming days.') }}</td></tr>
                <tr><td><code>date_to</code></td><td>{{ __('no') }}</td><td>{{ __('End of a date range, inclusive.') }}</td></tr>
            </tbody>
        </table>
    </div>
    <p>{{ __('Example request:') }}</p>
    <pre><code>POST /api/v1/booking/availability
Authorization: Bearer btsk_12_4f0c…
Content-Type: application/json

{ "service": "Haircut", "stylist": "Maya", "date": "2026-08-14" }</code></pre>
    <p>{{ __('Example success response (200):') }}</p>
    <pre><code>{
  "success": true,
  "service": { "id": 3, "name": "Haircut", "duration_minutes": 60 },
  "timezone": "America/Los_Angeles",
  "slots": [
    { "starts_at": "2026-08-14T10:00:00-07:00",
      "date": "2026-08-14", "time": "10:00 AM",
      "spoken": "Friday, August 14 at 10:00 AM",
      "stylist_id": 7, "stylist": "Maya Marchetti",
      "duration_minutes": 60 }
  ],
  "message": "There are 4 openings for Haircut. The earliest is …"
}</code></pre>
    <p>{{ __('Every response carries a speakable message — the Voice AI can read it verbatim. An empty slots array is still a 200 with a message suggesting another date.') }}</p>

    <h3 id="endpoint-create">{{ __('Create booking') }}</h3>
    <div class="overflow-x-auto">
        <table>
            <tbody>
                <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/create</code></td></tr>
                <tr><th>{{ __('Route name') }}</th><td><code>api.booking.create</code></td></tr>
                <tr><th>{{ __('Auth header') }}</th><td><code>Authorization: Bearer btsk_…</code></td></tr>
            </tbody>
        </table>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Required') }}</th><th>{{ __('Meaning') }}</th></tr></thead>
            <tbody>
                <tr><td><code>service</code></td><td>{{ __('yes') }}</td><td>{{ __('Service name or id (multiple = one visit, back-to-back).') }}</td></tr>
                <tr><td><code>stylist</code></td><td>{{ __('no') }}</td><td>{{ __('Preferred stylist; omitted = assigned from qualified stylists.') }}</td></tr>
                <tr><td><code>date</code> + <code>time</code></td><td>{{ __('one form') }}</td><td>{{ __('The slot as a GHL-friendly pair — the primary shape.') }}</td></tr>
                <tr><td><code>datetime</code></td><td>{{ __('one form') }}</td><td>{{ __('Alternative: a single ISO datetime instead of the pair.') }}</td></tr>
                <tr><td><code>client.name</code></td><td>{{ __('yes') }}</td><td>{{ __('Client fields are accepted nested (client.name) or flattened (client_name).') }}</td></tr>
                <tr><td><code>client.phone</code> / <code>client.email</code></td><td>{{ __('no') }}</td><td>{{ __('Used for client matching and confirmations.') }}</td></tr>
                <tr><td><code>notes</code></td><td>{{ __('no') }}</td><td>{{ __('Free text, max 1000 chars.') }}</td></tr>
                <tr><td><code>ghl_contact_id</code></td><td>{{ __('no') }}</td><td>{{ __('The GHL contact — links the booking for contact sync and attribution.') }}</td></tr>
            </tbody>
        </table>
    </div>
    <p>{{ __('Example request:') }}</p>
    <pre><code>POST /api/v1/booking/create
Authorization: Bearer btsk_12_4f0c…
Content-Type: application/json

{ "service": "Haircut", "stylist": "Maya",
  "date": "2026-08-14", "time": "10:00 AM",
  "client": { "name": "Casey Client", "phone": "+1 415 555 0122" },
  "ghl_contact_id": "AbC123…" }</code></pre>
    <p>{{ __('Example success response (201):') }}</p>
    <pre><code>{
  "success": true,
  "idempotent": false,
  "booking_id": 4821,
  "confirmation": {
    "salon": "Glamour Studio", "service": "Haircut",
    "stylist": "Maya Marchetti",
    "starts_at": "2026-08-14T10:00:00-07:00",
    "spoken_time": "Friday, August 14 at 10:00 AM"
  },
  "message": "Booked — Friday, August 14 at 10:00 AM with Maya."
}</code></pre>

    <h3 id="endpoint-errors">{{ __('Status codes & error responses') }}</h3>
    <p>{{ __('Errors are clean JSON — never a stack trace — and always carry the speakable message. Verified from VoiceBookingController and VoiceBookingApi:') }}</p>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Status') }}</th><th><code>error</code></th><th>{{ __('Meaning → resolution') }}</th></tr></thead>
            <tbody>
                <tr><td><code>401</code></td><td><code>unauthenticated</code></td><td>{{ __('Missing/invalid/rotated token, or an inactive salon. Re-paste the salon\'s current token from Settings → Integrations.') }}</td></tr>
                <tr><td><code>409</code></td><td><code>slot_unavailable</code></td><td>{{ __('The requested time was taken (race) — the response includes alternatives[] (create) with nearby open slots to offer. Pick one and re-create.') }}</td></tr>
                <tr><td><code>422</code></td><td><code>invalid_request</code></td><td>{{ __('Request-shape validation failed; fields[] names the offending inputs. Fix the Custom Action\'s field mapping.') }}</td></tr>
                <tr><td><code>422</code></td><td><code>unknown_service</code> / <code>unknown_stylist</code></td><td>{{ __('The name didn\'t match anything in this salon; the message lists what exists. Check spelling or use ids.') }}</td></tr>
                <tr><td><code>422</code></td><td><code>ambiguous_stylist</code></td><td>{{ __('The name matched more than one stylist; the message lists the options — send a fuller name or the id.') }}</td></tr>
                <tr><td><code>422</code></td><td><code>no_services</code> / <code>no_stylists</code></td><td>{{ __('The request named no bookable service, or nobody qualified offers it — fix the salon\'s services/qualifications in-app.') }}</td></tr>
                <tr><td><code>422</code></td><td><code>invalid_date</code></td><td>{{ __('The date could not be parsed — use e.g. 2026-07-25.') }}</td></tr>
                <tr><td><code>429</code></td><td>—</td><td>{{ __('Rate limit exceeded (see limits below). Back off and retry after a minute.') }}</td></tr>
            </tbody>
        </table>
    </div>
    <x-docs.callout type="note" :title="__('Wire tolerance (deliberate)')">
        {{ __('Parameters are accepted from the JSON body OR the query string (GHL often sends a query string with an empty body); values are defensively percent-decoded (GHL double-encodes: "Hair%2520Cut" arrives as literal "Hair%20Cut"); client fields are accepted nested or flattened. See VoiceInput. Do not "fix" a working Custom Action to send a prettier shape — both are first-class.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="auth" :title="__('Token lifecycle — issuance, scope, rotation')">
    <p>{{ __('One token per salon, and the token IS the tenant scope (App\Support\BookingApiToken, AuthenticateBookingApi):') }}</p>
    <pre><code>Authorization: Bearer btsk_{salonId}_{40 hex chars}</code></pre>
    <ul>
        <li><strong>{{ __('Issuance') }}</strong> — {{ __('generated in-app on the salon\'s subdomain: Settings → Integrations → Voice-AI Booking API. The plaintext exists exactly once, at generation — copy it immediately; only the sha256 hash is stored.') }}</li>
        <li><strong>{{ __('Format') }}</strong> — {{ __('btsk_ prefix, embedded salon id (an id is not a secret; the 160-bit random suffix is), constant-time hash comparison on every request.') }}</li>
        <li><strong>{{ __('Scope') }}</strong> — {{ __('the salon resolves FROM the token, so a token can never read or book another salon. Cross-tenant access through this surface is impossible by construction.') }}</li>
        <li><strong>{{ __('Rotation / revocation') }}</strong> — {{ __('regenerate on the same card: the old token stops working immediately (its hash is replaced). Deactivating the salon also invalidates its token. After rotating, re-paste into every GHL Custom Action that used it.') }}</li>
        <li><strong>{{ __('Refusals') }}</strong> — {{ __('demo salons can never hold a token; anything invalid gets one uniform 401 (no information leak about which part failed). Tokens are never logged.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="resilience" :title="__('Retries, idempotency & rate limits')">
    <ul>
        <li><strong>{{ __('Idempotent create') }}</strong> — {{ __('replaying a create for a slot that is already booked for the same client returns the SAME booking with idempotent: true and a 201 — retries after a timeout are safe and never double-book. (VoiceBookingApi matches the existing appointment and answers with its confirmation.)') }}</li>
        <li><strong>{{ __('Races') }}</strong> — {{ __('if the slot was taken by someone else between offer and create, the response is 409 slot_unavailable with nearby alternatives[] to offer the caller — re-create with a chosen alternative.') }}</li>
        <li><strong>{{ __('Rate limits') }}</strong> — {{ __('booking API: 60 requests/min per token (config booking_api.rate_limit, keyed by token hash; by IP when no token). Webhook: 120/min per IP. Calendar feeds: 60/min per IP. Exceeding answers 429 — back off, do not tighten GHL retry loops.') }}</li>
        <li><strong>{{ __('Timeouts') }}</strong> — {{ __('treat a timeout as unknown outcome and retry the same create once — idempotency makes that safe.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="webhook" :title="__('Inbound webhook contract (GHL → BTS)')">
    <p>{{ __('GHL-side booking events flow back into BTS through one endpoint (verified from routes/web.php and GhlWebhookController):') }}</p>
    <div class="overflow-x-auto">
        <table>
            <tbody>
                <tr><th>{{ __('Endpoint') }}</th><td><code>POST https://app.bookthestyle.com/webhooks/ghl</code> ({{ __('route') }} <code>webhooks.ghl</code>)</td></tr>
                <tr><th>{{ __('Auth header') }}</th><td><code>X-Webhook-Secret: &lt;{{ __('per-salon shared secret') }}&gt;</code></td></tr>
                <tr><th>{{ __('Session / CSRF') }}</th><td>{{ __('Sessionless and CSRF-exempt; rate-limited 120/min per IP.') }}</td></tr>
            </tbody>
        </table>
    </div>
    <ul>
        <li><strong>{{ __('Verification') }}</strong> — {{ __('the salon is resolved from the payload\'s GHL location id; the header secret is compared hash_equals against that salon\'s stored secret (encrypted at rest). Unknown location or wrong secret → one uniform 401.') }}</li>
        <li><strong>{{ __('Payload tolerance') }}</strong> — {{ __('GHL\'s webhook shape varies by version; the parser (GhlWebhookPayload) reads each field through known aliases — appointment.* (current), calendar.* (legacy), customData.* fallbacks — including GHL\'s misspelled calendar.appoinmentStatus, and qualifies offset-less times with the calendar\'s selectedTimezone. Fields: appointment id, calendar id, assigned user, status, start/end, contact.') }}</li>
        <li><strong>{{ __('Echo-loop protection') }}</strong> — {{ __('events that originated from BTS\'s own outbound push are recognised and not re-applied; the full design lives in docs/ARCHITECTURE.md and is hard-earned — never regress it.') }}</li>
        <li><strong>{{ __('Cloudflare') }}</strong> — {{ __('the WAF must skip /webhooks/ghl and /api/v1/booking/* (machine callers cannot pass a challenge page) — see docs/DEPLOY.md.') }}</li>
    </ul>
    <p>{{ __('The GHL-side workflow that sends this webhook, and where its secret is configured:') }} <x-docs.fill-in>{{ __('workflow name + where the secret is set in GHL') }}</x-docs.fill-in></p>
</x-docs.section>

<x-docs.section id="ghl-side" :title="__('GHL side (fill from the live instance)')">
    <ul>
        <li>{{ __('Sub-account / location: one per salon, provisioned from the Loopflo snapshot') }} <x-docs.fill-in>{{ __('snapshot name') }}</x-docs.fill-in></li>
        <li>{{ __('Contacts + tags: the tag-gating scheme is') }} <x-docs.fill-in>{{ __('tag names — e.g. bts-synced, booked') }}</x-docs.fill-in>{{ __('; only tagged contacts sync.') }}</li>
        <li>{{ __('Workflows:') }} <x-docs.fill-in>{{ __('list — confirmation, reminders (24h/2h), no-show follow-up, …') }}</x-docs.fill-in> {{ __('with triggers') }} <x-docs.fill-in>{{ __('triggers') }}</x-docs.fill-in></li>
        <li>{{ __('Voice AI agent: persona/prompt') }} <x-docs.fill-in>{{ __('persona') }}</x-docs.fill-in>{{ __(', allowed Custom Actions: availability + book.') }}</li>
        <li>{{ __('Contact-sync direction/trigger and dedupe rules:') }} <x-docs.fill-in>{{ __('when tagged → sync; match on email/phone …') }}</x-docs.fill-in></li>
    </ul>
</x-docs.section>

<x-docs.section id="runbook" :title="__('Runbook — provision & connect a salon')">
    <p>{{ __('Each step ends with its expected outcome; do not continue past a step that does not match. The plain-language version of the same journey is the salon-setup SOP.') }}</p>
    <x-docs.step n="1" :title="__('Create the hostname in hPanel')">
        <p>{{ __('Hostnames are a closed, human-created set: wildcard DNS resolves anything, but the origin only holds certificates for hPanel-created subdomains and Cloudflare runs Full (strict) — a runtime-minted hostname answers 525 for every visitor. Application code never generates a subdomain (HostnameGuardTest pins the allowlist).') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('https://{slug}.bookthestyle.com answers (the app\'s marketing/login shell) with a valid certificate.') }}</p>
    </x-docs.step>
    <x-docs.step n="2" :title="__('Create the BTS salon and populate it')">
        <p>{{ __('Agency console → New salon; then staff (Takes bookings where relevant), services, hours — the SOP covers this in detail.') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('the salon dashboard loads on its subdomain and the calendar shows the staff columns.') }}</p>
    </x-docs.step>
    <x-docs.step n="3" :title="__('Create the GHL sub-account from the snapshot')">
        <p>{{ __('From') }} <x-docs.fill-in>{{ __('snapshot name') }}</x-docs.fill-in>{{ __('; confirm the tag scheme and workflows arrived (GHL-side section above).') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('the sub-account exists with the standard workflows listed, toggled off or on per the template.') }}</p>
    </x-docs.step>
    <x-docs.step n="4" :title="__('Generate the booking token; wire both Custom Actions')">
        <p>{{ __('Settings → Integrations → Voice-AI Booking API → generate (shown once). In GHL, paste into BOTH Custom Actions: the availability URL and the create URL from the endpoint reference, same bearer token on each.') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('a manual test call of the availability action from GHL returns 200 with slots (or a no-openings message), not 401.') }}</p>
    </x-docs.step>
    <x-docs.step n="5" :title="__('Configure the webhook + contact sync')">
        <p>{{ __('Set the shared secret on the salon\'s Integrations card and in the GHL workflow that posts to /webhooks/ghl; confirm the sync tag exists.') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('the in-app integration check for the webhook reports green.') }}</p>
    </x-docs.step>
    <x-docs.step n="6" :title="__('Wire the Voice AI and enable workflows')">
        <p>{{ __('Point the agent at the two Custom Actions; switch on confirmation/reminder/no-show workflows; personalise the greeting.') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('a live test call books a real appointment that appears on the BTS calendar within a minute, tagged with the voice source, and the confirmation message goes out.') }}</p>
    </x-docs.step>
    <x-docs.step n="7" :title="__('Verify end to end')">
        <p>{{ __('Run every in-app integration verify button, then the live test call above. Cancel the test booking in-app afterwards.') }}</p>
        <p><strong>{{ __('Expected:') }}</strong> {{ __('all checks green; the test booking\'s lifecycle (create → cancel) mirrors into GHL without echo duplicates.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="troubleshooting" :title="__('Troubleshooting & error reference')">
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Symptom') }}</th><th>{{ __('Likely cause → fix') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('Custom Action returns 401') }}</td><td>{{ __('Token missing, mistyped, rotated since pasting, or the salon is deactivated — regenerate/re-paste from Settings → Integrations.') }}</td></tr>
                <tr><td>{{ __('Custom Action returns 422 with fields[]') }}</td><td>{{ __('The GHL field mapping sends wrong names/shapes — align with the parameter tables above (body and query are both fine).') }}</td></tr>
                <tr><td>{{ __('unknown_service / unknown_stylist') }}</td><td>{{ __('Voice AI passes a name that doesn\'t exist in THIS salon — check the salon\'s service list and stylist names; prefer ids in the action mapping if names collide.') }}</td></tr>
                <tr><td>{{ __('Availability always empty') }}</td><td>{{ __('The salon has no bookable staff with hours, or nobody qualified for the service — fix staffing/qualifications in-app (SOP part A).') }}</td></tr>
                <tr><td>{{ __('Voice AI books, no confirmation goes out') }}</td><td>{{ __('The booking succeeded in BTS but the GHL workflow didn\'t fire — check the workflow trigger, the contact tag, and contact sync.') }}</td></tr>
                <tr><td>{{ __('Booking lands on the wrong salon') }}</td><td>{{ __('The Custom Action carries another salon\'s token — the token IS the tenant. Regenerate this salon\'s and re-paste.') }}</td></tr>
                <tr><td>{{ __('Webhook events not arriving') }}</td><td>{{ __('Wrong X-Webhook-Secret, wrong location id, or a Cloudflare rule challenging the path — verify the secret, the location mapping, and the WAF skip list.') }}</td></tr>
                <tr><td>{{ __('Duplicate bookings after webhook work') }}</td><td>{{ __('Echo-loop protection regression — stop and read docs/ARCHITECTURE.md before touching anything.') }}</td></tr>
                <tr><td>{{ __('429s under load') }}</td><td>{{ __('A retry loop in GHL is hammering the API — the limit is 60/min per token; add backoff, don\'t raise the limit first.') }}</td></tr>
                <tr><td>{{ __('"Deployed but nothing changed"') }}</td><td>{{ __('Opcache — run the full deploy sequence incl. the opcache reset (docs/DEPLOY.md).') }}</td></tr>
            </tbody>
        </table>
    </div>
</x-docs.section>

<x-docs.section id="glossary" :title="__('Glossary')">
    <div class="overflow-x-auto">
        <table>
            <tbody>
                <tr><th>{{ __('Custom Action') }}</th><td>{{ __('A GHL-side configured HTTP call the Voice AI / a Workflow can make — here, the two booking endpoints.') }}</td></tr>
                <tr><th>{{ __('Sub-account / location') }}</th><td>{{ __('GHL\'s per-business container; one per salon, identified by a location id.') }}</td></tr>
                <tr><th>{{ __('Slot engine') }}</th><td>{{ __('BTS\'s single availability computation — services × qualified staff × hours × existing bookings — used by every booking surface.') }}</td></tr>
                <tr><th>{{ __('Takes bookings') }}</th><td>{{ __('The per-member bookable flag: stylists always; owners and managers optionally.') }}</td></tr>
                <tr><th>{{ __('Chair Rental') }}</th><td>{{ __('The salon type where each stylist runs their own book and clients (vs Employee: one shared calendar; Mix: chosen per stylist).') }}</td></tr>
                <tr><th>{{ __('Tag gating') }}</th><td>{{ __('Only GHL contacts carrying the sync tag flow between systems — the consent/scope boundary for contact sync.') }}</td></tr>
                <tr><th>{{ __('Echo-loop protection') }}</th><td>{{ __('The design that stops BTS\'s own pushed events from re-importing via the webhook as new changes.') }}</td></tr>
                <tr><th>{{ __('Idempotent replay') }}</th><td>{{ __('Re-sending the same create safely returns the already-made booking instead of a duplicate.') }}</td></tr>
            </tbody>
        </table>
    </div>
</x-docs.section>

<x-docs.section id="sync" :title="__('Keeping this page in sync with the code')">
    <p>{{ __('Every BTS-side fact here mirrors a specific place in the codebase: the routes (routes/web.php), the request contract (VoiceBookingController + VoiceInput), responses and errors (VoiceBookingApi + ApiError), tokens (BookingApiToken + AuthenticateBookingApi), the webhook (GhlWebhookController + GhlWebhookPayload), and the rate limiters (AppServiceProvider). If you change any of those, update this page in the same pull request — the docs tests pin the headline values, but prose accuracy is on the author.') }}</p>
</x-docs.section>
