{{-- Shared "GoHighLevel connection" card for the agency salon-edit and salon
     settings screens. The host Livewire component must provide: $salon, the
     string props $ghlLocationId / $ghlCalendarId / $ghlToken, the display state
     $ghlStatus + $tokenIsSet + $ghlLastVerified, and the methods
     saveGhlConnection() / testGhlConnection() / disconnectGhl(). The token
     field is write-only — $ghlToken is never seeded with the stored token, so
     the secret is never echoed back into the page. --}}
@php
    [$pillBg, $pillFg, $pillLabel] = match ($ghlStatus) {
        'connected' => ['#E7EFE4', '#3E5C3A', __('Connected')],
        'incomplete' => ['#FBEFD6', '#8A5A1E', __('Incomplete')],
        default => ['#F0EEEA', '#6B6862', __('Not connected')],
    };
@endphp

<x-ui.card class="flex flex-col gap-5">
    <div class="flex items-center justify-between gap-4">
        <h2 class="bts-card-title">{{ __('GoHighLevel connection') }}</h2>
        <span class="bts-pill" style="background-color: {{ $pillBg }}; color: {{ $pillFg }};">{{ $pillLabel }}</span>
    </div>

    <p class="text-[14px] text-secondary">
        {{ __('Connect this salon to its GoHighLevel sub-account. Optional — you can fill it in later. The token is stored encrypted and is never shown again.') }}
    </p>

    <form wire:submit="saveGhlConnection" class="flex flex-col gap-5" novalidate>
        <flux:input wire:model="ghlLocationId" :label="__('Location ID')"
            :description="__('The GoHighLevel sub-account / location ID.')" placeholder="e.g. aBcD1234" />

        <flux:input wire:model="ghlCalendarId" :label="__('Calendar ID')"
            :description="__('The salon\'s master GoHighLevel calendar ID.')" placeholder="e.g. cal_aBcD1234" />

        {{-- ABOVE the token field: grant the right scopes BEFORE going to
             GHL to create the token. Expanded until a token exists. --}}
        @include('partials.ghl-scopes', ['open' => ! $tokenIsSet])

        @if ($tokenIsSet)
            <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2 text-[14px] font-medium text-body">
                    <flux:icon.check-circle variant="micro" class="text-[#3E5C3A]" />
                    <span>{{ __('Private integration token saved') }}</span>
                </div>
                <flux:input type="password" wire:model="ghlToken" :label="__('Replace token')"
                    :description="__('Leave blank to keep the current token; enter a new one to replace it.')"
                    placeholder="••••••••••••" autocomplete="off" />
            </div>
        @else
            <flux:input type="password" wire:model="ghlToken" :label="__('Private integration token')"
                :description="__('Stored encrypted at rest. Write-only — it is never shown back once saved.')"
                autocomplete="off" />
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit">{{ __('Save connection') }}</x-ui.button>
            @if ($tokenIsSet)
                <x-ui.button type="button" variant="secondary" wire:click="testGhlConnection" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="testGhlConnection">{{ __('Test connection') }}</span>
                    <span wire:loading wire:target="testGhlConnection">{{ __('Testing…') }}</span>
                </x-ui.button>
                {{-- Themed confirm (replaces wire:confirm) — single-line Js::from, per the x-ui.confirm-modal recipe. --}}
                <x-ui.button type="button" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Disconnect GoHighLevel')) }}, message: {{ Js::from(__('Disconnect GoHighLevel? The stored token will be deleted. Stylist mappings are kept.')) }}, confirmLabel: {{ Js::from(__('Disconnect')) }}, danger: true }, () => $wire.disconnectGhl())">
                    {{ __('Disconnect') }}
                </x-ui.button>
            @endif
        </div>

        {{-- Inline outcome of the last "Test connection" run — same panel as
             every other integration check, read straight off the salon. --}}
        @include('partials.integration-check-result', [
            'result' => $salon->integration_checks['connection'] ?? null,
        ])

        @if ($ghlLastVerified)
            <p class="text-[13px] text-faint">{{ __('Last verified :time', ['time' => $ghlLastVerified]) }}</p>
        @endif
    </form>
</x-ui.card>
