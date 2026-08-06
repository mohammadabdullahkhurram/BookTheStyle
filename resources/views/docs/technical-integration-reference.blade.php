{{-- Technical reference: BookTheStyle × GoHighLevel. Every BTS-side value
     below is verified from this codebase (routes/web.php, the BookingApi
     services, BookingApiToken, AuthenticateBookingApi). GHL-instance
     specifics render as <x-docs.fill-in> slots — they live in GHL. --}}

<p class="mb-4">{{ __('For the technical team. BookTheStyle-side values on this page are taken from the codebase and are exact; amber dashed slots are GHL-instance specifics to be filled from the live Loopflo account, never guessed.') }}</p>

<x-docs.section id="overview" :title="__('System overview')">
    <p>{{ __('Two systems with a clean division of responsibility:') }}</p>
    <ul>
        <li><strong>BookTheStyle (BTS)</strong> — {{ __('the booking engine and source of truth for salons, staff, services, availability, and appointments. Multi-tenant: each salon is a tenant resolved by subdomain ({salon}.bookthestyle.com); the agency console and central surfaces live on app.bookthestyle.com. BTS exposes the public booking widget and the booking API that GHL calls.') }}</li>
        <li><strong>GoHighLevel (GHL — Loopflo white-label)</strong> — {{ __('the CRM, communications (SMS / email / voice), workflow automation, and the Voice AI conversational layer. One GHL sub-account / location per salon.') }}</li>
    </ul>
    <p><strong>{{ __('Rule of thumb: BTS owns the calendar; GHL owns the conversation.') }}</strong> {{ __('They connect through Custom Actions (GHL → BTS HTTP calls) and tag-gated contact sync.') }}</p>
    <x-docs.figure :title="__('System overview')">
        @include('docs.partials.diagram-architecture')
    </x-docs.figure>
</x-docs.section>

