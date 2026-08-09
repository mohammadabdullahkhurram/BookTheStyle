{{-- SOP: set up and run a new salon, end to end — written for the
     non-technical agency team. Every BookTheStyle fact here comes from the
     code; anything that lives only in the GHL/Loopflo instance renders as
     an <x-docs.fill-in> slot. Rebuilt from scratch 2026-08-09. --}}

<x-docs.section id="how-it-works" :title="__('How the system fits together')">
    <p>{{ __('Two products work as one. BookTheStyle is the salon\'s brain: it holds the real calendar, the staff, the services, the working hours, and it alone decides which times can be booked. GoHighLevel (GHL) is the salon\'s voice: it answers the phone with the AI receptionist, sends the confirmation and reminder texts, and holds the client conversations. Every appointment — whoever books it, however they book it — lives in BookTheStyle first and is mirrored into GHL so the reminders fire.') }}</p>

    <x-docs.figure :title="__('System overview: GoHighLevel handles conversation; BookTheStyle decides the calendar')">
        <svg viewBox="0 0 720 220" width="720" height="220" role="img" aria-label="{{ __('Diagram: clients reach GHL by phone or the salon website widget; both book through BookTheStyle; BookTheStyle mirrors appointments back to GHL, which sends reminders.') }}">
            <defs>
                <marker id="sopArrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#6B6862"/></marker>
            </defs>
            <rect x="10" y="70" width="150" height="80" rx="12" fill="#F0EEEA" stroke="#DAD5CD"/>
            <text x="85" y="103" text-anchor="middle" font-size="13" fill="#1C1B1A" font-weight="600">{{ __('Clients') }}</text>
            <text x="85" y="122" text-anchor="middle" font-size="11" fill="#6B6862">{{ __('call · text · website') }}</text>
            <rect x="240" y="20" width="200" height="80" rx="12" fill="#E3EDF6" stroke="#C4D6E8"/>
            <text x="340" y="50" text-anchor="middle" font-size="13" fill="#356088" font-weight="600">GoHighLevel</text>
            <text x="340" y="68" text-anchor="middle" font-size="11" fill="#356088">{{ __('Voice AI · texts · reminders') }}</text>
            <rect x="240" y="130" width="200" height="80" rx="12" fill="#E7EFE4" stroke="#C8DAC2"/>
            <text x="340" y="160" text-anchor="middle" font-size="13" fill="#3E5C3A" font-weight="600">BookTheStyle</text>
            <text x="340" y="178" text-anchor="middle" font-size="11" fill="#3E5C3A">{{ __('the calendar · the booking engine') }}</text>
            <rect x="530" y="70" width="170" height="80" rx="12" fill="#F6EFE2" stroke="#E2CFA4"/>
            <text x="615" y="103" text-anchor="middle" font-size="13" fill="#7A5B1F" font-weight="600">{{ __('Salon team') }}</text>
            <text x="615" y="122" text-anchor="middle" font-size="11" fill="#7A5B1F">{{ __('check-in · day-to-day') }}</text>
            <line x1="160" y1="90" x2="238" y2="62" stroke="#6B6862" stroke-width="1.5" marker-end="url(#sopArrow)"/>
            <line x1="160" y1="130" x2="238" y2="168" stroke="#6B6862" stroke-width="1.5" marker-end="url(#sopArrow)"/>
            <line x1="330" y1="102" x2="330" y2="128" stroke="#6B6862" stroke-width="1.5" marker-end="url(#sopArrow)"/>
            <line x1="350" y1="128" x2="350" y2="102" stroke="#6B6862" stroke-width="1.5" marker-end="url(#sopArrow)"/>
            <text x="365" y="119" font-size="10" fill="#6B6862">{{ __('books + mirrors') }}</text>
            <line x1="442" y1="170" x2="528" y2="120" stroke="#6B6862" stroke-width="1.5" marker-end="url(#sopArrow)"/>
        </svg>
    </x-docs.figure>

    <p>{{ __('This guide takes you from nothing to a live salon: create it in BookTheStyle (Part A), set up its people and services (Part B), prepare the GHL side (Part C), connect the two (Part D), wire the Voice AI (Part E), put the widget on the salon\'s website (Part F), then test everything and go live. Expect one to two hours of actual work for a straightforward salon, plus waiting on the salon owner for details.') }}</p>
    <x-docs.callout type="note">
        {{ __('Dashed amber boxes are values that live in our GHL account — ask if one has not been filled in for you. Dashed grey boxes are screenshots still to capture. Everything else on this page is checked against the product itself.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="before-you-start" :title="__('Before you start')">
    <p>{{ __('Gather these first — the setup goes smoothly when nothing has to stop mid-way:') }}</p>
    <ul>
        <li>{{ __('An agency owner or admin login at app.bookthestyle.com (agency "user" accounts can follow along but cannot create salons).') }}</li>
        <li>{{ __('Access to the agency\'s GoHighLevel account, with permission to create a sub-account.') }}</li>
        <li>{{ __('From the salon owner: business name, address, phone, email, timezone, the list of staff (names, emails, phone numbers, who takes bookings), the service menu (names, durations, prices), each stylist\'s weekly hours, and a logo file if they have one.') }}</li>
        <li>{{ __('The web address they want — the salon lives at its own address like glamour-studio.bookthestyle.com. Short, lowercase, hyphens only.') }}</li>
        <li>{{ __('A decision on the salon type — see the decision point in Part A; it changes what stylists can see.') }}</li>
    </ul>
    <x-docs.callout type="warning">
        {{ __('The salon\'s web address must also be created by the technical team in the hosting panel before clients use it — the address will not load securely until that is done. Put the chosen name in your handover note early (see Escalation) so it is ready before go-live.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="part-a-bookthestyle" :title="__('Part A — Create the salon in BookTheStyle')">
    <x-docs.step n="1" :title="__('Create the salon from the agency console')">
        <p>{{ __('Log in at app.bookthestyle.com → Salons → New salon. Fill in the business profile, the web-address name (the "slug"), timezone, and currency. The Owner details matter: the person named there automatically gets the salon-owner login — a temporary password is shown ONCE on screen. Copy it and pass it on securely; they must change it the first time they log in.') }}</p>
        <x-docs.screenshot :capture="__('Agency console → Salons → New salon form, owner-details section highlighted')" />
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the salon appears in the Salons list, and the owner receives the branded welcome email.') }}</p>
    </x-docs.step>

    <x-docs.step n="2" :title="__('Choose the salon type — a decision point')">
        <p>{{ __('Three types, chosen for how the salon pays its stylists:') }}</p>
        <ul>
            <li><strong>{{ __('Employee') }}</strong> — {{ __('staff on payroll. Stylists see the shared salon calendar (read-only) and their own appointments.') }}</li>
            <li><strong>{{ __('Chair Rental') }}</strong> — {{ __('independent stylists renting a chair. Each runs their own book: they see ONLY their own column, their own clients, their own revenue — never each other\'s.') }}</li>
            <li><strong>{{ __('Mix') }}</strong> — {{ __('both kinds under one roof; you choose per stylist.') }}</li>
        </ul>
        <p>{{ __('If unsure, ask the owner: "are your stylists employees, or do they rent their chair?" The agency can change the type later (the app names the consequences before applying them), so a wrong first guess is recoverable — but getting it right now avoids day-one confusion.') }}</p>
    </x-docs.step>

    <x-docs.step n="3" :title="__('Skip the GHL fields for now')">
        <p>{{ __('The creation form has optional GoHighLevel fields (Location ID, token). Unless the GHL sub-account already exists, leave them empty — Part D connects everything with better testing tools.') }}</p>
    </x-docs.step>

    <p>{{ __('A brand-new salon starts with a small set of practice records — a "Bluejaypro" test stylist, service, and two test clients, all badged TEST in the app. They exist so you can safely test booking; they are invisible to real clients, they clean themselves up automatically, and they disappear for good at go-live. Do not delete or rename them by hand.') }}</p>
</x-docs.section>

<x-docs.section id="part-b-salon-setup" :title="__('Part B — Staff, services, and hours')">
    <p>{{ __('Open the salon itself (its own web address, or from the agency Salons list) and follow the Setup wizard — it tracks all ten setup steps and shows what is missing. This part covers the app-side steps; the wizard\'s GHL steps are Parts C–E.') }}</p>

    <x-docs.step n="4" :title="__('Invite the staff')">
        <p>{{ __('Salon → Users → Add user. For each person choose a role — Owner runs the salon, Manager runs the front desk, Stylist serves clients — and tick "Takes bookings" for anyone who takes appointments. Managers CAN take bookings if the salon wants that (the checkbox appears when you pick Manager); the owner toggles it on their own row. Every invited person gets a temporary password, shown once, and must change it at first login.') }}</p>
        <x-docs.screenshot :capture="__('Salon → Users → Add user modal, role dropdown open, Takes bookings checkbox visible')" />
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('each person appears in the Users list with the right role, and the bookable ones say so.') }}</p>
    </x-docs.step>

    <x-docs.step n="5" :title="__('Build the service menu')">
        <p>{{ __('Salon → Services. For each service: name, duration in minutes, and a display price (leave the price empty for "price varies" — the app never takes payments; prices are informational). Tick the stylists who can perform each service — this matters: a service with no qualified stylist can never be booked. Per-stylist time overrides and cleanup buffers are available where one stylist is faster or needs tidy-up time.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('every active service lists at least one stylist in its Stylists column.') }}</p>
    </x-docs.step>

    <x-docs.step n="6" :title="__('Set the working hours')">
        <p>{{ __('Salon → Availability. Give every bookable person their weekly hours (split shifts are fine) and add time off or date-specific changes as needed. A stylist with no hours can never be offered to clients — the health check flags it if someone is forgotten.') }}</p>
        <x-docs.callout type="warning">
            {{ __('Hours are managed HERE and only here. Anything typed into GoHighLevel\'s own calendars is overwritten the next time BookTheStyle syncs — deliberate, so there is exactly one source of truth.') }}
        </x-docs.callout>
    </x-docs.step>

    <x-docs.step n="7" :title="__('Branding')">
        <p>{{ __('Salon → Settings → Branding: the accent colour (used across the app and the booking widget) and the logo (PNG/JPG/WebP/SVG up to 1 MB, shown at the top of the widget). The app warns if the chosen colour is too low-contrast to read.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-c-ghl" :title="__('Part C — The GoHighLevel side')">
    <x-docs.step n="8" :title="__('Create the sub-account from the snapshot')">
        <p>{{ __('In GHL, create the salon\'s sub-account from the agency snapshot') }} <x-docs.fill-in>{{ __('Loopflo snapshot name') }}</x-docs.fill-in>{{ __('. The snapshot brings the workflows (confirmations, reminders, no-show follow-ups), the Voice AI agent, and the contact tag scheme') }} <x-docs.fill-in>{{ __('tag names — e.g. bts-synced / booked') }}</x-docs.fill-in>.</p>
        <x-docs.screenshot :capture="__('GHL agency view → creating the sub-account from the snapshot')" />
    </x-docs.step>

    <x-docs.step n="9" :title="__('Add the team and the master calendar')">
        <p>{{ __('Still in GHL: add every bookable stylist as a USER on the location (Settings → My Staff), then create — or confirm the snapshot created — ONE master booking calendar, and add those stylists to it as TEAM MEMBERS. BookTheStyle later maps each stylist to their calendar team member; someone missing here simply will not appear in the mapping list.') }}</p>
        <p>{{ __('Use the same email addresses as in BookTheStyle where possible — the mapping step auto-matches people by email.') }}</p>
    </x-docs.step>

    <x-docs.step n="10" :title="__('Create the Private Integration Token (PIT)')">
        <p>{{ __('Sub-account → Settings → Private Integrations → create one for BookTheStyle. Tick EXACTLY the scopes BookTheStyle lists on its connection card — calendars (read + write), calendar events (read + write), calendar groups (read), contacts (read + write), users (read); the card has a copy button with the precise list. GHL shows the token ONCE — copy it straight into the password manager.') }}</p>
        <x-docs.callout type="warning">
            {{ __('The PIT is a secret with real power over the sub-account. Never paste it into chat or email; hand it over via the password manager only. If it leaks, delete it in GHL and create a new one — BookTheStyle accepts the replacement on the same connection card.') }}
        </x-docs.callout>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-d-connect" :title="__('Part D — Connect the two')">
    <p>{{ __('Everything in this part lives on ONE screen: the salon → Settings → Integrations, organized as sub-tabs (Connection · Calendar & staff · Booking token · Webhook · Sync & testing), each with a status dot — green means done. Work through them left to right; the Setup wizard\'s GHL steps mirror the same work.') }}</p>

    <x-docs.step n="11" :title="__('Connect: Location ID + token, then test')">
        <p>{{ __('On the Connection tab: paste the sub-account\'s Location ID and the PIT, save, then press "Test connection" — BookTheStyle makes a real call to GHL with the stored token and tells you plainly whether it worked. The token is stored encrypted and never shown again.') }}</p>
        <x-docs.screenshot :capture="__('Settings → Integrations, step 1 showing the connection card Connected')" />
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the card shows Connected with a last-verified time, and the Connection tab\'s dot turns green.') }}</p>
    </x-docs.step>

    <x-docs.step n="12" :title="__('Map the calendar and the team')">
        <p>{{ __('On the Calendar & staff tab: press "Load from GoHighLevel", pick the master calendar, and match each BookTheStyle stylist to their GHL team member (email matches are pre-suggested — check them). Other staff (owner, managers) link to GHL users for attribution only; that never makes them bookable. Save, then "Verify mapping".') }}</p>
        <p>{{ __('A stylist missing from a dropdown is not a team member on that calendar in GHL — fix it there (edit calendar → team members), reload, map again.') }}</p>
    </x-docs.step>

    <x-docs.step n="13" :title="__('The webhook — GHL changes flow back')">
        <p>{{ __('On the Webhook tab: press "Generate secret". Then in GHL build the inbound workflow: trigger on appointment changes, action = Webhook (POST) to the URL shown on the card, with a custom header named X-Webhook-Secret set to the shown secret. Publish it. Back in BookTheStyle press "Test delivery" — the app pings its own public address exactly the way GHL will.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('Test delivery reports success (it only runs on the live site, and says so honestly otherwise).') }}</p>
    </x-docs.step>

    <x-docs.step n="14" :title="__('Push the schedules into GHL')">
        <p>{{ __('On the Sync & testing tab: press "Sync availability to GoHighLevel". Each mapped stylist\'s weekly hours and time off are mirrored into GHL so its calendars and AI only ever offer times BookTheStyle would allow. Every stylist row should end up marked Synced; "Verify in GoHighLevel" reads the schedules back as proof.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-e-voice" :title="__('Part E — The Voice AI')">
    <x-docs.step n="15" :title="__('Generate the booking token')">
        <p>{{ __('On the Booking token tab: press "Generate token". This is the key the Voice AI uses to book through the salon\'s own engine. It is shown ONCE — keep it on screen (or in the password manager) until the Custom Actions in the next step are saved. Regenerating later invalidates the old token immediately.') }}</p>
    </x-docs.step>

    <x-docs.step n="16" :title="__('Wire the four Custom Actions in GHL')">
        <p>{{ __('In GHL (AI Agents → the voice agent') }} <x-docs.fill-in>{{ __('agent name / persona') }}</x-docs.fill-in> {{ __('→ Custom Actions) create the four actions — check availability, book, cancel, reschedule — exactly as the technical reference\'s endpoint section lists them. The three details that bite:') }}</p>
        <ul>
            <li>{{ __('Every URL must contain /v1/ — the address is app.bookthestyle.com/api/v1/booking/… ; leaving /v1/ out makes every call fail with "not found".') }}</li>
            <li>{{ __('The Authorization header is "Bearer" plus the token from step 15.') }}</li>
            <li>{{ __('Dates and times are SEPARATE parameters (a date like 2026-07-25, a time like 2:00 PM) — GHL mangles combined date-times.') }}</li>
        </ul>
        <x-docs.screenshot :capture="__('GHL Custom Action editor for the booking action: URL with /v1/, Bearer header, separate date and time parameters')" />
    </x-docs.step>

    <x-docs.step n="17" :title="__('Test the Voice AI safely — the designated test client')">
        <p>{{ __('Test calls should never touch real data. Book, cancel, and reschedule as "Bluejaypro Voice AI Test Client", phone +1 555 010 0001 — one of the salon\'s built-in practice records. This client is special: any date works, even years ahead, so tests can never collide with real appointments — and everything it books is invisible to clients and cleaned up automatically.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the AI books it, the appointment appears on the salon calendar badged TEST, and a cancel call by the same phone number cancels it.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-f-widget" :title="__('Part F — The booking widget')">
    <x-docs.step n="18" :title="__('Brand it and hand over the embed code')">
        <p>{{ __('Salon → Widgets: a default booking widget already exists. Adjust its branding if wanted, then copy the embed snippet and send it to whoever runs the salon\'s website — a couple of lines pasted where the booking form should appear; it sizes itself automatically. Use the preview to walk the whole client flow yourself first.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('a booking made through the widget on the real site appears in Appointments marked as coming from the widget, and shows up in GHL with a reminder scheduled.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="verify-go-live" :title="__('Test everything & go live')">
    <x-docs.step n="19" :title="__('Run the health check')">
        <p>{{ __('Settings → Integrations → Health check (agency logins only). Confirm your password and press Run: twenty-plus checks cover the connection, the booking engine (it books a real practice appointment on the test records — always at 2:00 PM on 28 June 3004, a date nobody real can ever book, so it never collides with anything), the webhook, the scheduler, salon readiness, and data integrity. Fix anything red using the plain-language hint on its line; warnings are worth reading but not always blockers.') }}</p>
        <x-docs.screenshot :capture="__('Health check results with every category green')" />
    </x-docs.step>

    <x-docs.step n="20" :title="__('The full round-trip, by hand')">
        <p>{{ __('Prove each direction once, with the test client from Part E: book in-app → it appears in GHL with a reminder scheduled; book by phone through the AI → it appears in-app tagged Voice AI; cancel by phone → it cancels in both; check someone in → nothing bounces back as a duplicate. Then remove the practice data (the health-check page offers "Remove test data") — or leave it; going live clears it automatically.') }}</p>
    </x-docs.step>

    <x-docs.step n="21" :title="__('Mark the salon live')">
        <p>{{ __('The Setup wizard\'s summary shows every remaining gap; when all ten steps are done, press go live. That stamps the salon live and removes the practice records for good. From here on, real clients are booking — treat changes with care.') }}</p>
    </x-docs.step>
</x-docs.section>

<x-docs.section id="day-to-day" :title="__('Running the salon day to day')">
    <ul>
        <li><strong>{{ __('Check-in:') }}</strong> {{ __('the Check-in screen lists today; arriving clients are checked in with one tap. Appointments still sitting at "Booked" after they end can auto-mark as no-shows if the salon turns that on (Settings → Booking policy → automation) — otherwise the desk marks them by hand.') }}</li>
        <li><strong>{{ __('Cancel vs delete:') }}</strong> {{ __('CANCEL is the everyday action — it keeps the record and tells GHL, so reminders stop. DELETE (owners and agency logins only) permanently removes a record; deleting a service, client, or stylist never deletes their appointments — those stay, showing the removed name marked "(removed)". Prefer Deactivate/Cancel unless something truly must go.') }}</li>
        <li><strong>{{ __('Hours changes:') }}</strong> {{ __('edit availability in BookTheStyle only; it flows to GHL on the next sync.') }}</li>
        <li><strong>{{ __('Speed of sync:') }}</strong> {{ __('GHL mirrors land within about a minute, not instantly — normal. Anything that failed to mirror queues under Settings → Integrations → Sync issues with a Retry button; bookings are never blocked by a sync problem.') }}</li>
        <li><strong>{{ __('Watchfulness is automatic:') }}</strong> {{ __('once live, a read-only health monitor re-checks every salon hourly and emails the agency admins if something that was passing starts failing.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="troubleshooting" :title="__('Troubleshooting — if you see X, do Y')">
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('You see') }}</th><th>{{ __('Do this') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('"Test connection" fails') }}</td><td>{{ __('Re-check the Location ID; if it still fails the PIT is wrong or was recreated without all scopes — make a fresh one with the exact list from the connection card.') }}</td></tr>
                <tr><td>{{ __('A stylist is missing from the mapping dropdown') }}</td><td>{{ __('They are not a team member on the master calendar in GHL. Add them there, press Load from GoHighLevel again.') }}</td></tr>
                <tr><td>{{ __('The Voice AI cannot book / every call fails') }}</td><td>{{ __('Almost always the Custom Action URL (missing /v1/) or a stale token after a regeneration. Check both against Part E.') }}</td></tr>
                <tr><td>{{ __('Bookings appear in BookTheStyle but never in GHL') }}</td><td>{{ __('Open Settings → Integrations → Sync issues and read the per-booking reason — a broken connection or unmapped stylist is named there. The hourly monitor emails the agency when this starts happening.') }}</td></tr>
                <tr><td>{{ __('A GHL-side change never shows in the app') }}</td><td>{{ __('The webhook workflow is off, unpublished, or its secret was rotated without updating the header. Re-run "Test delivery".') }}</td></tr>
                <tr><td>{{ __('A client says reminders stopped') }}</td><td>{{ __('Reminders come from GHL workflows — check the workflow is on and the appointment actually exists in GHL (Sync issues again).') }}</td></tr>
                <tr><td>{{ __('The salon\'s address shows a security error') }}</td><td>{{ __('The subdomain was never created in the hosting panel — escalate to the technical team; nothing in the app fixes this.') }}</td></tr>
                <tr><td>{{ __('Practice/TEST records lingering') }}</td><td>{{ __('Settings → Integrations → Health check → "Remove test data" — or ignore them; they expire on their own and vanish at go-live.') }}</td></tr>
            </tbody>
        </table>
    </div>
</x-docs.section>

<x-docs.section id="escalation" :title="__('Escalation — who to ask')">
    <ul>
        <li>{{ __('Salon content questions (services, prices, hours, staff) → the salon owner.') }}</li>
        <li>{{ __('GHL account questions (sub-accounts, snapshot, workflows, the AI agent) →') }} <x-docs.fill-in>{{ __('GHL owner on the team') }}</x-docs.fill-in>.</li>
        <li>{{ __('Anything technical (the salon address / hosting panel, API errors that survive the troubleshooting table, deploys) →') }} <x-docs.fill-in>{{ __('technical escalation contact') }}</x-docs.fill-in>.</li>
        <li>{{ __('When escalating, say WHICH salon, WHAT you pressed, and WHAT the screen said — a screenshot of the health-check page answers most questions in one image.') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="checklist" :title="__('Go-live checklist')">
    <p>{{ __('Print-worthy. Every line should be true before real clients book:') }}</p>
    <ul class="bts-doc-checklist">
        <li>{{ __('Salon created; owner has logged in and changed the temporary password') }}</li>
        <li>{{ __('Salon type confirmed with the owner (Employee / Chair Rental / Mix)') }}</li>
        <li>{{ __('All staff invited, right roles, "Takes bookings" correct') }}</li>
        <li>{{ __('Every active service has a duration and at least one qualified stylist') }}</li>
        <li>{{ __('Every bookable person has weekly hours') }}</li>
        <li>{{ __('GHL sub-account created from the snapshot; staff added as users; master calendar with team members') }}</li>
        <li>{{ __('Connection tested green; mapping verified; webhook delivery tested; availability synced') }}</li>
        <li>{{ __('Booking token generated and wired into all four Custom Actions (URLs contain /v1/)') }}</li>
        <li>{{ __('Voice AI booked, rescheduled, and cancelled the designated test client successfully') }}</li>
        <li>{{ __('Widget embedded on the salon website; a test booking arrived tagged correctly') }}</li>
        <li>{{ __('Health check run: no red lines') }}</li>
        <li>{{ __('Salon address created in the hosting panel and loading securely') }}</li>
        <li>{{ __('Setup wizard shows every step done → marked live') }}</li>
    </ul>
    <p class="text-[12.5px] text-faint">{{ __('Revision: 2026-08-09 — rebuilt from scratch against the current product.') }}</p>
</x-docs.section>
