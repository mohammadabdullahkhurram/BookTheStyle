@props([
    'stylists',
    'idsModel',
    'durationsModel',
    'buffersModel' => null,
    'placeholderDuration',
])

{{--
    Qualified-stylists panel, shared by the service create form and the edit
    modal. Stylist checkboxes with an optional per-stylist duration and
    cleanup buffer. The duration placeholder shows the service's entered
    default, so "leave blank = service default" reads true. The wire:model
    targets are passed in so create and edit bind to their own properties
    while sharing one implementation.
--}}
<div>
    <flux:label>{{ __('Qualified stylists') }}</flux:label>
    <flux:text class="mb-2 text-sm text-secondary">
        {{ __('Who can perform this service, plus their own time and cleanup buffer. Leave time blank to use the service default.') }}
    </flux:text>
    <div class="flex flex-col gap-2.5">
        @if (count($stylists))
            {{-- Column headings: the override inputs are labeled, not
                 placeholder-only (placeholders vanish on input and are
                 never announced as names). --}}
            <div class="flex items-center gap-3 text-[12.5px] font-medium text-faint" aria-hidden="true">
                <div class="min-w-0 flex-1"></div>
                <div class="w-24 shrink-0">{{ __('Time (min)') }}</div>
                @if ($buffersModel)
                    <div class="w-24 shrink-0">{{ __('Buffer (min)') }}</div>
                @endif
            </div>
        @endif
        @forelse ($stylists as $stylist)
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <flux:checkbox wire:model="{{ $idsModel }}" value="{{ $stylist->id }}" :label="$stylist->name" />
                </div>
                <div class="w-24 shrink-0">
                    <flux:input type="number" wire:model="{{ $durationsModel }}.{{ $stylist->id }}" :placeholder="$placeholderDuration . ' min'"
                        aria-label="{{ __(':name — duration in minutes (blank = service default)', ['name' => $stylist->name]) }}" min="5" max="600" step="5" />
                </div>
                @if ($buffersModel)
                    <div class="w-24 shrink-0">
                        <flux:input type="number" wire:model="{{ $buffersModel }}.{{ $stylist->id }}" :placeholder="__('buffer')"
                            aria-label="{{ __(':name — cleanup buffer in minutes', ['name' => $stylist->name]) }}" min="0" max="120" step="5" />
                    </div>
                @endif
            </div>
        @empty
            <flux:text class="text-sm text-secondary">{{ __('No stylists in this salon yet. Add stylists on the Staff page.') }}</flux:text>
        @endforelse
    </div>
</div>
