{{-- SOP: setting up a new salon end to end. Written for non-technical
     teammates — plain language, numbered steps, screenshot slots the team
     fills. GHL-instance specifics render as <x-docs.fill-in> slots. --}}

<p class="mb-4">{{ __('This guide walks you through setting up a brand-new salon, start to finish: first in BookTheStyle (the calendar and booking side), then in GHL / Loopflo (the texts, emails, and phone assistant), and finally connecting the two. No technical background needed — follow it top to bottom.') }}</p>

<x-docs.callout type="note">
    {{ __('Dashed amber boxes are values that live in our GHL account — ask the technical team if one has not been filled in for you yet. Dashed grey boxes are screenshots we still need to capture.') }}
</x-docs.callout>

<x-docs.section id="before-you-start" :title="__('Before you start — gather these')">
    <p>{{ __('Everything is smoother with the salon\'s details ready before you begin. Collect from the salon:') }}</p>
    <ul>
        <li>{{ __('Business name, and the owner\'s full name, email, and phone number') }}</li>
        <li>{{ __('The list of services with prices and how long each takes') }}</li>
        <li>{{ __('Working hours — which days, what times, per stylist if they differ') }}</li>
        <li>{{ __('Staff members: names, and whether each person takes bookings') }}</li>
        <li>{{ __('Logo image and brand colours, if they have them') }}</li>
    </ul>
    <p>{{ __('And make sure you have:') }}</p>
    <ul>
        <li>{{ __('Your BookTheStyle agency login — app.bookthestyle.com/login') }}</li>
        <li>{{ __('Your GHL / Loopflo login —') }} <x-docs.fill-in>{{ __('login URL') }}</x-docs.fill-in></li>
    </ul>
    <x-docs.callout type="tip">
        {{ __('If the salon does not have everything yet, get what you can and come back — services, staff, and hours can always be added later without breaking anything.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="part-a-bookthestyle" :title="__('Part A — Set up the salon in BookTheStyle')">
    <p>{{ __('This is where the salon\'s calendar, staff, services, and booking widget live.') }}</p>

    <x-docs.step n="1" :title="__('Create the salon')">
        <p>{{ __('Log in, open the agency Dashboard, and press New salon. Enter the business name and the owner\'s name, email, and phone, then save.') }}</p>
        <x-docs.callout type="note">
            {{ __('The owner automatically gets a welcome email with a temporary password and is asked to change it at first login — that is normal, nothing to do on your side.') }}
        </x-docs.callout>
        <x-docs.screenshot :capture="__('The new-salon screen with the business and owner details filled in, before saving.')" />
    </x-docs.step>

    <x-docs.step n="2" :title="__('Choose the salon type')">
        <p>{{ __('Salons work one of three ways — pick what the salon told you:') }}</p>
        <ul>
            <li><strong>{{ __('Employee') }}</strong> — {{ __('everyone shares one calendar (a normal salon with staff).') }}</li>
            <li><strong>{{ __('Booth rental') }}</strong> — {{ __('each stylist runs their own chair and their own book.') }}</li>
            <li><strong>{{ __('Mix') }}</strong> — {{ __('a bit of both; you choose per stylist as you add them.') }}</li>
        </ul>
        <x-docs.screenshot :capture="__('The salon-type choice with the three options visible.')" />
    </x-docs.step>

    <x-docs.step n="3" :title="__('Add the staff')">
        <p>{{ __('Open the salon\'s Users page and add each person who works there. Each one gets a role:') }}</p>
        <ul>
            <li><strong>{{ __('Owner') }}</strong> — {{ __('runs everything (created automatically with the salon).') }}</li>
            <li><strong>{{ __('Manager') }}</strong> — {{ __('helps run the salon: bookings, clients, staff.') }}</li>
            <li><strong>{{ __('Stylist') }}</strong> — {{ __('does the services and takes bookings.') }}</li>
        </ul>
        <x-docs.callout type="warning">
            {{ __('If an owner or manager ALSO does hair, tick the "Takes bookings" checkbox for them — that is what gives them a calendar column and a schedule. Stylists always take bookings, so they have no checkbox.') }}
        </x-docs.callout>
        <p>{{ __('Everyone you add gets their own login by email, the same way the owner did.') }}</p>
        <x-docs.screenshot :capture="__('The Add user modal with the role dropdown open and the Takes bookings checkbox visible for a manager.')" />
    </x-docs.step>

    <x-docs.step n="4" :title="__('Add services and prices')">
        <p>{{ __('Go to Services and add each service the salon offers with its price and duration. If a particular stylist takes longer or shorter for a service, you can set a per-stylist time on the service — useful, not required.') }}</p>
        <x-docs.screenshot :capture="__('The services list with two or three services added, prices showing.')" />
    </x-docs.step>

    <x-docs.step n="5" :title="__('Set the working hours')">
        <p>{{ __('Go to Availability and set each bookable person\'s weekly hours — which days they work and from when to when. Days off stay unticked; a lunch break can be added as a break block. One-off changes (a holiday, a short day) go under date-specific hours.') }}</p>
        <x-docs.screenshot :capture="__('The availability screen showing one stylist\'s week filled in.')" />
    </x-docs.step>

    <x-docs.step n="6" :title="__('Add the branding')">
        <p>{{ __('In the salon\'s Settings, upload the logo and set their accent colour — the app and the booking widget both pick it up.') }}</p>
        <x-docs.screenshot :capture="__('The branding settings with the logo uploaded and an accent colour chosen.')" />
    </x-docs.step>

    <x-docs.step n="7" :title="__('Get the booking widget')">
        <p>{{ __('Open the Widgets page. The widget is the little booking tool that goes on the salon\'s own website. Copy the embed code and send it to whoever manages their site; the Preview button shows exactly what visitors will see.') }}</p>
        <x-docs.screenshot :capture="__('The Widgets page with the embed code visible and the preview open.')" />
    </x-docs.step>

    <x-docs.callout type="tip" :title="__('Part A done')">
        {{ __('The salon now has a calendar, staff with schedules, priced services, and a booking widget. Everything from here on is the messaging side.') }}
    </x-docs.callout>
</x-docs.section>

<x-docs.section id="part-b-ghl" :title="__('Part B — Set up the GHL account')">
    <p>{{ __('GHL / Loopflo is where texts, emails, and the Voice AI (the assistant that answers the salon\'s phone) live. Each salon gets its own GHL sub-account.') }}</p>

    <x-docs.step n="8" :title="__('Create the salon\'s sub-account')">
        <p>{{ __('Log in to GHL / Loopflo and create a new sub-account for the salon from the template snapshot') }} <x-docs.fill-in>{{ __('Loopflo snapshot name') }}</x-docs.fill-in>{{ __(', then enter the salon\'s business details.') }}</p>
        <x-docs.callout type="note">
            {{ __('The template comes pre-loaded with the standard workflows and the Voice AI setup — you are configuring, not building from scratch.') }}
        </x-docs.callout>
        <x-docs.screenshot :capture="__('The new sub-account screen with the snapshot selected.')" />
    </x-docs.step>

    <x-docs.step n="9" :title="__('Confirm contacts and tags')">
        <p>{{ __('Contacts are the salon\'s customers; tags are the labels that decide which contacts connect to BookTheStyle. Confirm the tag') }} <x-docs.fill-in>{{ __('tag name') }}</x-docs.fill-in> {{ __('exists — it ships with the template, so usually you just check it is there.') }}</p>
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
        <x-docs.screenshot :capture="__('The Workflows list with the standard ones toggled on.')" />
    </x-docs.step>

    <x-docs.step n="11" :title="__('Check the Voice AI')">
        <p>{{ __('Open the Voice AI agent (') }}<x-docs.fill-in>{{ __('where it lives in our GHL') }}</x-docs.fill-in>{{ __(') and read its greeting/persona with this salon\'s eyes — the salon name, opening line, and tone should fit. Leave the booking connection alone for now; Part C wires it.') }}</p>
        <x-docs.screenshot :capture="__('The Voice AI agent setup showing the greeting for this salon.')" />
    </x-docs.step>
</x-docs.section>

<x-docs.section id="part-c-connect" :title="__('Part C — Connect the two')">
    <p>{{ __('This is the step that lets the Voice AI book into the salon\'s real calendar.') }}</p>

    <x-docs.step n="12" :title="__('Copy the booking token from BookTheStyle into GHL')">
        <p>{{ __('In BookTheStyle, open the salon\'s Settings, go to the Integrations tab, and find the Voice-AI Booking API card. Generate the token — it is shown exactly once, so keep the window open while you paste.') }}</p>
        <x-docs.screenshot :capture="__('The Voice-AI Booking API card right after generating, token visible once.')" />
        <p>{{ __('In GHL, open the Custom Actions the Voice AI uses (') }}<x-docs.fill-in>{{ __('where the Custom Actions live') }}</x-docs.fill-in>{{ __(') and paste in, for both actions:') }}</p>
        <ul>
            <li>{{ __('Check availability address: https://app.bookthestyle.com/api/v1/booking/availability') }}</li>
            <li>{{ __('Create booking address: https://app.bookthestyle.com/api/v1/booking/create') }}</li>
            <li>{{ __('The token you just generated, as the Bearer token on both.') }}</li>
        </ul>
        <x-docs.callout type="warning">
            {{ __('This is the one place a wrong copy-paste breaks everything. The token belongs to THIS salon only — never reuse another salon\'s token, and double-check both addresses match the above exactly.') }}
        </x-docs.callout>
        <x-docs.screenshot :capture="__('The GHL Custom Action with the address and token pasted in.')" />
    </x-docs.step>

    <x-docs.step n="13" :title="__('Test it — a pretend booking')">
        <p>{{ __('First use the verify button on the salon\'s integration settings — it should come back green. Then do the real thing: call the salon\'s Voice AI number and book a fake appointment ("a haircut tomorrow afternoon"). Check that:') }}</p>
        <ul>
            <li>{{ __('the appointment appears on the salon\'s BookTheStyle calendar, and') }}</li>
            <li>{{ __('a confirmation text/email went out to the number you gave.') }}</li>
        </ul>
        <x-docs.callout type="tip">
            {{ __('If the test booking does not appear, go back to step 12 — it is almost always the address or the token.') }}
        </x-docs.callout>
        <x-docs.screenshot :capture="__('The test appointment showing on the salon calendar.')" />
    </x-docs.step>
</x-docs.section>

<x-docs.section id="checklist" :title="__('Go-live checklist')">
    <p>{{ __('Before handing over, tick every line:') }}</p>
    <ul class="bts-doc-checklist">
        <li>{{ __('Salon created with the right type') }}</li>
        <li>{{ __('Owner + staff added; Takes bookings ticked for anyone who does hair') }}</li>
        <li>{{ __('Services with prices and durations') }}</li>
        <li>{{ __('Working hours set for every bookable person') }}</li>
        <li>{{ __('Logo + accent colour added') }}</li>
        <li>{{ __('Widget embed code sent to the salon\'s web person') }}</li>
        <li>{{ __('GHL sub-account created from the template') }}</li>
        <li>{{ __('Workflows on: confirmation, reminders, no-show') }}</li>
        <li>{{ __('Voice AI greeting reads right for this salon') }}</li>
        <li>{{ __('Both Custom Action addresses + the salon\'s own token pasted into GHL') }}</li>
        <li>{{ __('Verify button green') }}</li>
        <li>{{ __('Test booking via the Voice AI landed on the calendar') }}</li>
        <li>{{ __('Confirmation message went out for the test') }}</li>
    </ul>
</x-docs.section>

<x-docs.section id="help" :title="__('Stuck? Who to ask')">
    <ul>
        <li>{{ __('Something in BookTheStyle (salon, staff, calendar):') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in></li>
        <li>{{ __('Something in GHL / Voice AI / workflows:') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in></li>
        <li>{{ __('The connection between them (test booking won\'t show up):') }} <x-docs.fill-in>{{ __('who / channel') }}</x-docs.fill-in> {{ __('— mention which step you are on.') }}</li>
    </ul>
    <x-docs.callout type="tip">
        {{ __('A screenshot of the screen you are stuck on plus the step number gets it solved fastest.') }}
    </x-docs.callout>
</x-docs.section>
