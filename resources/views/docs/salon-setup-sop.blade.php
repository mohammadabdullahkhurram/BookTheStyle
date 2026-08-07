{{-- SOP: setting up a new salon end to end. Written for non-technical
     teammates — plain language, numbered steps with expected results,
     decision points, verification passes, troubleshooting, escalation.
     GHL-instance specifics render as <x-docs.fill-in> slots. --}}

<x-docs.section id="purpose" :title="__('Purpose & scope')">
    <p>{{ __('This guide takes you from "a salon signed up" to "their phone assistant books real appointments onto their real calendar". It covers three stages: setting the salon up in BookTheStyle (Part A), setting up their GHL / Loopflo account (Part B), and connecting the two (Part C) — plus how to check your work, what to do when something looks wrong, and who to ask.') }}</p>
    <ul>
        <li><strong>{{ __('Who it is for') }}</strong> — {{ __('anyone on the agency team. No technical background needed; a new hire should be able to follow it end to end.') }}</li>
        <li><strong>{{ __('What it does not cover') }}</strong> — {{ __('day-to-day salon usage (the salon\'s own staff learn that in-app) and API internals (the Technical group covers those).') }}</li>
    </ul>
    <x-docs.callout type="note">
        {{ __('Dashed amber boxes are values that live in our GHL account — ask the technical team if one has not been filled in for you yet. Dashed grey boxes are screenshots we still need to capture.') }}</x-docs.callout>
    <p class="text-[12.5px] text-faint">{{ __('Revision: 2026-08-07.') }}</p>
</x-docs.section>

