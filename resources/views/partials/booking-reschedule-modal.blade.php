{{-- Shared reschedule modal for the check-in and appointments screens. The
     host Livewire component provides $showReschedule / $rescheduleDate, the
     computed rescheduleSlots (real slot-engine times, the booking's own slot
     ignored), and reschedule(time). Same stylist + services — only the start
     moves; changing stylist or service is a rebook, not a reschedule. --}}
<x-ui.modal wire:model="showReschedule" class="max-w-lg" :heading="__('Reschedule booking')">
    <div class="flex flex-col gap-4">
        <p class="text-[13.5px] text-secondary">
            {{ __('The stylist and services stay the same — pick a new start time.') }}
        </p>

        @error('start')
            <p class="text-[13.5px] font-medium text-[#A23A3A]">{{ $message }}</p>
        @enderror

        <div class="max-w-44">
            <flux:input type="date" wire:model.live="rescheduleDate" :label="__('Date')" />
        </div>

        @php($slots = $this->rescheduleSlots)
        @if ($slots === [])
            <p class="text-[13.5px] font-medium text-[#8A5A1E]">
                {{ __('No open times for this stylist on this date. Try another date.') }}
            </p>
        @else
            <div>
                <div class="bts-field-label mb-2">{{ __('Available start times') }}</div>
                <div class="flex flex-wrap gap-2">
                    @foreach ($slots as $slot)
                        <button type="button" wire:click="reschedule('{{ $slot }}')"
                                class="rounded-[9px] border border-input-border bg-field px-3 py-1.5 text-[14px] font-medium text-body transition hover:border-accent hover:text-accent-ink">
                            {{ $slot }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-ui.modal>
