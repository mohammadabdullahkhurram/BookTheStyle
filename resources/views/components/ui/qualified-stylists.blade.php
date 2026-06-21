@props([
    'stylists',
    'idsModel',
    'durationsModel',
    'buffersModel' => null,
    'placeholderDuration',
    'buffersEnabled' => false,
])

{{--
    Qualified-stylists panel, shared by the service create form and the edit
    modal. Stylist checkboxes with an optional per-stylist duration (and, behind
    the salon's buffer flag, a cleanup buffer). The duration placeholder shows
    the service's entered default, so "leave blank = service default" reads true.
    The wire:model targets are passed in so create and edit bind to their own
    properties while sharing one implementation.
--}}
<div>
    <flux:label>{{ __('Qualified stylists') }}</flux:label>
    <flux:text class="mb-2 text-sm text-secondary">
        {{ $buffersEnabled
            ? __('Who can perform this service, plus their own time and cleanup buffer. Leave time blank to use the service default.')
            : __('Who can perform this service, and how long they take. Leave time blank to use the service default.') }}
    </flux:text>
    <div class="flex flex-col gap-2.5">
        @forelse ($stylists as $stylist)
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <flux:checkbox wire:model="{{ $idsModel }}" value="{{ $stylist->id }}" :label="$stylist->name" />
                </div>
                <div class="w-24 shrink-0">
                    <flux:input type="number" wire:model="{{ $durationsModel }}.{{ $stylist->id }}" :placeholder="$placeholderDuration . ' min'" min="5" max="600" step="5" />
                </div>
                @if ($buffersEnabled && $buffersModel)
                    <div class="w-24 shrink-0">
                        <flux:input type="number" wire:model="{{ $buffersModel }}.{{ $stylist->id }}" :placeholder="__('buffer')" min="0" max="120" step="5" />
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-sm text-secondary">{{ __('No stylists in this salon yet. Add stylists on the Staff page.') }}</flux:text>
        @endforelse
    </div>
</div>
