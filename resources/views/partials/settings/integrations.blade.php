{{--
    The Integrations tab, reorganized as a guided five-step setup a
    non-technical person can follow top to bottom: connect GoHighLevel →
    pick the calendar & link staff → get the booking token → set up the
    webhook → sync and prove it. Every card, form, action and check is the
    SAME one as before — only grouped under numbered step headers with a
    live done/not-done status (integrationStepStatuses). Included with the
    parent Settings component's scope; same route, same view name.
--}}
@can('manageGhlConnection', $salon)
    @php($stepStatus = $this->integrationStepStatuses)

    <x-ui.card class="flex flex-col gap-1.5">
        <h2 class="bts-card-title">{{ __('Set up your integrations, step by step') }}</h2>
        <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Work top to bottom — each step says what it is for and shows whether it is done. Finish all five and bookings, reminders and the Voice AI all run through GoHighLevel automatically. The full setup wizard covers the same ground with more hand-holding.') }}</p>
        <p class="text-[13px] text-faint">{{ trans_choice(':done of 5 steps done.|:done of 5 steps done.', collect($stepStatus)->filter(fn ($s) => $s === 'done')->count(), ['done' => collect($stepStatus)->filter(fn ($s) => $s === 'done')->count()]) }}</p>
    </x-ui.card>

    {{-- Step 1 — Connect GoHighLevel. --}}
    @include('partials.settings.integration-step', ['n' => 1, 'title' => __('Connect GoHighLevel'), 'status' => $stepStatus['connect'], 'lead' => __('Paste the Location ID and Private Integration Token from your GHL account, then test the connection. Everything else builds on this.')])
    @include('partials.settings.integrations.connect')

    {{-- Step 2 — Master calendar & staff. --}}
    @include('partials.settings.integration-step', ['n' => 2, 'title' => __('Pick the calendar and link your team'), 'status' => $stepStatus['mapping'], 'lead' => __('Choose the master GHL calendar and match each stylist to their GHL team member — that is what routes every booking to the right person.')])
    @if ($tokenIsSet)
        @include('partials.settings.integrations.mapping')
    @else
        <x-ui.card><p class="text-[14px] text-faint">{{ __('Finish step 1 first — the calendar and team lists come from your GoHighLevel account.') }}</p></x-ui.card>
    @endif

    {{-- Step 3 — Booking API token. --}}
    @include('partials.settings.integration-step', ['n' => 3, 'title' => __('Get your booking token'), 'status' => $stepStatus['token'], 'lead' => __('The secret token the GHL Voice AI uses to book through this salon\'s own engine. It is shown once — copy it straight into the GHL Custom Action.')])
    @include('partials.settings.integrations.token')

    {{-- Step 4 — Inbound webhook. --}}
    @include('partials.settings.integration-step', ['n' => 4, 'title' => __('Set up the webhook'), 'status' => $stepStatus['webhook'], 'lead' => __('Lets GoHighLevel push appointment changes back into the app, so both calendars always agree. One URL and one secret header in your GHL workflow.')])
    @if ($tokenIsSet)
        @include('partials.settings.integrations.webhook')
    @else
        <x-ui.card><p class="text-[14px] text-faint">{{ __('Finish step 1 first — the webhook secret belongs to the GoHighLevel connection.') }}</p></x-ui.card>
    @endif

    {{-- Step 5 — Sync & prove it works. --}}
    @include('partials.settings.integration-step', ['n' => 5, 'title' => __('Sync and test it'), 'status' => $stepStatus['test'], 'lead' => __('Push every stylist\'s hours into GoHighLevel, run the round-trip booking test, and keep an eye on anything that failed to mirror.')])
    @if ($tokenIsSet)
        @include('partials.settings.integrations.testing')
    @else
        <x-ui.card><p class="text-[14px] text-faint">{{ __('Finish step 1 first — syncing and testing need the live GoHighLevel connection.') }}</p></x-ui.card>
    @endif

    @can('runDiagnostics', $salon)
        @include('partials.settings.integrations.health')
    @endcan
@endcan
