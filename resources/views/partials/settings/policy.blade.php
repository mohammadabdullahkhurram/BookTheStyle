{{-- Extracted body of the Salon settings "Booking policy" tab. Included with the
     parent component's full scope — every wire binding and method call
     behaves exactly as before the extraction. --}}
        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Booking policy') }}</h2>
            <form wire:submit="savePolicy" class="flex flex-col gap-5" novalidate>
                <div class="flex flex-col gap-3">
                    <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                    <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                    <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
                </div>

                {{-- Booking automation: what the scheduler does to elapsed bookings. --}}
                <div class="flex flex-col gap-3 border-t border-row pt-5">
                    <h3 class="text-[13px] font-semibold uppercase tracking-wide text-secondary">{{ __('Booking automation') }}</h3>

                    <div class="flex flex-col gap-1.5">
                        <flux:checkbox wire:model.live="auto_no_show" :label="__('Auto-mark no-shows')" />
                        <p class="text-[12.5px] leading-relaxed text-faint">{{ __('When on, appointments that are still "Booked" after they end are automatically marked as no-shows (and synced to GoHighLevel). Leave off if your front desk doesn\'t always check clients in — staff can mark no-shows manually either way.') }}</p>
                    </div>

                    @if ($auto_no_show)
                        <div class="flex flex-col gap-1.5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input type="number" wire:model="auto_no_show_grace_minutes" :label="__('No-show grace period (minutes)')" min="0" max="1440" />
                            </div>
                            <p class="text-[12.5px] leading-relaxed text-faint">{{ __('How long after the end time to wait before auto-marking — covers a busy front desk checking someone in late.') }}</p>
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5">
                        <flux:checkbox wire:model="auto_complete" :label="__('Auto-complete checked-in appointments')" />
                        <p class="text-[12.5px] leading-relaxed text-faint">{{ __('When on, checked-in appointments are marked completed once their end time passes.') }}</p>
                    </div>
                </div>

                <div><x-ui.button type="submit">{{ __('Save policy') }}</x-ui.button></div>
            </form>
        </x-ui.card>