<x-docs.section id="before-you-start" :title="__('Before you start — access & information')">
    <p><strong>{{ __('Access you need:') }}</strong></p>
    <ul>
        <li>{{ __('Your BookTheStyle agency login — app.bookthestyle.com/login') }}</li>
        <li>{{ __('Your GHL / Loopflo login —') }} <x-docs.fill-in>{{ __('login URL') }}</x-docs.fill-in></li>
        <li>{{ __('Someone with hPanel access has created the salon\'s web address (its subdomain) — if you are not sure, ask the technical team BEFORE starting; nothing works on an address that does not exist yet.') }}</li>
    </ul>
    <p><strong>{{ __('Information to gather from the salon:') }}</strong></p>
    <ul class="bts-doc-checklist">
        <li>{{ __('Business name, address, and phone number') }}</li>
        <li>{{ __('Owner\'s full name, email, and phone number') }}</li>
        <li>{{ __('How the salon works: employees on one calendar, chair renters, or a mix (this decides the salon type in step 2)') }}</li>
        <li>{{ __('Every staff member\'s name + whether they take appointments') }}</li>
        <li>{{ __('The service list with prices and how long each service takes') }}</li>
        <li>{{ __('Working hours — per person if they differ') }}</li>
        <li>{{ __('Logo image and brand colour, if they have them') }}</li>
        <li>{{ __('Who manages their website (they will receive the booking widget)') }}</li>
    </ul>
    <x-docs.callout type="tip">
        {{ __('Missing pieces? Start anyway with what you have — services, staff, hours, and branding can all be added later without breaking anything. Only the owner\'s name and email are truly needed up front.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="part-a-bookthestyle" :title="__('Part A — Set up the salon in BookTheStyle')">
    <p>{{ __('This is where the salon\'s calendar, staff, services, and booking widget live.') }}</p>

    <x-docs.step n="1" :title="__('Create the salon')">
        <p>{{ __('Log in, open the agency Dashboard, and press New salon. Enter the business name and the owner\'s name, email, and phone, then save.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the salon appears in your Salons list, and the owner receives a welcome email with a temporary password (they are asked to change it at first login — normal, nothing for you to do).') }}</p>
        <x-docs.screenshot :capture="__('The new-salon screen with the business and owner details filled in, before saving.')" />
    </x-docs.step>

    <x-docs.step n="2" :title="__('Choose the salon type — a decision point')">
        <p>{{ __('Salons work one of three ways. Pick what the salon told you — this changes how calendars behave:') }}</p>
        <ul>
            <li><strong>{{ __('Employee') }}</strong> — {{ __('everyone works for the salon and shares one calendar. Most common.') }}</li>
            <li><strong>{{ __('Chair Rental') }}</strong> — {{ __('each stylist rents their chair and runs their own book: their own calendar, their own clients, their own revenue view.') }}</li>
            <li><strong>{{ __('Mix') }}</strong> — {{ __('some employees, some chair renters — you will choose which, per stylist, as you add them in step 3.') }}</li>
        </ul>
        <x-docs.callout type="warning">
            {{ __('Not sure? Ask the salon "do any of your stylists rent their chair?" If the answer is no, choose Employee. Guessing wrong here means redoing staff arrangements later.') }}
        </x-docs.callout>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the type shows on the salon\'s settings; for Mix salons the Add-user form starts offering the per-stylist arrangement choice.') }}</p>
        <x-docs.screenshot :capture="__('The salon-type choice with the three options visible.')" />
    </x-docs.step>

    <x-docs.step n="3" :title="__('Add the staff')">
        <p>{{ __('Open the salon\'s Users page and add each person. Each gets a role:') }}</p>
        <ul>
            <li><strong>{{ __('Owner') }}</strong> — {{ __('runs everything (already created with the salon).') }}</li>
            <li><strong>{{ __('Manager') }}</strong> — {{ __('helps run the salon: bookings, clients, staff.') }}</li>
            <li><strong>{{ __('Stylist') }}</strong> — {{ __('does the services and takes bookings.') }}</li>
        </ul>
        <p>{{ __('Two decisions per person:') }}</p>
        <ul>
            <li>{{ __('Does an owner or manager ALSO do hair? Tick their "Takes bookings" checkbox — that gives them a calendar column and a schedule. Stylists always take bookings, so they have no checkbox.') }}</li>
            <li>{{ __('In a Mix salon, each stylist is either an Employee or a Chair renter — pick per person as you add them.') }}</li>
        </ul>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('every person is listed with the right role; anyone who takes bookings shows the Takes bookings badge (managers/owners) or the Stylist role; each got their own welcome email.') }}</p>
        <x-docs.screenshot :capture="__('The Add user modal with the role dropdown open and the Takes bookings checkbox visible for a manager.')" />
    </x-docs.step>

    <x-docs.step n="4" :title="__('Add services and prices')">
        <p>{{ __('Go to Services and add each service with its price and duration. If a particular stylist takes more or less time for a service, set the per-stylist duration on that service — useful, not required. Assign which stylists perform each service; only qualified stylists are offered when clients book.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the list shows every service with a price, and opening one shows at least one assigned stylist.') }}</p>
        <x-docs.screenshot :capture="__('The services list with a few services added, prices showing, one open with stylists assigned.')" />
    </x-docs.step>

    <x-docs.step n="5" :title="__('Set the working hours')">
        <p>{{ __('Go to Availability and set each bookable person\'s weekly hours — days, start and end times, split shifts and lunch breaks if needed. One-off changes (a holiday, a short day) go under date-specific hours.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('each bookable person\'s card summarises a sensible week (not "no hours"), and the calendar shows their column shaded for working time.') }}</p>
        <x-docs.screenshot :capture="__('The availability screen showing one stylist\'s week filled in.')" />
    </x-docs.step>

    <x-docs.step n="6" :title="__('Add the branding')">
        <p>{{ __('In the salon\'s Settings, upload the logo and set their accent colour — the app and the booking widget both pick it up.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the salon\'s pages immediately show the accent colour, and the logo appears where the salon name is used.') }}</p>
        <x-docs.screenshot :capture="__('The branding settings with the logo uploaded and an accent colour chosen.')" />
    </x-docs.step>

    <x-docs.step n="7" :title="__('Get the booking widget')">
        <p>{{ __('Open the Widgets page. The widget is the booking tool for the salon\'s own website. Copy the embed code and send it to whoever manages their site; the Preview button shows exactly what visitors will see.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the preview opens, shows the salon\'s services with their branding, and walks to a bookable time.') }}</p>
        <x-docs.screenshot :capture="__('The Widgets page with the embed code visible and the preview open.')" />
    </x-docs.step>

    <h3 id="verify-part-a">{{ __('Check your work — Part A') }}</h3>
    <ul class="bts-doc-checklist">
        <li>{{ __('The calendar shows a column for every person who takes bookings') }}</li>
        <li>{{ __('Make a quick in-app test booking (New booking): pick a service — sensible times appear — book it, then cancel it') }}</li>
        <li>{{ __('The widget preview reaches a real time slot') }}</li>
    </ul>
    <x-docs.callout type="tip" :title="__('Part A done')">
        {{ __('The salon works on its own now: staff can log in, the desk can book, the widget can go on their site. Everything from here is the messaging/phone side.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="part-b-ghl" :title="__('Part B — Set up the GHL account')">
    <p>{{ __('GHL / Loopflo is where texts, emails, and the Voice AI (the assistant that answers the salon\'s phone) live. Each salon gets its own GHL sub-account.') }}</p>

    <x-docs.step n="8" :title="__('Create the salon\'s sub-account')">
        <p>{{ __('Log in to GHL / Loopflo and create a new sub-account for the salon from the template snapshot') }} <x-docs.fill-in>{{ __('Loopflo snapshot name') }}</x-docs.fill-in>{{ __(', then enter the salon\'s business details.') }}</p>
        <x-docs.callout type="note">
            {{ __('The template comes pre-loaded with the standard workflows and the Voice AI setup — you are configuring, not building from scratch.') }}
        </x-docs.callout>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the sub-account opens with the template\'s workflows visible in its Workflows list.') }}</p>
        <x-docs.screenshot :capture="__('The new sub-account screen with the snapshot selected.')" />
    </x-docs.step>

    <x-docs.step n="9" :title="__('Confirm contacts and tags')">
        <p>{{ __('Contacts are the salon\'s customers; tags are labels that decide which contacts connect to BookTheStyle. Confirm the tag') }} <x-docs.fill-in>{{ __('tag name') }}</x-docs.fill-in> {{ __('exists — it ships with the template, so usually you just check it is there.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the tag appears in the sub-account\'s tag list.') }}</p>
        <x-docs.screenshot :capture="__('The tags list showing the sync tag.')" />
    </x-docs.step>

    <x-docs.step n="10" :title="__('Turn on the workflows')">
        <p>{{ __('Workflows are the automatic messages. Confirm these are on:') }}</p>
        <ul>
            <li><strong>{{ __('Booking confirmation') }}</strong> — {{ __('texts/emails the customer when they book.') }}</li>
            <li><strong>{{ __('Reminders') }}</strong> — {{ __('nudges before the appointment.') }}</li>
            <li><strong>{{ __('No-show follow-up') }}</strong> — {{ __('reaches out if they miss it.') }}</li>
            <li><x-docs.fill-in>{{ __('any others in our template') }}</x-docs.fill-in></li>
        </ul>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('each shows as published/active in the Workflows list, not draft.') }}</p>
        <x-docs.screenshot :capture="__('The Workflows list with the standard ones toggled on.')" />
    </x-docs.step>

    <x-docs.step n="11" :title="__('Check the Voice AI')">
        <p>{{ __('Open the Voice AI agent (') }}<x-docs.fill-in>{{ __('where it lives in our GHL') }}</x-docs.fill-in>{{ __(') and read its greeting/persona with this salon\'s eyes — the salon name, opening line, and tone should fit. Leave the booking connection alone for now; Part C wires it.') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the greeting mentions THIS salon (not the template\'s example name).') }}</p>
        <x-docs.screenshot :capture="__('The Voice AI agent setup showing the greeting for this salon.')" />
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-c-connect" :title="__('Part C — Connect the two')">
    <p>{{ __('This is the step that lets the Voice AI book into the salon\'s real calendar.') }}</p>

    <x-docs.step n="12" :title="__('Copy the booking token from BookTheStyle into GHL')">
        <p>{{ __('In BookTheStyle, open the salon\'s Settings → Integrations tab → the Voice-AI Booking API card. Press generate — the token is shown exactly once, so keep the window open while you paste.') }}</p>
        <x-docs.screenshot :capture="__('The Voice-AI Booking API card right after generating, token visible once.')" />
        <p>{{ __('In GHL, open the Custom Actions the Voice AI uses (') }}<x-docs.fill-in>{{ __('where the Custom Actions live') }}</x-docs.fill-in>{{ __(') and paste in, for BOTH actions:') }}</p>
        <ul>
            <li>{{ __('Check availability address: https://app.bookthestyle.com/api/v1/booking/availability') }}</li>
            <li>{{ __('Create booking address: https://app.bookthestyle.com/api/v1/booking/create') }}</li>
            <li>{{ __('The token you just generated, as the Bearer token on both.') }}</li>
        </ul>
        <x-docs.callout type="warning">
            {{ __('This is the one place a wrong copy-paste breaks everything. The token belongs to THIS salon only — never reuse another salon\'s token, and double-check both addresses match exactly.') }}
        </x-docs.callout>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('both Custom Actions save without error and show the new token (masked).') }}</p>
        <x-docs.screenshot :capture="__('The GHL Custom Action with the address and token pasted in.')" />
    </x-docs.step>

    <x-docs.step n="13" :title="__('Test it — a pretend booking')">
        <p>{{ __('First press the verify button on the salon\'s Integrations card — it should come back green. Then do the real thing: call the salon\'s Voice AI number and book a fake appointment ("a haircut tomorrow afternoon").') }}</p>
        <p><strong>{{ __('How you know it worked:') }}</strong> {{ __('the appointment appears on the salon\'s BookTheStyle calendar within a minute, AND a confirmation text/email arrives at the details you gave. Cancel the test booking in the app afterwards.') }}</p>
        <x-docs.screenshot :capture="__('The test appointment showing on the salon calendar.')" />
    </x-docs.step>
