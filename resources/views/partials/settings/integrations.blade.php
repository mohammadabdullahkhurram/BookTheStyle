{{-- Extracted body of the Salon settings "Integrations" tab. Included with the
     parent component's full scope — every wire binding and method call
     behaves exactly as before the extraction. --}}
        @can('runDiagnostics', $salon)
            {{-- Agency operators only: the one-click full-setup validation. --}}
            <x-ui.card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="bts-card-title">{{ __('Health check') }}</h2>
                    <p class="mt-1 text-[13.5px] text-secondary">{{ __('Validate the whole setup — integrations, notifications, scheduler & queue, salon readiness, system — with disposable test records.') }}</p>
                </div>
                <x-ui.button :href="route('salon.check-connections', $salon)" wire:navigate>{{ __('Open') }}</x-ui.button>
            </x-ui.card>
        @endcan
        @can('manageGhlConnection', $salon)
            @include('partials.ghl-connection-card')

            @if ($tokenIsSet)
                <x-ui.card class="flex flex-col gap-5">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="bts-card-title">{{ __('Master calendar and staff mapping') }}</h2>
                        <x-ui.button type="button" variant="secondary" wire:click="loadGhlDirectory" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="loadGhlDirectory">{{ $ghlDirectoryLoaded ? __('Reload from GoHighLevel') : __('Load from GoHighLevel') }}</span>
                            <span wire:loading wire:target="loadGhlDirectory">{{ __('Loading…') }}</span>
                        </x-ui.button>
                    </div>

                    <p class="text-[14px] text-secondary">
                        {{ __('Pick the salon\'s master GoHighLevel calendar, then link your team. Stylist links route bookings to the right provider; other staff links are identity only.') }}
                    </p>

                    @error('ghl')
                        <p class="text-[13.5px] font-medium text-[#A23A3A]">{{ $message }}</p>
                    @enderror

                    <form wire:submit="saveGhlMapping" class="flex flex-col gap-5" novalidate>
                        @if ($ghlDirectoryLoaded)
                            <flux:select wire:model.live="ghlCalendarId" :label="__('Master calendar')"
                                :description="__('The team calendar whose members are your stylists.')">
                                <flux:select.option value="">{{ __('Choose a calendar') }}</flux:select.option>
                                @foreach ($ghlCalendars as $calendar)
                                    <flux:select.option value="{{ $calendar['id'] }}">{{ $calendar['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @elseif ($ghlCalendarId !== '')
                            <div class="flex flex-col gap-1">
                                <div class="bts-field-label">{{ __('Master calendar') }}</div>
                                <p class="font-mono text-[13.5px] text-body">{{ $ghlCalendarId }}</p>
                                <p class="text-[13px] text-faint">{{ __('Load from GoHighLevel to pick a different calendar by name.') }}</p>
                            </div>
                        @endif

                        @if ($ghlDirectoryLoaded && $ghlUsers === [])
                            <p class="text-[13.5px] font-medium text-[#A23A3A]">
                                {{ __('No users found in GoHighLevel. Add your team as users on the location (Settings → My Staff), then reload.') }}
                            </p>
                        @endif

                        {{-- Tier 1: stylists → calendar team members (bookable providers). --}}
                        <div class="flex flex-col gap-1">
                            <div class="bts-field-label">{{ __('Stylists — calendar providers') }}</div>
                            <p class="text-[13px] text-secondary">
                                {{ __('Each stylist maps to a team member of the master calendar. This is what routes bookings to the right provider.') }}
                            </p>
                            <div class="flex flex-col divide-y divide-row rounded-[11px] border border-input-border">
                                @forelse ($this->mappableStylists as $stylist)
                                    @php($mapped = ($ghlStylistMap[$stylist->id] ?? '') !== '')
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$stylist->name" :seed="$stylist->id" size="sm" />
                                            <span class="text-[14.5px] font-medium text-ink">{{ $stylist->name }}</span>
                                            @if (in_array($stylist->id, $ghlAutoMatched, true))
                                                <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('Matched by email') }}</span>
                                            @elseif (! $mapped)
                                                <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Unmapped') }}</span>
                                            @endif
                                        </div>
                                        <div class="w-full sm:w-72">
                                            @if ($ghlDirectoryLoaded && $this->ghlProviderOptions !== [])
                                                <flux:select wire:model="ghlStylistMap.{{ $stylist->id }}" aria-label="{{ __('Calendar provider for :name', ['name' => $stylist->name]) }}">
                                                    <flux:select.option value="">{{ __('Not mapped') }}</flux:select.option>
                                                    @foreach ($this->ghlProviderOptions as $provider)
                                                        <flux:select.option value="{{ $provider['id'] }}">{{ $provider['name'] !== '' ? $provider['name'] : $provider['id'] }}{{ $provider['email'] !== '' ? ' — '.$provider['email'] : '' }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            @elseif ($mapped)
                                                <p class="text-right font-mono text-[13px] text-secondary">{{ $ghlStylistMap[$stylist->id] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-4 py-4 text-[14px] text-faint">{{ __('No active stylists yet. Add stylists under Staff first.') }}</p>
                                @endforelse
                            </div>
                            @if ($ghlDirectoryLoaded && $ghlCalendarId !== '' && $this->ghlProviderOptions === [])
                                <p class="text-[13px] font-medium text-[#8A5A1E]">
                                    {{ __('This calendar has no team members yet. In GoHighLevel, add your stylists to the calendar (edit calendar → team members), then reload.') }}
                                </p>
                            @elseif ($ghlDirectoryLoaded && $ghlCalendarId === '')
                                <p class="text-[13px] text-faint">{{ __('Choose a master calendar to see its providers.') }}</p>
                            @endif
                            <p class="text-[13px] text-faint">
                                {{ __('A stylist missing from the dropdown must be added to the master calendar in GoHighLevel before they can receive bookings.') }}
                            </p>
                        </div>

                        {{-- Tier 2: everyone else → location users (identity only). --}}
                        <div class="flex flex-col gap-1">
                            <div class="bts-field-label">{{ __('Other staff — team members') }}</div>
                            <p class="text-[13px] text-secondary">
                                {{ __('Front desk, managers and owners link to a GoHighLevel user for attribution only — this never makes them bookable.') }}
                            </p>
                            <div class="flex flex-col divide-y divide-row rounded-[11px] border border-input-border">
                                @forelse ($this->mappableStaff as $membership)
                                    @php($staff = $membership->user)
                                    @php($mapped = ($ghlStaffMap[$staff->id] ?? '') !== '')
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$staff->name" :seed="$staff->id" size="sm" />
                                            <div class="flex flex-col">
                                                <span class="text-[14.5px] font-medium text-ink">{{ $staff->name }}</span>
                                                <span class="text-[12.5px] text-faint">{{ $membership->salon_role->label() }}{{ $membership->staff_type ? ' · '.$membership->staff_type->label() : '' }}</span>
                                            </div>
                                            @if (in_array($staff->id, $ghlAutoMatched, true))
                                                <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('Matched by email') }}</span>
                                            @elseif (! $mapped)
                                                <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Unmapped') }}</span>
                                            @endif
                                        </div>
                                        <div class="w-full sm:w-72">
                                            @if ($ghlDirectoryLoaded && $ghlUsers !== [])
                                                <flux:select wire:model="ghlStaffMap.{{ $staff->id }}" aria-label="{{ __('GoHighLevel user for :name', ['name' => $staff->name]) }}">
                                                    <flux:select.option value="">{{ __('Not mapped') }}</flux:select.option>
                                                    @foreach ($this->ghlStaffOptions as $ghlUser)
                                                        <flux:select.option value="{{ $ghlUser['id'] }}">{{ $ghlUser['name'] !== '' ? $ghlUser['name'] : $ghlUser['id'] }}{{ $ghlUser['email'] !== '' ? ' — '.$ghlUser['email'] : '' }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            @elseif ($mapped)
                                                <p class="text-right font-mono text-[13px] text-secondary">{{ $ghlStaffMap[$staff->id] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-4 py-4 text-[14px] text-faint">{{ __('No other active staff.') }}</p>
                                @endforelse
                            </div>
                        </div>

                        @unless ($ghlDirectoryLoaded)
                            <p class="text-[13px] text-faint">{{ __('Load from GoHighLevel to link staff by name.') }}</p>
                        @endunless

                        @if ($ghlDirectoryLoaded)
                            <div><x-ui.button type="submit">{{ __('Save mapping') }}</x-ui.button></div>
                        @endif
                    </form>

                    {{-- Live verification: the calendar still exists in GHL and
                         every stylist maps to a real team member on it. --}}
                    <div class="border-t border-row pt-4">
                        @include('partials.integration-check', ['check' => 'mapping', 'label' => __('Verify mapping')])
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Client contact sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Bookings keep GoHighLevel contacts in step with the app\'s clients. This verifies the token can actually read and write contacts (the contacts.readonly and contacts.write scopes) before a booking needs to.') }}
                    </p>
                    @include('partials.integration-check', ['check' => 'contacts', 'label' => __('Verify contact sync')])
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Inbound webhook') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Lets GoHighLevel push appointment changes back into the app. In your GHL workflow, add a custom webhook action pointing at this URL with the secret as an X-Webhook-Secret header.') }}
                    </p>
                    <div class="flex flex-col gap-1">
                        <div class="bts-field-label">{{ __('Webhook URL (POST)') }}</div>
                        <p class="font-mono text-[13px] text-body">{{ route('webhooks.ghl') }}</p>
                    </div>
                    <div class="flex flex-col gap-1">
                        <div class="bts-field-label">{{ __('Secret — sent as the X-Webhook-Secret header') }}</div>
                        @if ($ghlWebhookSecret)
                            <p class="break-all font-mono text-[13px] text-body">{{ $ghlWebhookSecret }}</p>
                        @else
                            <p class="text-[13.5px] text-faint">{{ __('No secret yet — inbound calls are rejected until one exists.') }}</p>
                        @endif
                    </div>
                    <div>
                        @if ($ghlWebhookSecret)
                            {{-- Themed confirm (replaces wire:confirm) — single-line Js::from, per the x-ui.confirm-modal recipe. --}}
                            <x-ui.button type="button" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Rotate webhook secret')) }}, message: {{ Js::from(__('Rotate the webhook secret? The current one stops working immediately.')) }}, confirmLabel: {{ Js::from(__('Rotate')) }}, danger: false }, () => $wire.generateGhlWebhookSecret())">
                                {{ __('Rotate secret') }}
                            </x-ui.button>
                        @else
                            <x-ui.button type="button" variant="secondary" wire:click="generateGhlWebhookSecret">
                                {{ __('Generate secret') }}
                            </x-ui.button>
                        @endif
                    </div>

                    {{-- Reachability + secret test: the app pings its own
                         public webhook URL with a signed test payload. --}}
                    <div class="border-t border-row pt-4">
                        @include('partials.integration-check', [
                            'check' => 'webhook',
                            'label' => __('Test delivery'),
                            'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                            'blockedNote' => __('Delivery can only be tested over the app\'s live public URL — GoHighLevel (and this check) cannot reach a local address. The button works automatically once the app is deployed.'),
                        ])
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Availability sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Mirrors each mapped stylist\'s weekly hours and time off into GoHighLevel, so the voice AI, chat widget and booking pages only offer times the app would allow. The app remains the source of truth.') }}
                    </p>
                    @if ($this->ghlAvailabilityStates->isEmpty())
                        <p class="text-[14px] text-faint">{{ __('Map stylists to GoHighLevel providers above, then sync.') }}</p>
                    @else
                        <div class="divide-y divide-row rounded-[18px] border border-border">
                            @foreach ($this->ghlAvailabilityStates as $state)
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <x-ui.avatar :name="$state->user?->name ?? ''" :seed="$state->user_id" size="sm" />
                                        <div class="flex flex-col">
                                            <span class="text-[14.5px] font-medium text-ink">{{ $state->user?->name }}</span>
                                            @if ($state->ghl_availability_status === 'failed')
                                                <span class="text-[12.5px]" style="color:#A23A3A;">{{ $state->ghl_availability_error }}</span>
                                            @elseif ($state->ghl_availability_status === 'skipped')
                                                <span class="text-[12.5px] text-faint">{{ $state->ghl_availability_error }}</span>
                                            @elseif ($state->ghl_availability_synced_at)
                                                <span class="text-[12.5px] text-faint">{{ __('Synced') }} {{ $state->ghl_availability_synced_at->diffForHumans() }}</span>
                                            @else
                                                <span class="text-[12.5px] text-faint">{{ __('Never synced') }}</span>
                                            @endif
                                        </div>
                                        @if ($state->ghl_availability_status === 'failed')
                                            <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;">{{ __('Failed') }}</span>
                                        @elseif ($state->ghl_availability_status === 'pending')
                                            <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Pending') }}</span>
                                        @elseif ($state->ghl_availability_status === 'synced')
                                            <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Synced') }}</span>
                                        @endif
                                    </div>
                                    <x-ui.button type="button" variant="secondary" wire:click="retryGhlAvailability({{ $state->id }})">
                                        {{ $state->ghl_availability_status === 'failed' ? __('Retry sync') : __('Sync') }}
                                    </x-ui.button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div>
                        <x-ui.button type="button" wire:click="syncGhlAvailability">
                            {{ __('Sync availability to GoHighLevel') }}
                        </x-ui.button>
                    </div>

                    {{-- Read-back verification: each mapped stylist's schedule
                         actually exists in GHL, not just our last push status. --}}
                    <div class="border-t border-row pt-4">
                        @include('partials.integration-check', ['check' => 'availability', 'label' => __('Verify in GoHighLevel')])
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Outbound booking sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Proves the app can write appointments to the master calendar. The round trip creates ONE clearly-titled test appointment through the same push path real bookings use, reads it back from GoHighLevel, and deletes it — no real client data, nothing left behind.') }}
                    </p>
                    @include('partials.integration-check', ['check' => 'booking', 'label' => __('Run round-trip test')])
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Sync issues') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Bookings that could not be mirrored to GoHighLevel. Retry re-sends the booking\'s current state.') }}
                    </p>
                    @if ($this->ghlSyncIssues->isEmpty())
                        <p class="text-[14px] text-faint">{{ __('No sync issues — everything is mirrored.') }}</p>
                    @else
                        <div class="divide-y divide-row rounded-[18px] border border-border">
                            @foreach ($this->ghlSyncIssues as $issue)
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 flex-col">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[14.5px] font-medium text-ink">{{ $issue->client->name }}</span>
                                            <span class="text-[13px] text-secondary">
                                                {{ $issue->items->min('starts_at')?->setTimezone($salon->timezone)->format('D, M j · g:i A') }}
                                                @if ($issue->items->isNotEmpty())
                                                    · {{ $issue->items->first()->service->name }} · {{ $issue->items->first()->stylist->name }}
                                                @endif
                                            </span>
                                        </div>
                                        <span class="text-[12.5px]" style="color:#A23A3A;">{{ $issue->ghl_sync_error }}</span>
                                        @if ($issue->ghl_last_attempt_at)
                                            <span class="text-[12px] text-faint">{{ __('Last attempt') }} {{ $issue->ghl_last_attempt_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                    <x-ui.button type="button" variant="secondary" wire:click="retryGhlSync({{ $issue->id }})">
                                        {{ __('Retry sync') }}
                                    </x-ui.button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif
        @endcan

        {{-- Voice-AI Booking API: the per-salon bearer token GHL Custom
             Actions authenticate with. Hash-only storage; shown once. Behind
             the same gate as the rest of the integrations plumbing, so demo
             salons (where the gate always denies) never render it. --}}
        @can('manageGhlConnection', $salon)
        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Voice AI booking API') }}</h2>
            <p class="text-[13.5px] leading-relaxed text-secondary">
                {{ __('The GoHighLevel voice assistant books through this salon\'s own engine using these endpoints, authenticated by a secret token. The token is shown once — store it in the GHL Custom Action. Regenerating invalidates the old token immediately.') }}
            </p>

            @if ($apiTokenPlain !== null)
                <div class="flex flex-col gap-2 rounded-[11px] border border-[#D8E4D5] bg-[#E7EFE4] px-4 py-3">
                    <span class="text-[13px] font-semibold text-[#3E5C3A]">{{ __('Copy this token now — it will not be shown again.') }}</span>
                    <code class="break-all font-mono text-[13.5px] text-ink" data-test="api-token">{{ $apiTokenPlain }}</code>
                </div>
            @elseif ($salon->api_token_generated_at !== null)
                <p class="text-[13.5px] text-body">
                    {{ __('A token is active (generated :date). It cannot be viewed again — regenerate to replace it.', ['date' => $salon->api_token_generated_at->setTimezone($salon->timezone)->format('M j, Y g:i A')]) }}
                </p>
            @else
                <p class="text-[13.5px] text-faint">{{ __('No token yet — generate one to enable the booking API for this salon.') }}</p>
            @endif

            <div>
                @if ($salon->api_token_generated_at !== null)
                    {{-- Themed confirm (replaces wire:confirm); first generation commits without one, as before. --}}
                    <x-ui.button type="button" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Regenerate API token')) }}, message: {{ Js::from(__('Regenerate the API token? The current token stops working immediately.')) }}, confirmLabel: {{ Js::from(__('Regenerate')) }}, danger: false }, () => $wire.generateApiToken())">
                        {{ __('Regenerate token') }}
                    </x-ui.button>
                @else
                    <x-ui.button type="button" variant="secondary" wire:click="generateApiToken">
                        {{ __('Generate token') }}
                    </x-ui.button>
                @endif
            </div>

            <p class="text-[12.5px] text-faint">
                POST {{ route('api.booking.availability') }} · POST {{ route('api.booking.create') }} — Authorization: Bearer &lt;token&gt;
            </p>

            {{-- End-to-end test: call this salon's own availability endpoint
                 over the public URL — exactly what the GHL custom action does.
                 Run it while a freshly generated token is on screen for the
                 full 200-with-slots proof. --}}
            @can('manageGhlConnection', $salon)
                <div class="border-t border-row pt-4">
                    @include('partials.integration-check', [
                        'check' => 'voice',
                        'label' => __('Test booking API'),
                        'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                        'blockedNote' => __('The booking API can only be tested over the app\'s live public URL — the same way the GHL custom action calls it. The button works automatically once the app is deployed.'),
                    ])
                </div>
            @endcan
        </x-ui.card>
        @endcan
