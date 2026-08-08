{{-- Step 2: master calendar + staff mapping, and the contact-sync verify — included with the parent Settings component's scope. --}}
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
