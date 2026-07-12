{{-- Shared reschedule modal for the check-in and appointments screens. The
     host Livewire component provides $showReschedule / $rescheduleDate /
     $rescheduleTime, the computed rescheduleSlots (real slot-engine times
     covering the WHOLE visit, the booking's own slots ignored), and
     reschedule(). Same stylist + services — only the start moves; changing
     stylist or service is a rebook, not a reschedule.

     Select-then-confirm: clicking a time chip only SELECTS it (visible
     selected state + aria-pressed); the move — which fires reminders and the
     GHL update — commits only from the "Confirm reschedule" button. --}}
<x-ui.modal wire:model="showReschedule" class="max-w-lg" :heading="__('Reschedule booking')">
    <div class="flex flex-col gap-4">
        <p class="text-[13.5px] text-secondary">
            {{ __('The stylist and services stay the same — pick a new start time, then confirm.') }}
        </p>

        @error('start')
            <p class="text-[13.5px] font-medium text-[#A23A3A]">{{ $message }}</p>
        @enderror
        @error('rescheduleTime')
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
                        @php($selected = $rescheduleTime === $slot)
                        <button type="button" wire:click="$set('rescheduleTime', '{{ $slot }}')"
                                aria-pressed="{{ $selected ? 'true' : 'false' }}"
                                class="rounded-[9px] border px-3 py-1.5 text-[14px] transition {{ $selected
                                    ? 'border-accent bg-accent-soft font-semibold text-accent-ink'
                                    : 'border-input-border bg-field font-medium text-body hover:border-accent hover:text-accent-ink' }}">
                            {{ $slot }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex justify-end gap-2 border-t border-divider pt-4">
            <x-ui.button variant="secondary" wire:click="$set('showReschedule', false)">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button wire:click="reschedule" loading="reschedule" :disabled="$rescheduleTime === ''">
                {{ __('Confirm reschedule') }}
            </x-ui.button>
        </div>
    </div>
</x-ui.modal>