</x-docs.section>

<x-docs.section id="verification" :title="__('Final verification — the full pass')">
    <p>{{ __('Before handing over, run this QA sweep end to end (five minutes):') }}</p>
    <ul class="bts-doc-checklist">
        <li>{{ __('Book in-app at the desk → appears on the calendar → cancel it') }}</li>
        <li>{{ __('Book through the widget preview → appears on the calendar → cancel it') }}</li>
        <li>{{ __('Book by phone via the Voice AI → appears on the calendar AND the confirmation message arrives → cancel it') }}</li>
        <li>{{ __('The owner can log in (ask them to try) and sees their salon, staff, and services') }}</li>
        <li>{{ __('Every integration verify button on the Integrations tab is green') }}</li>
    </ul>
    <p>{{ __('All five pass? The salon is live. Any fail → the troubleshooting section below.') }}</p>
</x-docs.section>

<x-docs.section id="troubleshooting" :title="__('Troubleshooting — if you see X, do Y')">
    <div class="overflow-x-auto">
        <table>
            <thead><tr><th>{{ __('If you see…') }}</th><th>{{ __('Do this') }}</th></tr></thead>
            <tbody>
                <tr><td>{{ __('The owner never got the welcome email') }}</td><td>{{ __('Check the address on their user entry (Edit details), fix any typo, then use Reset password to send fresh credentials.') }}</td></tr>
                <tr><td>{{ __('No times appear when test-booking') }}</td><td>{{ __('Almost always hours or services: confirm step 5 hours exist for a bookable person, and step 4 assigned that person to the service.') }}</td></tr>
                <tr><td>{{ __('A stylist has no calendar column') }}</td><td>{{ __('They are not bookable — for an owner/manager, tick Takes bookings (step 3); a stylist row always has one, so check they were added as Stylist.') }}</td></tr>
                <tr><td>{{ __('The Voice AI answers but says it cannot book') }}</td><td>{{ __('Part C problem: re-check both Custom Action addresses and the token (step 12), then the verify button.') }}</td></tr>
                <tr><td>{{ __('The test booking never appears on the calendar') }}</td><td>{{ __('Same as above — address or token. If verify is green and it still fails, escalate with the time of your call.') }}</td></tr>
                <tr><td>{{ __('Booking works but no confirmation message') }}</td><td>{{ __('The booking side is fine; a workflow is off or its trigger/tag is wrong (step 10) — check it is published and the contact got tagged.') }}</td></tr>
                <tr><td>{{ __('The widget shows the wrong colours/name') }}</td><td>{{ __('Branding (step 6) — re-check logo and accent; the widget picks them up immediately.') }}</td></tr>
                <tr><td>{{ __('The salon\'s web address does not load at all') }}</td><td>{{ __('The subdomain was never created in hPanel — stop and escalate to the technical team; this is not fixable from the app.') }}</td></tr>
            </tbody>
        </table>
    </div>
