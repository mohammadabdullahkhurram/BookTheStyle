{{--
    The Integrations tab, organized as SUB-TABS — one section on screen at
    a time (Connection · Calendar & staff · Booking token · Webhook · Sync
    & testing). Reuses the app's existing tab pattern (Alpine x-data +
    x-show with all content kept in the DOM, exactly like the parent
    Settings tabs, so every wire binding and action behaves unchanged).
    Each sub-tab button carries a live status dot from
    integrationStepStatuses (green done · amber needs attention · grey not
    set up). Every card, form, action, verify button and gate is the SAME
    one as before — only the presentation changed. Included with the parent
    Settings component's scope; same route, same view name.
--}}
@can('manageGhlConnection', $salon)
    @php($subStatus = $this->integrationStepStatuses)
    @php($subTabs = [
        'connect' => ['label' => __('Connection'), 'status' => $subStatus['connect']],
        'mapping' => ['label' => __('Calendar & staff'), 'status' => $subStatus['mapping']],
        'token' => ['label' => __('Booking token'), 'status' => $subStatus['token']],
        'webhook' => ['label' => __('Webhook'), 'status' => $subStatus['webhook']],
        'testing' => ['label' => __('Sync & testing'), 'status' => $subStatus['test']],
    ])

    <div x-data="{ sub: 'connect' }" class="flex flex-col gap-5">
        <nav class="flex gap-1 overflow-x-auto border-b border-divider pb-2" aria-label="{{ __('Integration sections') }}">
            @foreach ($subTabs as $key => $tab)
                @php($dot = match ($tab['status']) { 'done' => '#7FA379', 'attention' => '#D9A441', default => '#C9C4BC' })
                @php($statusLabel = match ($tab['status']) { 'done' => __('Done'), 'attention' => __('Needs attention'), default => __('Not set up') })
                <button type="button" x-on:click="sub = {{ Js::from($key) }}" :aria-current="sub === {{ Js::from($key) }} ? 'page' : null"
                        aria-label="{{ $tab['label'] }} — {{ $statusLabel }}"
                        class="bts-nav-item flex shrink-0 items-center gap-2 whitespace-nowrap" :class="sub === {{ Js::from($key) }} && 'bts-nav-item-active'">
                    <span class="inline-block size-2 shrink-0 rounded-full" style="background-color:{{ $dot }};" aria-hidden="true"></span>
                    <span>{{ $tab['label'] }}</span>
                </button>
            @endforeach
        </nav>

        {{-- Connection — the GHL credentials + live test. --}}
        <section x-show="sub === 'connect'" x-cloak class="flex flex-col gap-5">
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Paste the Location ID and Private Integration Token from the salon\'s GHL sub-account, then test the connection. Everything else builds on this.') }}</p>
            @include('partials.settings.integrations.connect')
        </section>

        {{-- Calendar & staff — mapping + contact-sync verify. --}}
        <section x-show="sub === 'mapping'" x-cloak class="flex flex-col gap-5">
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Choose the master GHL calendar and match each stylist to their GHL team member — that is what routes every booking to the right person.') }}</p>
            @if ($tokenIsSet)
                @include('partials.settings.integrations.mapping')
            @else
                <x-ui.card><p class="text-[14px] text-faint">{{ __('Connect GoHighLevel first (the Connection tab) — the calendar and team lists come from the salon\'s GHL account.') }}</p></x-ui.card>
            @endif
        </section>

        {{-- Booking token — the Voice AI API credential. --}}
        <section x-show="sub === 'token'" x-cloak class="flex flex-col gap-5">
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('The secret token the GHL Voice AI uses to book through this salon\'s own engine. Shown once — copy it straight into the GHL Custom Actions.') }}</p>
            @include('partials.settings.integrations.token')
        </section>

        {{-- Webhook — GHL changes flowing back. --}}
        <section x-show="sub === 'webhook'" x-cloak class="flex flex-col gap-5">
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Lets GoHighLevel push appointment changes back into the app, so both calendars always agree. One URL and one secret header in the GHL workflow.') }}</p>
            @if ($tokenIsSet)
                @include('partials.settings.integrations.webhook')
            @else
                <x-ui.card><p class="text-[14px] text-faint">{{ __('Connect GoHighLevel first (the Connection tab) — the webhook secret belongs to the connection.') }}</p></x-ui.card>
            @endif
        </section>

        {{-- Sync & testing — availability push, round-trip, sync issues, health. --}}
        <section x-show="sub === 'testing'" x-cloak class="flex flex-col gap-5">
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Push every stylist\'s hours into GoHighLevel, run the round-trip booking test, and keep an eye on anything that failed to mirror.') }}</p>
            @if ($tokenIsSet)
                @include('partials.settings.integrations.testing')
            @else
                <x-ui.card><p class="text-[14px] text-faint">{{ __('Connect GoHighLevel first (the Connection tab) — syncing and testing need the live connection.') }}</p></x-ui.card>
            @endif
            @can('runDiagnostics', $salon)
                @include('partials.settings.integrations.health')
            @endcan
        </section>
    </div>
@endcan