<x-docs.section id="integration-model" :title="__('Integration model')">
    <ul>
        <li><strong>{{ __('GHL → BTS (Custom Actions).') }}</strong> {{ __('Voice AI and Workflows call the BTS booking endpoints over HTTPS to check availability and create appointments. BTS is the booking authority — GHL never stores the calendar; it asks every time.') }}</li>
        <li><strong>{{ __('BTS ↔ GHL (contact sync).') }}</strong> {{ __('Client/contact records sync bidirectionally, gated by tags so only intended contacts flow.') }}</li>
        <li><strong>{{ __('Voice AI is the conversation layer only') }}</strong> {{ __('("Architecture 4"): the AI holds the phone conversation and delegates every availability lookup and booking to BTS. One source of truth, no double-booking.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="booking-sequence" :title="__('Voice-AI booking sequence')">
    <x-docs.figure :title="__('A call, end to end')">
        @include('docs.partials.diagram-booking-sequence')
    </x-docs.figure>
</x-docs.section>

<x-docs.section id="endpoints" :title="__('Booking endpoints — the Custom Action targets')">
    <p>{{ __('Both endpoints live on the central app host — the salon is resolved from the bearer token, never from the URL. Verified from routes/web.php:') }}</p>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Action') }}</th><th>{{ __('Method + path') }}</th><th>{{ __('Route name') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('Check availability') }}</td><td><code>POST https://app.bookthestyle.com/api/v1/booking/availability</code></td><td><code>api.booking.availability</code></td></tr>
                <tr><td>{{ __('Create booking') }}</td><td><code>POST https://app.bookthestyle.com/api/v1/booking/create</code></td><td><code>api.booking.create</code></td></tr>
            </tbody>
        </table>
    </div>
    <p>{{ __('There are no other booking-API endpoints today — cancel and reschedule happen in-app, not over the API. Requests are rate-limited per token (throttle:booking-api) and are CSRF-exempt, sessionless routes.') }}</p>
    <x-docs.callout type="note" :title="__('Wire tolerance (deliberate)')">
        {{ __('Parameters are accepted from the JSON body OR the query string (GHL often sends a query string with an empty body), values are defensively percent-decoded (GHL double-encodes: "Hair%2520Cut" arrives as literal "Hair%20Cut"), and client fields are accepted nested or flattened. See VoiceInput and the controller.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="auth" :title="__('Auth — the per-salon bearer token')">
    <p>{{ __('Custom Actions authenticate with a per-salon token (App\Support\BookingApiToken):') }}</p>
    <pre><code>Authorization: Bearer btsk_{salonId}_{40 hex chars}</code></pre>
    <ul>
        <li>{{ __('Only the sha256 hash is stored; the plaintext exists exactly once, at generation.') }}</li>
        <li>{{ __('The token IS the tenant scope: the salon is resolved from it (AuthenticateBookingApi), so a token can never read or book another salon.') }}</li>
        <li>{{ __('Generated in-app on the salon\'s subdomain under Settings → Integrations → Voice-AI Booking API; regenerating replaces the old token immediately.') }}</li>
        <li>{{ __('Demo salons can never hold a token; anything invalid gets a uniform 401 {"error":"unauthenticated"}.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="contract" :title="__('Custom Actions — request/response contract')">
    <p>{{ __('Verified from VoiceBookingController (request validation) and VoiceBookingApi (responses). Every response is JSON with a speakable message for the Voice AI.') }}</p>

    <h3>{{ __('Check availability') }}</h3>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Request field') }}</th><th>{{ __('Rules') }}</th></tr></thead>
            <tbody>
                <tr><td><code>service</code></td><td>{{ __('required — service name or id') }}</td></tr>
                <tr><td><code>stylist</code></td><td>{{ __('optional — a preferred stylist') }}</td></tr>
                <tr><td><code>date</code> / <code>date_to</code></td><td>{{ __('optional — a day or a range, natural formats tolerated') }}</td></tr>
            </tbody>
        </table>
    </div>
    <pre><code>200 {
  "success": true,
  "service": { "id", "name", "duration_minutes" },
  "timezone": "America/Los_Angeles",
  "slots": [ { "starts_at" (ISO), "date", "time", "spoken",
               "stylist_id", "stylist", "duration_minutes" } ],
  "message": "There are 4 openings for Haircut. The earliest is …"
}</code></pre>

    <h3>{{ __('Create booking') }}</h3>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Request field') }}</th><th>{{ __('Rules') }}</th></tr></thead>
            <tbody>
                <tr><td><code>service</code></td><td>{{ __('required') }}</td></tr>
                <tr><td><code>stylist</code></td><td>{{ __('optional') }}</td></tr>
                <tr><td><code>date</code> + <code>time</code></td><td>{{ __('the slot — GHL-friendly pair (or a single ISO datetime instead)') }}</td></tr>
                <tr><td><code>client.name</code></td><td>{{ __('required (client.phone / client.email optional; nested or flattened)') }}</td></tr>
                <tr><td><code>notes</code>, <code>ghl_contact_id</code></td><td>{{ __('optional') }}</td></tr>
            </tbody>
        </table>
    </div>
    <pre><code>201 { "success": true, "idempotent": false, "booking_id": 123,
      "confirmation": { "salon", "service", "stylist",
                        "starts_at" (ISO), "spoken_time" },
      "message": "Booked — Friday, June 12 at 2:00 PM with Maya." }

409 { "success": false, "error": "slot_unavailable",
      "alternatives": [ …nearby open slots… ], "message": "…" }
422 { "success": false, "error": "invalid_request", "fields": […] }
401 { "success": false, "error": "unauthenticated" }</code></pre>
</x-docs.section>

<x-docs.section id="other-surfaces" :title="__('Other BTS integration surfaces (for completeness)')">
    <ul>
        <li><strong>{{ __('GHL inbound webhook') }}</strong> — <code>POST https://app.bookthestyle.com/webhooks/ghl</code> {{ __('(route webhooks.ghl): sessionless, CSRF-exempt, authenticated by the per-salon shared secret in X-Webhook-Secret; the salon resolves from the payload\'s location id. Rate-limited per IP.') }}</li>
        <li><strong>{{ __('Booking widget') }}</strong> — {{ __('embeddable per salon on their {slug} subdomain; same slot engine and data as everything else. Not part of the GHL path.') }}</li>
        <li><strong>{{ __('Calendar feeds') }}</strong> — <code>GET https://app.bookthestyle.com/cal/{token}.ics</code> {{ __('(route cal.feed): per-user tokenized ICS for personal calendars.') }}</li>
        <li><strong>{{ __('Verify tooling') }}</strong> — {{ __('the in-app integration test/verify buttons exercise each GHL touchpoint end to end; use them before any live test call.') }}</li>
    </ul>
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

<x-docs.section id="provisioning" :title="__('Provisioning a new salon — technical checklist')">
    <x-docs.step n="1" :title="__('Create the hostname in hPanel')">
        <p>{{ __('Hostnames are a closed, human-created set — wildcard DNS resolves anything, but the origin only holds certificates for hPanel-created subdomains and Cloudflare runs Full (strict). A runtime-minted hostname answers 525 for every visitor. Application code never generates a subdomain; HostnameGuardTest pins the allowlist.') }}</p>
    </x-docs.step>
    <x-docs.step n="2" :title="__('Create the BTS salon')"><p>{{ __('Agency console → New salon (the SOP covers the full walkthrough).') }}</p></x-docs.step>
    <x-docs.step n="3" :title="__('Create the GHL sub-account from the snapshot')"><p>{{ __('See the GHL-side slots above.') }}</p></x-docs.step>
    <x-docs.step n="4" :title="__('Generate the booking API token; wire the Custom Actions')"><p>{{ __('Settings → Integrations → Voice-AI Booking API; paste both endpoint URLs + the token into GHL.') }}</p></x-docs.step>
    <x-docs.step n="5" :title="__('Wire the Voice AI + enable Workflows')"><p>{{ __('Point the agent at the two Custom Actions; switch on the standard workflows.') }}</p></x-docs.step>
    <x-docs.step n="6" :title="__('Verify')"><p>{{ __('In-app verify buttons first, then a live test call that must land on the BTS calendar and trigger the confirmation workflow.') }}</p></x-docs.step>
</x-docs.section>

<x-docs.section id="security" :title="__('Auth & security notes')">
    <ul>
        <li>{{ __('Token scoping is tenant isolation by construction — the salon comes from the token, and cross-tenant access is impossible through this surface.') }}</li>
        <li>{{ __('Tenancy is enforced per subdomain/tenant everywhere else; the agency layer is separate.') }}</li>
        <li>{{ __('Cross-agency edit guard: an agency operator cannot repoint the login email of an account shared across agencies.') }}</li>
        <li>{{ __('Tokens and PII are never logged; API failures return clean JSON, never a stack trace.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="ops" :title="__('Deploy & ops (for maintainers)')">
    <x-docs.callout type="warning" :title="__('Opcache — non-negotiable')">
        {{ __('After git pull, reset PHP opcache / restart PHP-FPM, or the web process keeps executing stale compiled PHP even after the config/route/view cache rebuild. Full sequence and reset recipes: docs/DEPLOY.md in the repo.') }}
    </x-docs.callout>
    <ul>
        <li>{{ __('Migrations are additive-only; production refuses destructive schema commands outright. MySQL-safe migrations only (no ->after() referencing a later column).') }}</li>
        <li>{{ __('Assets are built locally and committed — the server has no Node.') }}</li>
        <li>{{ __('No runtime subdomain minting — hostnames come from a human in hPanel first.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="troubleshooting" :title="__('Troubleshooting')">
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Symptom') }}</th><th>{{ __('Likely cause → fix') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('A Custom Action fails / times out') }}</td><td>{{ __('Endpoint URL or token wrong in GHL — compare against the salon\'s Integrations card; run the in-app verify.') }}</td></tr>
                <tr><td>{{ __('Voice AI books, but no confirmation goes out') }}</td><td>{{ __('The booking succeeded in BTS but the GHL workflow didn\'t fire — check the tag/trigger and contact sync.') }}</td></tr>
                <tr><td>{{ __('Booking lands on the wrong salon') }}</td><td>{{ __('The Custom Action carries another salon\'s token — the token IS the tenant; regenerate and re-paste this salon\'s.') }}</td></tr>
                <tr><td>{{ __('"I deployed but nothing changed"') }}</td><td>{{ __('Opcache — reset it (see Deploy & ops above).') }}</td></tr>
            </tbody>
        </table>
    </div>
</x-docs.section>
