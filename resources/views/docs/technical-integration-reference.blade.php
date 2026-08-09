{{-- Technical reference for the technical team. Every BookTheStyle fact on
     this page is pulled from the code (owning classes named inline where
     load-bearing); GHL-instance specifics render as <x-docs.fill-in> slots
     and are never guessed. Rebuilt from scratch 2026-08-09. --}}

<x-docs.section id="about" :title="__('About this reference')">
    <p>{{ __('The integration internals for engineers: how the tenancy works, what the booking engine guarantees, the full Booking API contract, the webhook, the sync machinery, and the operational surfaces around them. The CODE is the source of truth — this page names the owning class for each claim so drift is checkable. Values that exist only in the live GHL/Loopflo account render as dashed amber fill-ins.') }}</p>
    <ul>
        <li><strong>{{ __('Audience') }}</strong> — {{ __('engineers onboarding to the project or debugging the integration. The SOPs group covers the non-technical walkthrough.') }}</li>
        <li><strong>{{ __('Stack in one line') }}</strong> — {{ __('PHP 8.4, Laravel 13 (pinned 13.15.0), Livewire 4/Volt, Flux free, Tailwind 4; MySQL in production, SQLite locally and in CI; Pest, Pint, Larastan level 7.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="architecture" :title="__('System architecture & tenancy')">
    <p>{{ __('One Laravel app, four hostnames: the apex (marketing), app.bookthestyle.com (auth, agency console, /api/v1/booking, /webhooks, /cal feeds, /widget.js), register. (public book-a-call), and one subdomain per salon. The doctrine that shapes everything: the app is the booking engine and single source of truth; GHL is the conversation layer and receives a mirror — because GHL reminders only fire for appointments that exist in GHL, and the Voice AI lives there.') }}</p>

    <x-docs.figure :title="__('System overview: GoHighLevel handles conversation; the app decides every slot')">
        <svg viewBox="0 0 760 250" width="760" height="250" role="img" aria-label="{{ __('Architecture diagram: four hosts on one app; GHL and the widget call the booking API; the app pushes mirrors to GHL and receives webhooks back.') }}">
            <defs>
                <marker id="techArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#6B6862"/></marker>
            </defs>
            <rect x="10" y="20" width="180" height="90" rx="12" fill="#E3EDF6" stroke="#C4D6E8"/>
            <text x="100" y="50" text-anchor="middle" font-size="13" fill="#356088" font-weight="600">GoHighLevel</text>
            <text x="100" y="68" text-anchor="middle" font-size="10.5" fill="#356088">{{ __('Voice AI · workflows · SMS') }}</text>
            <text x="100" y="84" text-anchor="middle" font-size="10.5" fill="#356088">{{ __('per-salon sub-account + PIT') }}</text>
            <rect x="10" y="140" width="180" height="80" rx="12" fill="#F0EEEA" stroke="#DAD5CD"/>
            <text x="100" y="172" text-anchor="middle" font-size="13" fill="#1C1B1A" font-weight="600">{{ __('Salon website') }}</text>
            <text x="100" y="190" text-anchor="middle" font-size="10.5" fill="#6B6862">{{ __('widget.js iframe embed') }}</text>
            <rect x="290" y="20" width="240" height="200" rx="12" fill="#E7EFE4" stroke="#C8DAC2"/>
            <text x="410" y="48" text-anchor="middle" font-size="13" fill="#3E5C3A" font-weight="600">{{ __('BookTheStyle (one app)') }}</text>
            <text x="410" y="72" text-anchor="middle" font-size="10.5" fill="#3E5C3A">app. — /api/v1/booking · /webhooks/ghl · /cal</text>
            <text x="410" y="90" text-anchor="middle" font-size="10.5" fill="#3E5C3A">{{ __('{slug}. tenant hosts · widget endpoints') }}</text>
            <text x="410" y="108" text-anchor="middle" font-size="10.5" fill="#3E5C3A">{{ __('SlotEngine + BookingPolicy — the one engine') }}</text>
            <text x="410" y="126" text-anchor="middle" font-size="10.5" fill="#3E5C3A">{{ __('queue on the per-minute cron') }}</text>
            <rect x="600" y="70" width="150" height="90" rx="12" fill="#F6EFE2" stroke="#E2CFA4"/>
            <text x="675" y="105" text-anchor="middle" font-size="13" fill="#7A5B1F" font-weight="600">MySQL</text>
            <text x="675" y="123" text-anchor="middle" font-size="10.5" fill="#7A5B1F">{{ __('UTC instants · salon_id everywhere') }}</text>
            <line x1="190" y1="50" x2="288" y2="70" stroke="#6B6862" stroke-width="1.5" marker-end="url(#techArrow)"/>
            <text x="196" y="42" font-size="10" fill="#6B6862">{{ __('Custom Actions (bearer)') }}</text>
            <line x1="288" y1="100" x2="190" y2="85" stroke="#6B6862" stroke-width="1.5" marker-end="url(#techArrow)"/>
            <text x="205" y="118" font-size="10" fill="#6B6862">{{ __('push mirror + webhook back') }}</text>
            <line x1="190" y1="175" x2="288" y2="160" stroke="#6B6862" stroke-width="1.5" marker-end="url(#techArrow)"/>
            <text x="200" y="152" font-size="10" fill="#6B6862">/api/widget/*</text>
            <line x1="530" y1="115" x2="598" y2="115" stroke="#6B6862" stroke-width="1.5" marker-end="url(#techArrow)"/>
        </svg>
    </x-docs.figure>

    <p>{{ __('Tenant isolation is three cooperating server-side layers:') }}</p>
    <ol>
        <li><strong>{{ __('Host resolution') }}</strong> — {{ __('ResolveSalon (a Livewire persistent middleware) maps the request host\'s slug to a salon, 404s unknown/inactive/reserved slugs, 403s non-members, and binds currentSalon.') }}</li>
        <li><strong>{{ __('Query scope') }}</strong> — {{ __('salon-owned models use BelongsToSalon + the SalonScope global scope; cross-tenant ids die inside scoped firstOrFail (anti-IDOR).') }}</li>
        <li><strong>{{ __('Authorization') }}</strong> — {{ __('SalonPolicy/AgencyPolicy gate every capability; Livewire actions re-authorize server-side regardless of what the UI rendered.') }}</li>
    </ol>
    <p>{{ __('The token-authenticated API and the webhook sit deliberately OUTSIDE the session system: they resolve their salon from the credential itself (the token embeds the salon id; the webhook payload\'s locationId maps to a connection) and query by salon_id explicitly.') }}</p>
    <x-docs.callout type="warning" :title="__('Hostnames are hand-created')">
        {{ __('The origin only holds certificates for subdomains a human created in hPanel, and Cloudflare runs Full (strict) — a runtime-minted hostname answers 525 for every visitor. Application code never generates a subdomain; HostnameGuardTest pins the allowlist. (This shipped wrong once. Never again.)') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="domain-model" :title="__('Domain model')">
    <p>{{ __('agencies → salons → salon_memberships(users). RBAC is role × bookability: agency roles (owner — exactly one, ever · admin · user) and salon roles (owner · manager · stylist), with staff_type as the orthogonal "takes bookings" flag — stylists always, managers and owners optionally (sticky checkbox / self-toggle). Salon types (Employee · Chair Rental · Mix) drive the per-stylist arrangement; chair-renters are separate businesses under one roof and never see each other\'s books, clients, or revenue.') }}</p>

    <p>{{ __('A booking is one client + one or more booking_items (service, stylist, starts_at/ends_at in UTC, buffer_min). The status machine (App\Enums\BookingStatus, mirrored to GHL by GhlStatusMap):') }}</p>
    <x-docs.figure :title="__('Booking status machine (BookingStatus::allowedTransitions)')">
        <svg viewBox="0 0 700 170" width="700" height="170" role="img" aria-label="{{ __('Status diagram: booked to arrived, no-show or cancelled; arrived to cancelled or completed via automation; completed, cancelled and no-show are terminal.') }}">
            <defs><marker id="stArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="6" markerHeight="6" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#6B6862"/></marker></defs>
            <rect x="15" y="60" width="110" height="44" rx="10" fill="#E3EDF6" stroke="#C4D6E8"/><text x="70" y="87" text-anchor="middle" font-size="12.5" fill="#356088" font-weight="600">booked</text>
            <rect x="235" y="60" width="110" height="44" rx="10" fill="#E7EFE4" stroke="#C8DAC2"/><text x="290" y="87" text-anchor="middle" font-size="12.5" fill="#3E5C3A" font-weight="600">arrived</text>
            <rect x="455" y="10" width="120" height="40" rx="10" fill="#E7EFE4" stroke="#C8DAC2"/><text x="515" y="35" text-anchor="middle" font-size="12" fill="#3E5C3A">completed*</text>
            <rect x="455" y="65" width="120" height="40" rx="10" fill="#F6E8E1" stroke="#E4C4B3"/><text x="515" y="90" text-anchor="middle" font-size="12" fill="#8A4B2D">cancelled</text>
            <rect x="455" y="120" width="120" height="40" rx="10" fill="#F6EFE2" stroke="#E2CFA4"/><text x="515" y="145" text-anchor="middle" font-size="12" fill="#7A5B1F">no_show</text>
            <line x1="125" y1="82" x2="233" y2="82" stroke="#6B6862" stroke-width="1.5" marker-end="url(#stArrow)"/>
            <line x1="345" y1="70" x2="453" y2="40" stroke="#6B6862" stroke-width="1.5" marker-end="url(#stArrow)"/>
            <line x1="345" y1="90" x2="453" y2="85" stroke="#6B6862" stroke-width="1.5" marker-end="url(#stArrow)"/>
            <line x1="125" y1="95" x2="453" y2="132" stroke="#6B6862" stroke-width="1.5" marker-end="url(#stArrow)"/>
            <line x1="125" y1="70" x2="453" y2="72" stroke="#6B6862" stroke-width="1" stroke-dasharray="4 3" marker-end="url(#stArrow)"/>
            <text x="290" y="20" font-size="10" fill="#6B6862">{{ __('* completed via automation (auto-complete after end); terminal states never transition') }}</text>
        </svg>
    </x-docs.figure>
    <p>{{ __('GHL mapping (GhlStatusMap): cancelled→cancelled, no_show→noshow, arrived/in_service/completed→showed, booked/confirmed→confirmed. Automation: bookings:close-elapsed (every 5 min) moves elapsed booked→no_show (opt-in, grace window) and checked-in→completed.') }}</p>
    <ul>
        <li><strong>{{ __('Deletes are solo:') }}</strong> {{ __('removing a service/client/stylist soft-deletes the row as a tombstone (name survives for kept appointments, rendered "(removed)"); appointments are never cascade-deleted. Gated hardDelete = salon owner + agency owner/admin.') }}</li>
        <li><strong>{{ __('The test lane:') }}</strong> {{ __('every salon carries disposable is_test records (Bluejaypro stylist / service / two clients — ConnectionDiagnostics) — staff-visible badged TEST, excluded from every client-facing surface, GHL-skipped, TTL-swept. is_test clients are exempt from the temporal booking-policy gates (derived from the stored flag only), which is how the pinned far-future test slot books through the real engine.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="booking-engine" :title="__('The booking engine')">
    <p>{{ __('One engine serves every surface (in-app, widget, Voice API): App\Services\Booking\SlotEngine computes bookable slots on a 15-minute grid from weekly work windows minus breaks/time-off, existing items + buffers, and per-stylist duration overrides (DurationResolver); BookingPolicy enforces the temporal gates. CreateBooking re-validates the slot under a per-stylist lock, so two racers cannot double-book. All instants are stored UTC and interpreted in the salon\'s timezone (DST-safe).') }}</p>
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('Policy field (salons)') }}</th><th>{{ __('Default') }}</th><th>{{ __('Effect') }}</th></tr></thead>
            <tbody>
                <tr><td><code>allow_walkins</code></td><td>true</td><td>{{ __('walk-ins book "now", bypassing notice/window gates') }}</td></tr>
                <tr><td><code>allow_same_day</code></td><td>true</td><td>{{ __('same-day scheduled bookings permitted') }}</td></tr>
                <tr><td><code>max_advance_days</code></td><td>90</td><td>{{ __('the booking-window horizon (1–365)') }}</td></tr>
                <tr><td><code>min_notice_minutes</code></td><td>0</td><td>{{ __('minimum lead time for scheduled bookings') }}</td></tr>
                <tr><td><code>auto_no_show</code> {{ __('(+ 15-min grace)') }}</td><td>false</td><td>{{ __('elapsed booked → no_show automatically') }}</td></tr>
                <tr><td><code>auto_complete</code></td><td>true</td><td>{{ __('elapsed checked-in → completed') }}</td></tr>
            </tbody>
        </table>
    </div>
    <p>{{ __('Exemption: designated is_test clients skip min-notice/same-day/max-advance (BookingPolicy::assertCreatable\'s forTestClient flag, derived from the stored client record — request input can never claim it); the past stays refused and slot conflicts always apply. Real clients keep the window byte-for-byte.') }}</p>
</x-docs.section>

<x-docs.section id="endpoints" :title="__('Booking API reference')">
    <p>{{ __('Four endpoints on the app host, all POST, all authenticated by the per-salon bearer token — the salon resolves from the token, never from URL or body. Owned by VoiceBookingController (request shape) + VoiceBookingApi (behaviour). Every response carries a speakable message the voice agent can read verbatim; failures are clean JSON, never a stack trace.') }}</p>
    <x-docs.callout type="warning" :title="__('The /v1/ path')">
        {{ __('The full path is /api/v1/booking/… — a Custom Action URL missing the /v1/ segment 404s every call. It is the single most common wiring mistake.') }}
    </x-docs.callout>

    <h3 id="endpoint-availability">{{ __('Check availability') }}</h3>
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/availability</code></td></tr>
        <tr><th>{{ __('Route name') }}</th><td><code>api.booking.availability</code></td></tr>
        <tr><th>{{ __('Auth header') }}</th><td><code>Authorization: Bearer btsk_…</code></td></tr>
    </tbody></table></div>
    <div class="overflow-x-auto"><table>
        <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Required') }}</th><th>{{ __('Meaning') }}</th></tr></thead>
        <tbody>
            <tr><td><code>service</code></td><td>{{ __('yes') }}</td><td>{{ __('Service name or id; fuzzy name matching; multiple services = a combined visit.') }}</td></tr>
            <tr><td><code>stylist</code></td><td>{{ __('no') }}</td><td>{{ __('Preferred stylist by name or id; omitted = any qualified stylist.') }}</td></tr>
            <tr><td><code>date</code> / <code>date_to</code></td><td>{{ __('no') }}</td><td>{{ __('A day or inclusive range; omitted = the coming days (config days_ahead, default 3; max_slots_per_day = 6).') }}</td></tr>
        </tbody>
    </table></div>
    <p>{{ __('Success (200): the service summary, timezone, and slots[] — each with starts_at (ISO), date (Y-m-d), time (g:i A), spoken, stylist_id/stylist, duration_minutes — plus the speakable message. An empty slots array is still a 200 with a suggestion to try another date.') }}</p>

    <h3 id="endpoint-create">{{ __('Create booking') }}</h3>
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/create</code></td></tr>
        <tr><th>{{ __('Route name') }}</th><td><code>api.booking.create</code></td></tr>
    </tbody></table></div>
    <div class="overflow-x-auto"><table>
        <thead><tr><th>{{ __('Field') }}</th><th>{{ __('Required') }}</th><th>{{ __('Meaning') }}</th></tr></thead>
        <tbody>
            <tr><td><code>service</code> / <code>stylist</code></td><td>{{ __('yes / no') }}</td><td>{{ __('As in availability; no stylist = first qualified stylist free at that time.') }}</td></tr>
            <tr><td><code>date</code> + <code>time</code></td><td>{{ __('one form') }}</td><td>{{ __('The slot as a GHL-friendly pair — the PRIMARY shape (GHL mangles combined datetimes).') }}</td></tr>
            <tr><td><code>datetime</code></td><td>{{ __('one form') }}</td><td>{{ __('Tolerated fallback: a single ISO datetime.') }}</td></tr>
            <tr><td><code>client.name</code></td><td>{{ __('yes') }}</td><td>{{ __('Client fields accepted nested (client.name) or flattened (client_name).') }}</td></tr>
            <tr><td><code>client.phone</code> / <code>client.email</code> / <code>ghl_contact_id</code></td><td>{{ __('no') }}</td><td>{{ __('Client matching (format-blind on phone — see cancel) + the GHL contact link.') }}</td></tr>
            <tr><td><code>notes</code></td><td>{{ __('no') }}</td><td>{{ __('Free text, max 1000 chars.') }}</td></tr>
        </tbody>
    </table></div>
    <p>{{ __('Example request:') }}</p>
    <pre><code>POST /api/v1/booking/create
Authorization: Bearer btsk_12_4f0c…
Content-Type: application/json

{ "service": "Haircut", "stylist": "Maya",
  "date": "2026-08-14", "time": "10:00 AM",
  "client": { "name": "Casey Client", "phone": "+1 415 555 0122" } }</code></pre>
    <p>{{ __('Success is 201 with booking_id + a confirmation block (salon, service, stylist, starts_at, spoken_time). Idempotent create: re-sending the same client + service + exact start returns the SAME confirmation with idempotent: true instead of a duplicate. A taken slot answers 409 slot_unavailable with alternatives[] (nearest genuinely bookable slots, config alternatives = 3).') }}</p>

    <h3 id="endpoint-cancel">{{ __('Cancel appointment') }}</h3>
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/cancel</code></td></tr>
        <tr><th>{{ __('Route name') }}</th><td><code>api.booking.cancel</code></td></tr>
    </tbody></table></div>
    <p>{{ __('Identify the caller\'s appointment: at least one of client.phone / client.email / ghl_contact_id (the client is FOUND, never created), optionally narrowed by the stated current date(/time), or a direct booking_id reference (salon-scoped; foreign ids 404). Phone matching is format-blind — digits compared, punctuation/+/country code ignored (App\Support\PhoneNumber) — and the SAME lookup serves create, cancel, and reschedule, so write and read cannot disagree. Exactly one open upcoming match cancels through the same TransitionBookingStatus path the app uses (status event, GHL mirror — reminders stop). Several matches → 409 multiple_appointments with appointments[] to disambiguate. Already cancelled → 200 with already_cancelled: true (idempotent). There is no separate cancellation-window policy: cancellable = upcoming and still open; anything else answers 409 cannot_cancel in plain words.') }}</p>

    <h3 id="endpoint-reschedule">{{ __('Reschedule appointment') }}</h3>
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>{{ __('Method + path') }}</th><td><code>POST https://app.bookthestyle.com/api/v1/booking/reschedule</code></td></tr>
        <tr><th>{{ __('Route name') }}</th><td><code>api.booking.reschedule</code></td></tr>
    </tbody></table></div>
    <p>{{ __('Identification exactly as cancel, plus the new slot as new_date + new_time (or new_datetime), normally from a preceding availability call. The move runs through RescheduleBooking: stylists locked, shifted items re-validated against the live engine (409 slot_unavailable if taken — no double-booking), booking policy applied, status event written, and the GHL appointment UPDATED in place so reminders follow the new time.') }}</p>
    <p>{{ __('Example request:') }}</p>
    <pre><code>POST /api/v1/booking/reschedule
Authorization: Bearer btsk_12_4f0c…
Content-Type: application/json

{ "client": { "phone": "(916) 555-0100" },
  "date": "2026-08-14", "time": "10:00 AM",
  "new_date": "2026-08-15", "new_time": "1:00 PM" }</code></pre>

    <h3 id="endpoint-errors">{{ __('Status codes & error responses') }}</h3>
    <div class="overflow-x-auto"><table>
        <thead><tr><th>{{ __('Status') }}</th><th><code>error</code></th><th>{{ __('Meaning → resolution') }}</th></tr></thead>
        <tbody>
            <tr><td><code>401</code></td><td><code>unauthenticated</code></td><td>{{ __('Missing/rotated token or inactive salon — re-paste the current token from Settings → Integrations.') }}</td></tr>
            <tr><td><code>404</code></td><td><code>client_not_found</code> / <code>appointment_not_found</code></td><td>{{ __('Cancel/reschedule: no client with those details, or nothing upcoming matching the stated day. Confirm phone/email + date with the caller.') }}</td></tr>
            <tr><td><code>409</code></td><td><code>slot_unavailable</code></td><td>{{ __('The time was taken or refused by policy — create includes alternatives[]; on reschedule pick another slot and retry.') }}</td></tr>
            <tr><td><code>409</code></td><td><code>multiple_appointments</code></td><td>{{ __('Several upcoming matches — appointments[] lists them; resend with booking_id or date+time.') }}</td></tr>
            <tr><td><code>409</code></td><td><code>cannot_cancel</code></td><td>{{ __('The status no longer allows a phone cancellation (e.g. completed) — the salon handles it directly.') }}</td></tr>
            <tr><td><code>422</code></td><td><code>invalid_request</code> / <code>missing_identifier</code> / <code>invalid_date</code> / <code>invalid_datetime</code></td><td>{{ __('Request-shape problems; fields[] or the message names the offender. Fix the Custom Action mapping.') }}</td></tr>
            <tr><td><code>422</code></td><td><code>unknown_service</code> / <code>unknown_stylist</code> / <code>ambiguous_stylist</code> / <code>no_services</code> / <code>no_stylists</code></td><td>{{ __('Name resolution failed; the message lists what exists / the options.') }}</td></tr>
            <tr><td><code>429</code></td><td>—</td><td>{{ __('Rate limit (60/min per token). Back off and retry.') }}</td></tr>
        </tbody>
    </table></div>

    <x-docs.callout type="note" :title="__('Wire tolerance (deliberate — VoiceInput)')">
        {{ __('Parameters are accepted from the JSON body OR the query string (GHL often sends a query string with an EMPTY body); every string is defensively percent-decoded (GHL double-encodes: "Hair%2520Cut" arrives as literal "Hair%20Cut"); client fields nested or flattened; date + time as separate params is the primary shape. Do not "fix" a working Custom Action into a prettier request.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="auth" :title="__('Token lifecycle')">
    <p>{{ __('One token per salon, and the token IS the tenant scope (App\Support\BookingApiToken + AuthenticateBookingApi):') }}</p>
    <pre><code>Authorization: Bearer btsk_{salonId}_{40 hex chars}</code></pre>
    <ul>
        <li>{{ __('Generated from 20 random bytes; only the sha256 hash is stored (api_token_hash) with a generated-at stamp; the plaintext is shown exactly once.') }}</li>
        <li>{{ __('Resolution is O(1) by the embedded salon id; the hash comparison is constant-time (hash_equals); inactive and demo salons never resolve (demo salons cannot even hold a token — generation throws).') }}</li>
        <li><strong>{{ __('Rotation / revocation') }}</strong> — {{ __('regenerate on the Settings token card: the old token dies the moment the new one is created; update every Custom Action immediately. Exactly one active token per salon.') }}</li>
        <li>{{ __('The middleware also stamps the last authenticated call (path + time, cache, 2h TTL) — what the health-check\'s round-trip indicator reads. Never the token, never PII.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="webhook" :title="__('Inbound webhook contract (GHL → app)')">
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>{{ __('Endpoint') }}</th><td><code>POST https://app.bookthestyle.com/webhooks/ghl</code> ({{ __('route') }} <code>webhooks.ghl</code>)</td></tr>
        <tr><th>{{ __('Auth header') }}</th><td><code>X-Webhook-Secret</code> {{ __('header — per-salon secret (encrypted at rest), constant-time compare; the salon resolves from the payload\'s locationId') }}</td></tr>
        <tr><th>{{ __('Throttle') }}</th><td>{{ __('120/min per IP') }}</td></tr>
    </tbody></table></div>
    <ul>
        <li>{{ __('Accepted events answer 202 immediately; processing is queued (ProcessGhlWebhook). Empty body → 400; unknown location / wrong secret → a uniform 401.') }}</li>
        <li>{{ __('Replay dedupe: the raw payload is sha256-hashed; a twin already applied within the last hour records as ignored_replay instead of double-applying.') }}</li>
        <li><strong>{{ __('Echo-loop protection') }}</strong> — {{ __('the load-bearing piece (GhlInboundSync): an incoming status equal to the app\'s current state, or equal to the last status the app itself pushed (ghl_last_pushed_status), records as ignored_echo; genuine conflicts resolve last-change-wins on GHL\'s dateUpdated. Without this, every push would bounce back as a phantom "change".') }}</li>
        <li>{{ __('Every event persists in webhook_events with a status (applied, created_booking, created_client, ignored_echo/stale/replay/untagged, review, error) and a note — the audit trail when "GHL did something weird". Pruned after 30 days; never pending rows.') }}</li>
        <li>{{ __('A self-test payload type (bookthestyle.webhook.test) answers without recording — that is what the "Test delivery" button sends.') }}</li>
        <li>{{ __('Safety net: ghl:reconcile (hourly) pulls each connected salon\'s appointments ±7 days and repairs drift — missed webhooks, unknown appointments, vanished ones.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="outbound-sync" :title="__('Outbound sync (app → GHL)')">
    <p>{{ __('Create/reschedule/status changes queue SyncBookingToGhl (the job carries the booking id and pushes CURRENT state — naturally idempotent and re-dispatchable). GhlBookingPusher upserts the GHL appointment via the stylist\'s calendar-provider mapping, ensures the contact (and its client tag), and stamps sync state on the booking: ghl_sync_status (pending/synced/skipped/failed), ghl_appointment_id, a payload hash for no-op suppression, last attempt/synced times. Cancellations UPDATE the GHL appointment to cancelled — never delete, so GHL keeps the record and reminders stop. Availability pushes mirror weekly hours + time off per mapped stylist — one-way, the app wins. Failures never block bookings: exhausted retries surface in Settings → Integrations → Sync issues with per-booking retry, and a permanently deleted synced appointment gets a dedicated id-carrying GHL-cancel job (its row is gone, so the normal job cannot help).') }}</p>
    <p>{{ __('The queue is the database queue drained by the per-minute cron (no supervisor on this hosting) — a mirror landing within ~1 minute is the expectation, not a bug.') }}</p>
</x-docs.section>

<x-docs.section id="surfaces" :title="__('Widget, calendar feeds & the demo')">
    <ul>
        <li><strong>{{ __('Widget embed') }}</strong> — {{ __('the site owner pastes a div with data-bookthestyle-salon (the slug) + optional data-bookthestyle-widget / data-accent / data-service, plus the widget.js script (served from the app host, cached 1h). It injects an auto-resizing iframe onto the salon\'s own subdomain; resize messages are origin-checked.') }}</li>
        <li><strong>{{ __('Widget API') }}</strong> — {{ __('slug-scoped GET services/availability/month + POST book under /api/widget/* (throttle 30/min per IP + salon host). The book endpoint is bot-gated: honeypot field plus an encrypted page token that must be at least 4 seconds and at most 12 hours old. Authenticated in-app preview twins exist so staff can walk the client flow (test records included) without the public exclusions.') }}</li>
        <li><strong>{{ __('ICS feeds') }}</strong> — {{ __('per-USER personal calendar feeds at /cal/{token}.ics (hashed-token lookup, no session; 60/min per IP; ETag/304; private max-age 900). Google refreshes subscriptions on its own schedule — hours, not minutes.') }}</li>
        <li><strong>{{ __('The demo') }}</strong> — {{ __('app.…/demo redirects into the static demo. host as a logged-out read-only guest; demo salons never hold API tokens, never reach GHL, and visitor bookings reset nightly.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="health" :title="__('Health checks, the test lane & monitoring')">
    <p>{{ __('Per salon, agency-operator-only (Settings → Integrations → Health check; sudo-style password confirm). An extensible registry (HealthCheckRegistry — one small class per check) runs six categories: Integrations & Voice AI booking (token issued · endpoint reachable · availability finds slots · test booking · webhook secret · GHL configured AND a live GHL API call that catches revoked tokens · widget reachable) · Notifications · Scheduled jobs & queue (scheduler heartbeat · task freshness · queue depth) · Salon readiness · Data integrity (upcoming appointments with a gone stylist · services nobody performs / without price · stylists without hours — each naming the records) · System (salon-subdomain SSL via a real TLS-verified request · DB · migrations · storage · caches · URLs).') }}</p>
    <ul>
        <li><strong>{{ __('The disposable test set') }}</strong> — {{ __('ConnectionDiagnostics creates Bluejaypro Stylist / Hair Cut / Test Client / Voice AI Test Client (+1 555 010 0001), all is_test: staff-visible badged TEST, excluded from the widget/public booking/reports/GHL, TTL-swept (60 min after a run, 48h from salon creation), removed at go-live or via the Remove-test-data button.') }}</li>
        <li><strong>{{ __('The pinned test booking') }}</strong> — {{ __('the check books the test client at 2:00 PM on 28 June 3004 through the FULL engine path (the is_test window exemption makes the far-future slot legal for test clients only) — collision-proof forever, reused idempotently on re-runs.') }}</li>
        <li><strong>{{ __('History + alerting') }}</strong> — {{ __('every run (manual or scheduled) is recorded (health_check_runs, pruned after 90 days) and shown on the page; a check that was passing and now fails is a regression: flagged in the history and emailed to the agency\'s owners/admins.') }}</li>
        <li><strong>{{ __('The auto-monitor') }}</strong> — {{ __('health:monitor runs hourly for LIVE salons, strictly read-only (checks needing test records are skipped; nothing is created or booked).') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="security-ops" :title="__('Security, limits & configuration')">
    <ul>
        <li>{{ __('Auth: Fortify, no public registration; staff invited with one-time temp passwords + forced change; 2FA and passkeys available; login/2FA/passkey attempts rate-limited.') }}</li>
        <li>{{ __('Secrets at rest: the GHL PIT and webhook secret are encrypted; the booking token exists only as a sha256 hash; nothing secret is ever logged.') }}</li>
        <li>{{ __('Destructive schema commands are prohibited in production; migrations are additive-only. The deploy sequence, opcache reset, and rollback live in docs/DEPLOY.md — not duplicated here.') }}</li>
    </ul>
    <div class="overflow-x-auto"><table>
        <thead><tr><th>{{ __('Limiter') }}</th><th>{{ __('Limit') }}</th><th>{{ __('Keyed by') }}</th></tr></thead>
        <tbody>
            <tr><td><code>booking-api</code></td><td>{{ __('60/min (BOOKING_API_RATE_LIMIT)') }}</td><td>{{ __('sha256 of the bearer; IP pre-auth') }}</td></tr>
            <tr><td><code>widget-api</code></td><td>{{ __('30/min (BOOKING_WIDGET_RATE_LIMIT)') }}</td><td>{{ __('IP + salon host') }}</td></tr>
            <tr><td><code>ghl-webhook</code></td><td>120/min</td><td>IP</td></tr>
            <tr><td><code>calendar-feed</code></td><td>60/min</td><td>IP</td></tr>
        </tbody>
    </table></div>
    <p>{{ __('Tuning knobs (config/booking_api.php): days_ahead 3 · max_slots_per_day 6 · alternatives 3 · widget_min_seconds 4 · widget_token_ttl_hours 12 · widget_days_ahead 30 (clamped to the salon\'s max_advance_days). Retention: webhook_events 30 days (GHL_WEBHOOK_RETENTION_DAYS) · failed jobs 720h · health runs 90 days.') }}</p>
    <p>{{ __('The single cron line drives everything: per-minute queue drain + scheduler heartbeat; bookings:close-elapsed every 5 min; ghl:reconcile, health:monitor, diagnostics:sweep-test-records, demo:sweep hourly; nightly prunes + demo reset.') }}</p>
</x-docs.section>

<x-docs.section id="ghl-side" :title="__('GHL side (fill from the live instance)')">
    <ul>
        <li>{{ __('Sub-account / location: one per salon, provisioned from the Loopflo snapshot') }} <x-docs.fill-in>{{ __('snapshot name') }}</x-docs.fill-in></li>
        <li>{{ __('Required PIT scopes (shown with a copy button on the connection card): calendars.readonly, calendars.write, calendars/events.readonly, calendars/events.write, calendars/groups.readonly, contacts.readonly, contacts.write, users.readonly.') }}</li>
        <li>{{ __('Contacts + tags: the tag-gating scheme is') }} <x-docs.fill-in>{{ __('tag names — e.g. bts-synced, booked') }}</x-docs.fill-in>{{ __('; only tagged contacts sync.') }}</li>
        <li>{{ __('Workflows:') }} <x-docs.fill-in>{{ __('list — confirmation, reminders (24h/2h), no-show follow-up, …') }}</x-docs.fill-in> {{ __('with triggers') }} <x-docs.fill-in>{{ __('triggers') }}</x-docs.fill-in></li>
        <li>{{ __('Voice AI agent: persona/prompt') }} <x-docs.fill-in>{{ __('persona') }}</x-docs.fill-in>{{ __(', allowed Custom Actions: availability + book + cancel + reschedule.') }}</li>
        <li>{{ __('Contact-sync direction/trigger and dedupe rules:') }} <x-docs.fill-in>{{ __('when tagged → sync; match on email/phone …') }}</x-docs.fill-in></li>
    </ul>
</x-docs.section>

<x-docs.section id="runbook" :title="__('Provisioning runbook (engineer\'s view)')">
    <p>{{ __('The SOP walks this for the team; the condensed engineer version, with what each step proves:') }}</p>
    <x-docs.step n="1" :title="__('Hostname first')">
        <p>{{ __('Create the salon subdomain in hPanel BEFORE go-live (origin certificate; Cloudflare Full-strict). Expected: the subdomain-SSL health check passes.') }}</p>
    </x-docs.step>
    <x-docs.step n="2" :title="__('Salon + content')">
        <p>{{ __('Create via the agency console (owner auto-provisioned; test records seeded); staff/services/availability via the wizard. Expected: the readiness checks go green.') }}</p>
    </x-docs.step>
    <x-docs.step n="3" :title="__('GHL wiring')">
        <p>{{ __('Sub-account from the snapshot → PIT with the exact scope list → connect + Test connection → Load directory, map calendar + stylists, Verify mapping → webhook secret + workflow + Test delivery → availability sync. Expected: each Integrations sub-tab\'s status dot turns green; the wizard\'s GHL steps verify themselves.') }}</p>
    </x-docs.step>
    <x-docs.step n="4" :title="__('Voice AI + token')">
        <p>{{ __('Generate the booking token (shown once) and wire the FOUR Custom Actions (URLs with /v1/, Bearer header, date + time separate). Expected: a test call books the Voice AI test client at any far date; a cancel by the same phone cancels it.') }}</p>
    </x-docs.step>
    <x-docs.step n="5" :title="__('Prove it, then go live')">
        <p>{{ __('Run the health check (expect no red), walk one manual round-trip in each direction, then mark live from the wizard — which also purges the test records. Expected: the hourly monitor takes over from here.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="troubleshooting" :title="__('Troubleshooting & error reference')">
    <div class="overflow-x-auto"><table>
        <thead><tr><th>{{ __('Symptom') }}</th><th>{{ __('Cause → fix') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ __('Every Custom Action call 404s') }}</td><td>{{ __('URL missing the /v1/ segment. Full path: /api/v1/booking/{availability|create|cancel|reschedule}.') }}</td></tr>
            <tr><td>{{ __('Every call 401s') }}</td><td>{{ __('Rotated/wrong token, or the salon was deactivated. Regenerate and re-paste into all four actions.') }}</td></tr>
            <tr><td>{{ __('Cancel/reschedule 404 a real appointment') }}</td><td>{{ __('Wrong identifier — the lookup is format-blind on phone, so check the actual number/email on the client record and the stated date; multiple matches come back as 409 with the list, not 404.') }}</td></tr>
            <tr><td>{{ __('Bookings not appearing in GHL') }}</td><td>{{ __('Settings → Integrations → Sync issues names the per-booking reason (dead token → the live GHL health check catches it within the hour; unmapped stylist → the mapping step).') }}</td></tr>
            <tr><td>{{ __('GHL edits not reflected in-app') }}</td><td>{{ __('Webhook workflow off/unpublished or secret mismatch — run Test delivery; webhook_events shows what arrived and why it was ignored (echo/stale/replay are NORMAL statuses, not errors).') }}</td></tr>
            <tr><td>{{ __('Phantom status flapping between the systems') }}</td><td>{{ __('Echo protection depends on ghl_last_pushed_status — check webhook_events for ignored_echo entries; genuine conflicts resolve by GHL\'s dateUpdated (last change wins).') }}</td></tr>
            <tr><td>{{ __('Widget booking rejected as a bot') }}</td><td>{{ __('Honeypot / too-fast / stale-token gate (min 4s on page, token ≤12h). Real users retrying slower succeed; embed pages cached longer than 12h re-mint the token on reload.') }}</td></tr>
            <tr><td>{{ __('Voice AI offers times the salon disallows') }}</td><td>{{ __('GHL-side calendars drifted — run availability sync; the app\'s engine still refuses at booking time (GHL offering ≠ app accepting).') }}</td></tr>
        </tbody>
    </table></div>
</x-docs.section>

<x-docs.section id="glossary" :title="__('Glossary')">
    <div class="overflow-x-auto"><table><tbody>
        <tr><th>PIT</th><td>{{ __('GHL Private Integration Token — the per-sub-account API credential BookTheStyle stores encrypted.') }}</td></tr>
        <tr><th>{{ __('Location') }}</th><td>{{ __('GHL\'s name for a sub-account; its id links a salon to its GHL side.') }}</td></tr>
        <tr><th>{{ __('Master calendar') }}</th><td>{{ __('The one GHL team calendar whose team members are the salon\'s bookable stylists.') }}</td></tr>
        <tr><th>{{ __('Provider mapping') }}</th><td>{{ __('BookTheStyle stylist ↔ GHL calendar team member — what routes a mirrored appointment to the right person.') }}</td></tr>
        <tr><th>{{ __('Chair Rental') }}</th><td>{{ __('Salon type where stylists are independent businesses; strict per-stylist data isolation.') }}</td></tr>
        <tr><th>{{ __('Echo') }}</th><td>{{ __('The app\'s own change arriving back via the webhook — detected and ignored, never re-applied.') }}</td></tr>
        <tr><th>{{ __('Test lane') }}</th><td>{{ __('The disposable is_test records incl. the Voice AI test client — window-exempt, leak-proof, TTL-swept.') }}</td></tr>
        <tr><th>btsk</th><td>{{ __('The booking-token prefix: btsk_{salonId}_{40 hex} — the token IS the tenant scope.') }}</td></tr>
    </tbody></table></div>
</x-docs.section>

<x-docs.section id="sync" :title="__('Keeping this page in sync with the code')">
    <p>{{ __('Every BookTheStyle claim above has an owning class named beside it — when one of these changes, this page changes in the same PR: VoiceBookingController / VoiceBookingApi (endpoints, errors), BookingApiToken / AuthenticateBookingApi (auth), GhlWebhookController / GhlInboundSync (webhook, echo), GhlBookingPusher + the sync jobs (outbound), SlotEngine / BookingPolicy (engine), ConnectionDiagnostics / HealthCheckRegistry / HealthMonitor (health + test lane), AppServiceProvider (rate limits), config/booking_api.php + config/ghl.php (knobs), routes/console.php (schedule). The AgencyDocsTest suite pins this page\'s structure and its load-bearing strings, so silent drift fails CI.') }}</p>
</x-docs.section>