</x-docs.section>

<x-docs.section id="escalation" :title="__('Escalation — who to ask')">
    <ul>
        <li>{{ __('Something in BookTheStyle (salon, staff, calendar):') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in></li>
        <li>{{ __('Something in GHL / Voice AI / workflows:') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in></li>
        <li>{{ __('The connection between them (verify red, test booking missing):') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in> {{ __('— mention which step you are on.') }}</li>
        <li>{{ __('The salon\'s web address / hosting:') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in></li>
    </ul>
    <x-docs.callout type="tip">
        {{ __('A screenshot of the screen you are stuck on plus the step number gets it solved fastest.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="checklist" :title="__('Go-live checklist')">
    <ul class="bts-doc-checklist">
        <li>{{ __('Salon created with the right type (Employee / Chair Rental / Mix)') }}</li>
        <li>{{ __('Owner + staff added; Takes bookings ticked for anyone who does hair; chair renters marked in Mix salons') }}</li>
        <li>{{ __('Services with prices, durations, and assigned stylists') }}</li>
        <li>{{ __('Working hours set for every bookable person') }}</li>
        <li>{{ __('Logo + accent colour added') }}</li>
        <li>{{ __('Widget embed code sent to the salon\'s web person') }}</li>
        <li>{{ __('GHL sub-account created from the template') }}</li>
        <li>{{ __('Sync tag confirmed; workflows on: confirmation, reminders, no-show') }}</li>
        <li>{{ __('Voice AI greeting reads right for this salon') }}</li>
        <li>{{ __('Both Custom Action addresses + this salon\'s own token pasted into GHL') }}</li>
        <li>{{ __('Verify button green') }}</li>
        <li>{{ __('Full verification pass done (in-app, widget, and phone bookings all landed and were cancelled)') }}</li>
        <li>{{ __('Owner has logged in successfully') }}</li>
    </ul>
</x-docs.section>
