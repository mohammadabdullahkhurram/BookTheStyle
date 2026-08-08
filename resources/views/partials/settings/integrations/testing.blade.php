{{-- Step 5: availability sync, outbound round-trip, and sync issues — included with the parent Settings component's scope. --}}
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
