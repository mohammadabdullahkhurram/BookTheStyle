<?php

use App\Actions\Salons\GenerateBookingApiToken;
use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\UpdateGhlConnection;
use App\Jobs\SyncAvailabilityToGhl;
use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Services\Onboarding\SalonOnboarding;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Salon setup wizard (owner/admin): orchestrates the existing screens and
 * actions into one guided, resumable flow — app-side steps link to the
 * screens that already do the work; GHL-side steps show exact copy-paste
 * values (scopes from config, webhook URL + secret, API endpoints + token)
 * and verify completion where the app can observe it. Step statuses are
 * COMPUTED from live data by SalonOnboarding; only the step pointer and
 * "I've done this in GHL" attestations persist.
 */
new #[Title('Salon setup')] class extends Component {
    public Salon $salon;

    public string $step = 'basics';

    // GHL connect step (token is write-only: never seeded from storage).
    public string $ghlLocationId = '';

    public string $ghlToken = '';

    // The booking API token plaintext — exists only right after generation.
    public ?string $apiTokenPlain = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;
        $this->step = $this->onboarding()->currentStep($salon);
        $this->ghlLocationId = $salon->ghlConnection()->first()?->location_id ?? '';
    }

    private function onboarding(): SalonOnboarding
    {
        return app(SalonOnboarding::class);
    }

    /** @return array<string, array{title: string, where: string}> */
    #[Computed]
    public function steps(): array
    {
        return SalonOnboarding::steps();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statuses(): array
    {
        return $this->onboarding()->statuses($this->salon);
    }

    /** @return list<string> */
    #[Computed]
    public function unmappedStylists(): array
    {
        return $this->onboarding()->unmappedStylists($this->salon);
    }

    #[Computed]
    public function webhookEventReceived(): bool
    {
        return $this->onboarding()->webhookEventReceived($this->salon);
    }

    #[Computed]
    public function connection(): ?\App\Models\SalonGhlConnection
    {
        return $this->salon->ghlConnection()->first();
    }

    /** Mapped stylists with their availability sync state. */
    #[Computed]
    public function availabilityStates()
    {
        return StylistProfile::forSalon($this->salon)
            ->whereNotNull('ghl_user_id')
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (StylistProfile $profile) => mb_strtolower((string) $profile->user?->name))
            ->values();
    }

    public function goTo(string $step): void
    {
        if (! array_key_exists($step, SalonOnboarding::steps())) {
            return;
        }

        $this->step = $step;
        $this->onboarding()->rememberStep($this->salon, $step);
        $this->reset('apiTokenPlain');
    }

    /** Save the pasted location id + PIT through the existing action. */
    public function saveConnection(UpdateGhlConnection $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $this->validate([
            'ghlLocationId' => ['required', 'string', 'max:255'],
            'ghlToken' => ['nullable', 'string', 'max:1024'],
        ]);

        // Preserve the chosen calendar — the action clears any key not passed.
        $action->handle($this->salon, [
            'location_id' => $this->ghlLocationId,
            'calendar_id' => $this->connection?->calendar_id,
            'private_integration_token' => $this->ghlToken,
        ]);

        $this->reset('ghlToken');
        unset($this->connection, $this->statuses);

        Flux::toast(variant: 'success', text: __('Connection details saved. Now run the test.'));
    }

    public function testConnection(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        // Shared checks engine: persists the inline result panel + stamp.
        $check = app(\App\Services\Ghl\IntegrationChecks::class)->run($this->salon, \App\Services\Ghl\IntegrationChecks::CONNECTION);
        $this->salon->refresh();
        unset($this->connection, $this->statuses);

        Flux::toast(variant: $check->ok() ? 'success' : 'danger', text: $check->message);
    }

    /**
     * Run one named integration check (partials.integration-check buttons);
     * the outcome persists on the salon and renders inline.
     */
    public function runIntegrationCheck(string $key): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        app(\App\Services\Ghl\IntegrationChecks::class)->run(
            $this->salon,
            $key,
            $key === \App\Services\Ghl\IntegrationChecks::VOICE ? $this->apiTokenPlain : null,
        );

        $this->salon->refresh();
        unset($this->statuses);
    }

    public function generateWebhookSecret(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $connection = $this->salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken()) {
            Flux::toast(variant: 'danger', text: __('Connect GoHighLevel first.'));

            return;
        }

        $connection->webhook_secret = bin2hex(random_bytes(24));
        $connection->save();
        unset($this->connection, $this->statuses);

        Flux::toast(variant: 'success', text: __('Webhook secret generated.'));
    }

    public function generateApiToken(GenerateBookingApiToken $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->apiTokenPlain = $action->handle($this->salon);
        $this->salon->refresh();
        unset($this->statuses);

        Flux::toast(variant: 'success', text: __('API token generated — copy it now, it is shown once.'));
    }

    public function syncAvailability(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        if (! ($this->salon->ghlConnection()->first()?->isConnected() ?? false)) {
            Flux::toast(variant: 'danger', text: __('Connect GoHighLevel (and choose a master calendar) first.'));

            return;
        }

        $queued = SyncAvailabilityToGhl::queueForSalon($this->salon);
        SyncGhlCalendarSlotSettings::queueFor($this->salon);
        unset($this->availabilityStates, $this->statuses);

        Flux::toast(
            variant: $queued > 0 ? 'success' : 'danger',
            text: $queued > 0
                ? __('Queued availability sync for :count stylist(s). Check back in a minute.', ['count' => $queued])
                : __('No stylists are mapped to GoHighLevel providers yet.'),
        );
    }

    /** "I've done this in GHL" for the steps the app cannot observe. */
    public function attest(string $step, bool $done = true): void
    {
        $this->authorize('manage', $this->salon);

        $this->onboarding()->attest($this->salon, $step, $done);
        $this->salon->refresh();
        unset($this->statuses);
    }

    public function markLive(): void
    {
        $this->authorize('manage', $this->salon);

        if (! $this->onboarding()->markLive($this->salon)) {
            Flux::toast(variant: 'danger', text: __('Finish the remaining steps first.'));

            return;
        }

        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __(':salon is live.', ['salon' => $this->salon->name]));
    }
}; ?>

