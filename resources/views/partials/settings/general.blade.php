{{-- Extracted body of the Salon settings "General" tab. Included with the
     parent component's full scope — every wire binding and method call
     behaves exactly as before the extraction. --}}
        @if ($salon->onboarded_at === null)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-[18px] border border-border bg-accent-soft px-4 py-3">
                <p class="text-[14px] text-accent-ink">{{ __('This salon is not live yet — the setup wizard walks through every remaining step.') }}</p>
                <a href="{{ route('salon.onboarding', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary bts-btn-sm shrink-0">{{ __('Open setup') }}</a>
            </div>
        @endif
        @can('manageProfile', $salon)
            <x-ui.card class="flex flex-col gap-5">
                <h2 class="bts-card-title">{{ __('Business profile') }}</h2>
                <p class="text-[13px] text-secondary">{{ __('Salon type: :type — managed by your agency.', ['type' => $salon->salon_type->label()]) }}</p>
                <form wire:submit="saveProfile" class="flex flex-col gap-5" novalidate>
                    @include('partials.salon-profile-fields')
                    <div><x-ui.button type="submit">{{ __('Save business profile') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Timezone') }}</h2>
            <form wire:submit="saveTimezone" class="flex flex-col gap-4" novalidate>
                <flux:select wire:model="timezone" :label="__('Salon timezone')">
                    @foreach ($this->timezones as $tz)
                        <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                    @endforeach
                </flux:select>
                <p class="text-[13px] text-faint">
                    {{ __('Changing the timezone changes how availability and bookings are shown. Existing bookings keep their exact moment in time — only the displayed local time follows the new timezone.') }}
                </p>
                <div><x-ui.button type="submit">{{ __('Save timezone') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Currency') }}</h2>
            <form wire:submit="saveCurrency" class="flex flex-col gap-4" novalidate>
                <div class="max-w-56">
                    <flux:select wire:model="currency" :label="__('Display currency')">
                        @foreach (\App\Support\Money::codes() as $code)
                            <flux:select.option value="{{ $code }}">{{ $code }} ({{ trim(\App\Support\Money::symbol($code)) }})</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <p class="text-[13px] text-faint">
                    {{ __('Used to display service prices. Prices are informational only — the app never takes payments.') }}
                </p>
                <div><x-ui.button type="submit">{{ __('Save currency') }}</x-ui.button></div>
            </form>
        </x-ui.card>