<section class="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">
    @php
        $pill = fn (string $status): array => match ($status) {
            'done' => ['bg' => '#E7EFE4', 'text' => '#3E5C3A', 'label' => __('Done')],
            'in_progress' => ['bg' => '#FBEFD6', 'text' => '#8A5A1E', 'label' => __('In progress')],
            default => ['bg' => '#F0EEEA', 'text' => '#6B6862', 'label' => __('Not started')],
        };
        $statuses = $this->statuses;
        $stepKeys = array_keys($this->steps);
        $current = array_search($this->step, $stepKeys, true);
    @endphp

    <x-ui.page-header :overline="__('Setup')" :title="__('Set up this salon')">
        <x-slot:subtitle>
            {{ __('Everything needed to bring :salon live, in order — app steps and the GoHighLevel steps with exact values to paste.', ['salon' => $salon->name]) }}
        </x-slot:subtitle>
    </x-ui.page-header>

    @if ($salon->onboarded_at !== null)
        <div class="mt-4 rounded-[18px] border border-[#D5E4D0] bg-[#E7EFE4] px-4 py-3 text-[14px] text-[#3E5C3A]">
            {{ __('This salon went live :when. You can still review or repeat any step.', ['when' => $salon->onboarded_at->diffForHumans()]) }}
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[300px_minmax(0,1fr)]">
        {{-- Step list + go live --}}
        <aside>
            <nav aria-label="{{ __('Setup steps') }}" class="rounded-[18px] border border-border bg-card p-2 shadow-[0_1px_2px_rgba(0,0,0,.04)]">
                <ol class="flex flex-col gap-0.5">
                    @foreach ($this->steps as $key => $meta)
                        @php $p = $pill($statuses[$key]); @endphp
                        <li>
                            <button type="button" wire:click="goTo('{{ $key }}')"
                                    @if ($step === $key) aria-current="step" @endif
                                    class="flex w-full items-center gap-3 rounded-[13px] px-3 py-2 text-start transition hover:bg-muted {{ $step === $key ? 'bg-accent-soft' : '' }}">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-full text-[12px] font-semibold"
                                      style="background-color: {{ $p['bg'] }}; color: {{ $p['text'] }}">
                                    @if ($statuses[$key] === 'done')
                                        <flux:icon.check variant="micro" />
                                    @else
                                        {{ $loop->iteration }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] font-semibold {{ $step === $key ? 'text-accent-ink' : 'text-ink' }}">{{ $meta['title'] }}</span>
                                    <span class="block text-[12px] text-secondary">{{ $meta['where'] === 'ghl' ? __('In GoHighLevel') : __('In the app') }} · {{ $p['label'] }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </nav>

            {{-- Go live --}}
            @php $allDone = ! in_array(false, array_map(fn ($s) => $s === 'done', $statuses), true); @endphp
            <div class="mt-4 rounded-[18px] border border-border bg-card p-4 shadow-[0_1px_2px_rgba(0,0,0,.04)]">
                <h2 class="font-display text-[16px] font-semibold text-ink">{{ __('Go live') }}</h2>
                <p class="mt-1 text-[13px] text-body">
                    {{ $allDone
                        ? __('Every step is complete.')
                        : trans_choice(':count step remaining.|:count steps remaining.', collect($statuses)->reject(fn ($s) => $s === 'done')->count(), ['count' => collect($statuses)->reject(fn ($s) => $s === 'done')->count()]) }}
                </p>
                @if ($salon->onboarded_at === null)
                    <button type="button" wire:click="markLive"
                            @if (! $allDone) disabled aria-disabled="true" @endif
                            class="bts-btn bts-btn-primary mt-3 w-full disabled:pointer-events-none disabled:opacity-50">
                        {{ __('Mark salon as live') }}
                    </button>
                @else
                    <p class="mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12.5px] font-semibold" style="background-color:#E7EFE4;color:#3E5C3A">
                        <flux:icon.check-circle variant="micro" /> {{ __('Live') }}
                    </p>
                @endif
            </div>
        </aside>

        {{-- Current step panel --}}
        <div class="min-w-0">
            @php $meta = $this->steps[$step]; $p = $pill($statuses[$step]); @endphp
            <div class="rounded-[18px] border border-border bg-card p-5 shadow-[0_1px_2px_rgba(0,0,0,.04)] sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="bts-overline">{{ __('Step :n of :total', ['n' => ($current === false ? 1 : $current + 1), 'total' => count($stepKeys)]) }} · {{ $meta['where'] === 'ghl' ? __('In GoHighLevel') : __('In the app') }}</p>
                    <span class="bts-pill" style="background-color: {{ $p['bg'] }}; color: {{ $p['text'] }}">{{ $p['label'] }}</span>
                </div>
                <h2 class="mt-1 font-display text-[22px] font-bold text-ink">{{ $meta['title'] }}</h2>

                {{-- ============================== basics ============================== --}}
                @if ($step === 'basics')
                    <p class="mt-2 text-[14px] text-body">{{ __('The salon needs a name, web address, timezone and display currency. These were set when the salon was created — confirm them, and adjust in salon settings if anything is off.') }}</p>
                    <dl class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach ([
                            __('Name') => $salon->name,
                            __('Web address') => $salon->slug.'.'.config('app.domain'),
                            __('Timezone') => $salon->timezone,
                            __('Currency') => $salon->currency,
                        ] as $label => $value)
                            <div class="rounded-[11px] border border-input bg-field px-3 py-2.5">
                                <dt class="text-[12.5px] font-semibold text-secondary">{{ $label }}</dt>
                                <dd class="text-[14px] text-ink">{{ $value !== '' ? $value : __('Not set') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <a href="{{ route('salon.settings', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary mt-4">{{ __('Open salon settings') }}</a>

                {{-- ============================== staff ============================== --}}
                @elseif ($step === 'staff')
                    <p class="mt-2 text-[14px] text-body">{{ __('Add everyone who works here and mark who takes bookings. At minimum, add each stylist (role member, staff type stylist) — the calendar, availability and GoHighLevel mapping are all built around them. Front-desk staff can be added any time.') }}</p>
                    @php $stylistCount = $salon->stylistUsers()->count(); @endphp
                    <p class="mt-4 text-[14px] text-ink">
                        {{ trans_choice(':count stylist added.|:count stylists added.', $stylistCount, ['count' => $stylistCount]) }}
                        @if ($stylistCount === 0) {{ __('Add at least one to continue.') }} @endif
                    </p>
                    <a href="{{ route('salon.staff', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary mt-4">{{ __('Open staff') }}</a>

                {{-- ============================== services ============================== --}}
                @elseif ($step === 'services')
                    <p class="mt-2 text-[14px] text-body">{{ __('Create the services clients book — name, duration and price — and tick which stylists perform each one. A service without a qualified stylist cannot be booked. Per-stylist duration overrides live on the same screen.') }}</p>
                    @php
                        $activeServices = $salon->services()->where('active', true)->count();
                        $bookable = $salon->services()->where('active', true)->whereHas('stylists')->count();
                    @endphp
                    <p class="mt-4 text-[14px] text-ink">
                        {{ trans_choice(':count active service.|:count active services.', $activeServices, ['count' => $activeServices]) }}
                        @if ($activeServices > 0) {{ trans_choice(':count is bookable (has a qualified stylist).|:count are bookable (have a qualified stylist).', $bookable, ['count' => $bookable]) }} @endif
                    </p>
                    <a href="{{ route('salon.services', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary mt-4">{{ __('Open services') }}</a>

                {{-- ============================== availability ============================== --}}
                @elseif ($step === 'availability')
                    <p class="mt-2 text-[14px] text-body">{{ __('Set each stylist\'s weekly working hours (and any time off). Slots are only ever offered inside these hours — a stylist without hours is invisible to booking.') }}</p>
                    @php
                        $stylistIds = $salon->stylistUsers()->pluck('users.id');
                        $withHours = \App\Models\Availability::forSalon($salon)->where('kind', 'work')->whereIn('user_id', $stylistIds)->distinct()->pluck('user_id');
                        $missing = $salon->stylistUsers()->whereNotIn('users.id', $withHours)->orderBy('name')->pluck('users.name');
                    @endphp
                    @if ($stylistIds->isEmpty())
                        <p class="mt-4 text-[14px] text-ink">{{ __('Add stylists first (previous step).') }}</p>
                    @elseif ($missing->isEmpty())
                        <p class="mt-4 text-[14px] text-ink">{{ __('Every stylist has weekly hours set.') }}</p>
                    @else
                        <p class="mt-4 text-[14px] text-ink">{{ __('Still without hours: :names.', ['names' => $missing->join(', ')]) }}</p>
                    @endif
                    <a href="{{ route('salon.availability', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary mt-4">{{ __('Open availability') }}</a>

                {{-- ============================== ghl_connect ============================== --}}
                @elseif ($step === 'ghl_connect')
                    <p class="mt-2 text-[14px] text-body">{{ __('The app talks to GoHighLevel with a Private Integration Token scoped to this salon\'s sub-account (location).') }}</p>

                    <ol class="mt-4 list-decimal space-y-2 ps-5 text-[14px] text-body">
                        <li>{{ __('In GoHighLevel, open the salon\'s sub-account and go to Settings, then Private integrations.') }}</li>
                        <li>{{ __('Create a new private integration named something recognisable (for example "BookTheStyle") and enable exactly these scopes:') }}</li>
                    </ol>

                    <ul class="mt-3 grid gap-1.5 sm:grid-cols-2">
                        @foreach (config('ghl.required_scopes') as $scope => $label)
                            <li class="rounded-[9px] bg-muted px-2.5 py-1.5 text-[13px]">
                                <span class="font-mono text-ink">{{ $scope }}</span>
                                <span class="block text-[12px] text-secondary">{{ $label }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-3">
                        <x-ui.copy-field :label="__('Scope list (paste into your notes while ticking)')" :value="implode(', ', array_keys(config('ghl.required_scopes')))" />
                    </div>

                    <ol class="mt-4 list-decimal space-y-2 ps-5 text-[14px] text-body" start="3">
                        <li>{{ __('Copy the generated token (it starts with pit-) and the Location ID (Settings, then Business profile — or the ID in the sub-account URL).') }}</li>
                        <li>{{ __('Paste both below, save, then run the test.') }}</li>
                    </ol>

                    <div class="mt-4 grid gap-3 sm:max-w-md">
                        <flux:input wire:model="ghlLocationId" :label="__('Location ID')" placeholder="9NmVpk3…" />
                        <flux:input wire:model="ghlToken" type="password" viewable :label="__('Private integration token')"
                                    :placeholder="$this->connection?->hasToken() ? __('Saved — paste to replace') : 'pit-…'" />
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="saveConnection" class="bts-btn bts-btn-primary">{{ __('Save connection') }}</button>
                            <button type="button" wire:click="testConnection" class="bts-btn bts-btn-secondary"
                                    @if (! $this->connection?->hasToken()) disabled aria-disabled="true" @endif>
                                {{ __('Test connection') }}
                            </button>
                        </div>
                        @if ($this->connection?->last_verified_at)
                            <p class="text-[13px] text-body">{{ __('Last verified :when.', ['when' => $this->connection->last_verified_at->diffForHumans()]) }}</p>
                        @endif

                        @include('partials.integration-check-result', ['result' => $salon->integration_checks['connection'] ?? null])
                    </div>

                    <div class="mt-5 border-t border-divider pt-4">
                        <p class="text-[14px] font-medium text-ink">{{ __('Client contact sync') }}</p>
                        <p class="mt-1 text-[13px] text-body">{{ __('Verifies the token can actually read and write GoHighLevel contacts (the contacts.readonly and contacts.write scopes) before a booking needs to.') }}</p>
                        <div class="mt-3">
                            @include('partials.integration-check', ['check' => 'contacts', 'label' => __('Verify contact sync')])
                        </div>
                    </div>

                {{-- ============================== ghl_mapping ============================== --}}
                @elseif ($step === 'ghl_mapping')
                    <p class="mt-2 text-[14px] text-body">{{ __('Bookings sync into ONE master calendar in GoHighLevel, and each app stylist must be linked to the GHL team member who represents them on that calendar.') }}</p>

                    <ol class="mt-4 list-decimal space-y-2 ps-5 text-[14px] text-body">
                        <li>{{ __('In GoHighLevel: Settings, then Calendars — create (or pick) the master booking calendar for this salon.') }}</li>
                        <li>{{ __('On that calendar, add every stylist as a team member (Calendars, then edit the calendar, Team members). A stylist missing here cannot be mapped.') }}</li>
                        <li>{{ __('Back in the app: open salon settings, Integrations — choose the master calendar and map each stylist to their GHL team member. Email addresses that match are pre-suggested.') }}</li>
                    </ol>

                    <div class="mt-4 rounded-[11px] border border-input bg-field px-3 py-2.5 text-[14px]">
                        @if (! filled($this->connection?->calendar_id))
                            <p class="text-ink">{{ __('No master calendar chosen yet.') }}</p>
                        @elseif ($this->unmappedStylists !== [])
                            <p class="text-ink">{{ __('Calendar chosen. Still unmapped: :names.', ['names' => implode(', ', $this->unmappedStylists)]) }}</p>
                        @else
                            <p class="text-ink">{{ __('Master calendar chosen and every stylist is mapped.') }}</p>
                        @endif
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('salon.settings', $salon) }}#integrations" class="bts-btn bts-btn-secondary">{{ __('Open integrations settings') }}</a>
                        <button type="button" wire:click="$refresh" class="bts-btn bts-btn-secondary">{{ __('Check again') }}</button>
                    </div>

                    <div class="mt-4 border-t border-divider pt-4">
                        <p class="text-[13px] text-body">{{ __('Live verification against GoHighLevel — the calendar exists and every stylist is linked to a real team member on it:') }}</p>
                        <div class="mt-3">
                            @include('partials.integration-check', ['check' => 'mapping', 'label' => __('Verify mapping')])
                        </div>
                    </div>

                {{-- ============================== webhook ============================== --}}
                @elseif ($step === 'webhook')
                    <p class="mt-2 text-[14px] text-body">{{ __('The inbound webhook lets GoHighLevel push appointment and contact changes back into the app (voice AI and chat-widget bookings arrive this way). It is a workflow in GHL that posts to the app with a shared secret header.') }}</p>

                    @if (! filled($this->connection?->webhook_secret))
                        <button type="button" wire:click="generateWebhookSecret" class="bts-btn bts-btn-primary mt-4">{{ __('Generate webhook secret') }}</button>
                        <p class="mt-2 text-[13px] text-body">{{ __('Requires the connection step first.') }}</p>
                    @else
                        <div class="mt-4 grid gap-2">
                            <x-ui.copy-field :label="__('Webhook URL')" :value="route('webhooks.ghl')" />
                            <x-ui.copy-field :label="__('Header name')" value="X-Webhook-Secret" />
                            <x-ui.copy-field :label="__('Header value (the secret)')" :value="$this->connection->webhook_secret" />
                        </div>

                        <ol class="mt-4 list-decimal space-y-2 ps-5 text-[14px] text-body">
                            <li>{{ __('In GoHighLevel: Automation, then Workflows — create a workflow (for example "BookTheStyle inbound").') }}</li>
                            <li>{{ __('Add triggers for appointment events (Appointment status changed / Customer booked appointment) and, if contact sync is wanted, contact changed.') }}</li>
                            <li>{{ __('Add a Webhook action: method POST, the URL above, and a custom header named X-Webhook-Secret with the secret above.') }}</li>
                            <li>{{ __('Publish the workflow, then make a small test change in GHL (for example move a test appointment) and check below.') }}</li>
                        </ol>

                        <div class="mt-4 rounded-[11px] border border-input bg-field px-3 py-2.5 text-[14px]">
                            @if ($this->webhookEventReceived)
                                <p class="inline-flex items-center gap-1.5 text-[#3E5C3A]"><flux:icon.check-circle variant="micro" /> {{ __('Verified — an event from GoHighLevel has been received.') }}</p>
                            @else
                                <p class="text-ink">{{ __('No event received yet. Trigger a test change in GHL, wait a few seconds, and check again — or confirm below if the workflow is set up and there is nothing to trigger yet.') }}</p>
                            @endif
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" wire:click="$refresh" class="bts-btn bts-btn-secondary">{{ __('Check again') }}</button>
                            @if (! $this->webhookEventReceived)
                                @if ($this->onboarding()->isAttested($salon, 'webhook'))
                                    <button type="button" wire:click="attest('webhook', false)" class="bts-btn bts-btn-secondary">{{ __('Undo confirmation') }}</button>
                                @else
                                    <button type="button" wire:click="attest('webhook')" class="bts-btn bts-btn-primary">{{ __('I\'ve set up the workflow in GHL') }}</button>
                                @endif
                            @endif
                        </div>

                        <div class="mt-4 border-t border-divider pt-4">
                            <p class="text-[13px] text-body">{{ __('Direct delivery test — the app pings its own public webhook URL with the secret and confirms it verifies:') }}</p>
                            <div class="mt-3">
                                @include('partials.integration-check', [
                                    'check' => 'webhook',
                                    'label' => __('Test delivery'),
                                    'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                                    'blockedNote' => __('Delivery can only be tested over the app\'s live public URL — GoHighLevel (and this check) cannot reach a local address. The button works automatically once the app is deployed.'),
                                ])
                            </div>
                        </div>
                    @endif

                {{-- ============================== api_token ============================== --}}
                @elseif ($step === 'api_token')
                    <p class="mt-2 text-[14px] text-body">{{ __('The voice AI authenticates to the booking API with this salon\'s bearer token. It is shown ONCE at generation — copy it straight into the GHL custom actions (next step). Regenerating invalidates the previous token immediately.') }}</p>

                    @if ($apiTokenPlain !== null)
                        <div class="mt-4">
                            <x-ui.copy-field :label="__('Booking API token — shown once')" :value="$apiTokenPlain" />
                        </div>
                        <p class="mt-2 text-[13px] text-body">{{ __('Store it like a password. Leaving this step hides it forever.') }}</p>
                    @elseif ($salon->api_token_hash !== null)
                        <p class="mt-4 text-[14px] text-ink">{{ __('A token exists (generated :when). The plaintext is not retrievable — regenerate if it was lost.', ['when' => $salon->api_token_generated_at?->diffForHumans() ?? __('earlier')]) }}</p>
                    @endif

                    {{-- Themed confirm (replaces wire:confirm); first generation commits without one, as before. --}}
                    <button type="button" class="bts-btn bts-btn-primary mt-4"
                            @if ($salon->api_token_hash !== null)
                                x-on:click="$store.confirm.ask({
                                    title: {{ Js::from(__('Regenerate API token')) }},
                                    message: {{ Js::from(__('Regenerate the API token? The current one stops working immediately — the GHL custom actions must be updated.')) }},
                                    confirmLabel: {{ Js::from(__('Regenerate')) }},
                                    danger: false,
                                }, () => $wire.generateApiToken())"
                            @else
                                wire:click="generateApiToken"
                            @endif>
                        {{ $salon->api_token_hash !== null ? __('Regenerate token') : __('Generate token') }}
                    </button>

                    <div class="mt-4 border-t border-divider pt-4">
                        <p class="text-[13px] text-body">{{ __('Prove what the GHL custom action will receive — best run right after generating, while the token is still on screen (that enables the full end-to-end check with real slots).') }}</p>
                        <div class="mt-3">
                            @include('partials.integration-check', [
                                'check' => 'voice',
                                'label' => __('Test booking API'),
                                'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                                'blockedNote' => __('The booking API can only be tested over the app\'s live public URL — the same way the GHL custom action calls it. The button works automatically once the app is deployed.'),
                            ])
                        </div>
                    </div>

                {{-- ============================== voice_actions ============================== --}}
                @elseif ($step === 'voice_actions')
                    <p class="mt-2 text-[14px] text-body">{{ __('The GHL Voice AI agent needs two Custom Actions: one to look up open slots, one to book. Configure both in GoHighLevel (AI Agents, then your voice agent, Custom Actions) with these exact values.') }}</p>

                    @php
                        $bearer = $apiTokenPlain !== null ? 'Bearer '.$apiTokenPlain : 'Bearer btsk_… ('.__('your token from the previous step').')';
                    @endphp

                    <div class="mt-4 grid gap-2">
                        <x-ui.copy-field :label="__('Header — Authorization')" :value="$bearer" />
                        <x-ui.copy-field :label="__('Header — Content-Type')" value="application/json" />
                    </div>

                    @foreach ([
                        [
                            'name' => __('Action 1 — check availability'),
                            'url' => route('api.booking.availability'),
                            'params' => [
                                ['service', __('The service name'), 'Hair Cut'],
                                ['stylist', __('Stylist name, or any'), 'any'],
                                ['date', __('The day to check'), '2026-07-27'],
                            ],
                        ],
                        [
                            'name' => __('Action 2 — create booking'),
                            'url' => route('api.booking.create'),
                            'params' => [
                                ['service', __('The service name'), 'Hair Cut'],
                                ['stylist', __('Stylist name, or any'), 'any'],
                                ['date', __('Slot date from availability'), '2026-07-27'],
                                ['time', __('Slot time from availability'), '11:00 AM'],
                                ['client_name', __('Caller\'s name'), 'Jane Doe'],
                                ['client_phone', __('Caller\'s phone'), '+15551234567'],
                                ['client_email', __('Caller\'s email (optional)'), 'jane@example.com'],
                            ],
                        ],
                    ] as $action)
                        <div class="mt-4 rounded-[16px] border border-border p-4">
                            <h2 class="font-display text-[16px] font-semibold text-ink">{{ $action['name'] }}</h2>
                            <div class="mt-2 grid gap-2">
                                <x-ui.copy-field :label="__('Method + URL (method is POST)')" :value="$action['url']" />
                            </div>
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-[13px]">
                                    <thead>
                                        <tr class="text-start text-[12px] text-secondary">
                                            <th scope="col" class="pb-1 pe-4 text-start font-semibold">{{ __('Parameter') }}</th>
                                            <th scope="col" class="pb-1 pe-4 text-start font-semibold">{{ __('Meaning') }}</th>
                                            <th scope="col" class="pb-1 text-start font-semibold">{{ __('Example') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($action['params'] as [$param, $meaning, $example])
                                            <tr class="border-t border-row">
                                                <td class="py-1.5 pe-4 font-mono text-ink">{{ $param }}</td>
                                                <td class="py-1.5 pe-4 text-body">{{ $meaning }}</td>
                                                <td class="py-1.5 font-mono text-body">{{ $example }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endforeach

                    <div class="mt-4 rounded-[11px] bg-accent-soft px-3 py-2.5 text-[13px] text-accent-ink">
                        <p class="font-semibold">{{ __('Good to know') }}</p>
                        <ul class="mt-1 list-disc space-y-1 ps-4">
                            <li>{{ __('GHL sends parameters as a query string with an empty body — the endpoints accept that.') }}</li>
                            <li>{{ __('Send date and time as two separate parameters (as above) — GHL rejects a combined ISO datetime.') }}</li>
                            <li>{{ __('URL-encoded or double-encoded values are decoded automatically — no cleanup needed in the agent prompt.') }}</li>
                        </ul>
                    </div>

                    <div class="mt-4 border-t border-divider pt-4">
                        <p class="text-[13px] text-body">{{ __('Dry-run the availability action — the app calls its own endpoint over the public URL, exactly as GHL will:') }}</p>
                        <div class="mt-3">
                            @include('partials.integration-check', [
                                'check' => 'voice',
                                'label' => __('Test booking API'),
                                'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                                'blockedNote' => __('The booking API can only be tested over the app\'s live public URL — the same way the GHL custom action calls it. The button works automatically once the app is deployed.'),
                            ])
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if ($this->onboarding()->isAttested($salon, 'voice_actions'))
                            <button type="button" wire:click="attest('voice_actions', false)" class="bts-btn bts-btn-secondary">{{ __('Undo confirmation') }}</button>
                        @else
                            <button type="button" wire:click="attest('voice_actions')" class="bts-btn bts-btn-primary">{{ __('I\'ve configured both actions in GHL') }}</button>
                        @endif
                    </div>

                {{-- ============================== availability_sync ============================== --}}
                @elseif ($step === 'availability_sync')
                    <p class="mt-2 text-[14px] text-body">{{ __('Push every mapped stylist\'s weekly hours and time off to GoHighLevel so its calendar (and the voice AI\'s own view) matches the app. After this first push, changes sync automatically.') }}</p>

                    <button type="button" wire:click="syncAvailability" class="bts-btn bts-btn-primary mt-4">{{ __('Sync availability now') }}</button>

                    @if ($this->availabilityStates->isNotEmpty())
                        <div class="mt-4 overflow-x-auto rounded-[11px] border border-input">
                            <table class="w-full text-[13px]">
                                <thead>
                                    <tr class="bg-field text-[12px] text-secondary">
                                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('Stylist') }}</th>
                                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('Sync status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->availabilityStates as $state)
                                        <tr class="border-t border-row">
                                            <td class="px-3 py-2 text-ink">{{ $state->user?->name }}</td>
                                            <td class="px-3 py-2">
                                                @php $sp = $pill($state->ghl_availability_status === 'synced' ? 'done' : ($state->ghl_availability_status === null ? 'not_started' : 'in_progress')); @endphp
                                                <span class="bts-pill" style="background-color: {{ $sp['bg'] }}; color: {{ $sp['text'] }}">{{ $state->ghl_availability_status ?? __('never synced') }}</span>
                                                @if ($state->ghl_availability_status === 'failed' && $state->ghl_availability_error)
                                                    <span class="block pt-1 text-[12px] text-body">{{ $state->ghl_availability_error }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="button" wire:click="$refresh" class="bts-btn bts-btn-secondary mt-3">{{ __('Check again') }}</button>
                    @else
                        <p class="mt-4 text-[14px] text-body">{{ __('No stylists are mapped yet — finish the calendar and mapping step first.') }}</p>
                    @endif

                    <div class="mt-4 flex flex-col gap-4 border-t border-divider pt-4">
                        <div>
                            <p class="text-[13px] text-body">{{ __('Read each schedule back from GoHighLevel — proves the mirror exists there, not just that our last push claimed success:') }}</p>
                            <div class="mt-3">
                                @include('partials.integration-check', ['check' => 'availability', 'label' => __('Verify in GoHighLevel')])
                            </div>
                        </div>
                        <div>
                            <p class="text-[13px] text-body">{{ __('Outbound booking round trip — creates one clearly-titled test appointment through the real push path, reads it back, then deletes it (no real client data, nothing left behind):') }}</p>
                            <div class="mt-3">
                                @include('partials.integration-check', ['check' => 'booking', 'label' => __('Run round-trip test')])
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Prev / next --}}
                <div class="mt-6 flex items-center justify-between border-t border-divider pt-4">
                    @if ($current !== false && $current > 0)
                        <button type="button" wire:click="goTo('{{ $stepKeys[$current - 1] }}')" class="bts-btn bts-btn-secondary">{{ __('Back') }}</button>
                    @else
                        <span></span>
                    @endif
                    @if ($current !== false && $current < count($stepKeys) - 1)
                        <button type="button" wire:click="goTo('{{ $stepKeys[$current + 1] }}')" class="bts-btn bts-btn-primary">{{ __('Next step') }}</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
