<?php

use App\Actions\Salons\UpdateBookingPolicy;
use App\Actions\Salons\UpdateBranding;
use App\Actions\Salons\UpdateFeatureFlags;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Salons\UpdateSalonProfile;
use App\Models\Salon;
use App\Support\SalonProfile;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Salon settings')] class extends Component {
    public Salon $salon;

    #[Validate('boolean')]
    public bool $allow_walkins = true;

    #[Validate('boolean')]
    public bool $allow_same_day = true;

    #[Validate('required|integer|min:1|max:365')]
    public int $max_advance_days = 90;

    #[Validate('required|integer|min:0|max:10080')]
    public int $min_notice_minutes = 0;

    #[Validate('nullable|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $accent = '';

    /** @var array<string, bool> */
    public array $flags = [];

    // Business + contact profile (name = business / trading name).
    public string $name = '';

    public string $legal_business_name = '';

    public string $business_email = '';

    public string $business_phone = '';

    public string $website = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $region = '';

    public string $postal_code = '';

    public string $country = '';

    public string $contact_name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    // GoHighLevel connection. The token is write-only: never seeded here, so the
    // stored secret is never rendered back to the page.
    public string $ghlLocationId = '';

    public string $ghlCalendarId = '';

    public string $ghlToken = '';

    public string $ghlStatus = 'not_connected';

    public bool $tokenIsSet = false;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;

        $this->allow_walkins = $salon->allow_walkins;
        $this->allow_same_day = $salon->allow_same_day;
        $this->max_advance_days = $salon->max_advance_days;
        $this->min_notice_minutes = $salon->min_notice_minutes;

        $this->accent = $salon->accentColor() ?? '';

        foreach (array_keys($this->catalog()) as $key) {
            $this->flags[$key] = $salon->hasFeature($key);
        }

        $this->loadProfile();
        $this->refreshGhlState();
    }

    /**
     * Load the business + contact profile from the salon into the form props.
     */
    private function loadProfile(): void
    {
        $this->name = $this->salon->name;
        $this->legal_business_name = $this->salon->legal_business_name;
        $this->business_email = $this->salon->business_email;
        $this->business_phone = $this->salon->business_phone;
        $this->website = $this->salon->website ?? '';
        $this->address_line1 = $this->salon->address_line1;
        $this->address_line2 = $this->salon->address_line2 ?? '';
        $this->city = $this->salon->city;
        $this->region = $this->salon->region;
        $this->postal_code = $this->salon->postal_code;
        $this->country = $this->salon->country;
        $this->contact_name = $this->salon->contact_name;
        $this->contact_email = $this->salon->contact_email;
        $this->contact_phone = $this->salon->contact_phone;
    }

    /**
     * Load the non-secret GHL connection state (location/calendar/status) for
     * display. Never loads the token into a property.
     */
    private function refreshGhlState(): void
    {
        $connection = $this->salon->ghlConnection()->first();

        $this->ghlLocationId = $connection?->location_id ?? '';
        $this->ghlCalendarId = $connection?->calendar_id ?? '';
        $this->tokenIsSet = (bool) $connection?->hasToken();
        $this->ghlStatus = $connection?->status() ?? 'not_connected';
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function catalog(): array
    {
        /** @var array<string, string> $features */
        $features = config('salon_features', []);

        return $features;
    }

    public function savePolicy(UpdateBookingPolicy $action): void
    {
        $this->authorize('manage', $this->salon);

        $data = $this->validate([
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ]);

        $action->handle($this->salon, [
            'allow_walkins' => $data['allow_walkins'],
            'allow_same_day' => $data['allow_same_day'],
            'max_advance_days' => $data['max_advance_days'],
            'min_notice_minutes' => $data['min_notice_minutes'],
        ]);

        Flux::toast(variant: 'success', text: __('Booking policy saved.'));
    }

    public function saveBranding(UpdateBranding $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->validate([
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $action->handle($this->salon, ['accent' => $this->accent ?: null]);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Branding saved.'));
    }

    /**
     * Save the salon's business + point-of-contact profile. Gated tighter than
     * the rest of settings: salon owner/admin (+ agency owner/admin via before),
     * never salon staff or agency users.
     */
    public function saveProfile(UpdateSalonProfile $action): void
    {
        $this->authorize('manageProfile', $this->salon);

        $data = $this->validate(SalonProfile::rules());

        $action->handle($this->salon, $data);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Business profile saved.'));
    }

    public function saveFlags(UpdateFeatureFlags $action): void
    {
        $this->authorize('manage', $this->salon);

        // Keep only known flags; coerce to bool.
        $clean = [];
        foreach (array_keys($this->catalog()) as $key) {
            $clean[$key] = (bool) ($this->flags[$key] ?? false);
        }

        $action->handle($this->salon, $clean);

        Flux::toast(variant: 'success', text: __('Feature flags saved.'));
    }

    /**
     * Store the salon's GoHighLevel connection. Gated tighter than the rest of
     * settings: salon owner/admin (+ agency owner/admin), never salon staff or
     * agency users — they cannot touch the credentials.
     */
    public function saveGhlConnection(UpdateGhlConnection $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $data = $this->validate([
            'ghlLocationId' => ['nullable', 'string', 'max:255'],
            'ghlCalendarId' => ['nullable', 'string', 'max:255'],
            'ghlToken' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->handle($this->salon, [
            'location_id' => $data['ghlLocationId'],
            'calendar_id' => $data['ghlCalendarId'],
            'private_integration_token' => $data['ghlToken'],
        ]);

        $this->ghlToken = '';
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('GoHighLevel connection saved.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="$salon->name" :title="__('Salon settings')" />

        @can('manageProfile', $salon)
            <x-ui.card class="flex flex-col gap-5">
                <h2 class="bts-card-title">{{ __('Business profile') }}</h2>
                <form wire:submit="saveProfile" class="flex flex-col gap-5">
                    @include('partials.salon-profile-fields')
                    <div><x-ui.button type="submit">{{ __('Save business profile') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Booking policy') }}</h2>
            <form wire:submit="savePolicy" class="flex flex-col gap-5">
                <div class="flex flex-col gap-3">
                    <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                    <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                    <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
                </div>
                <div><x-ui.button type="submit">{{ __('Save policy') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Branding') }}</h2>
            <form wire:submit="saveBranding" class="flex flex-col gap-5">
                {{-- Accent preset selector (violet / sage / terracotta). Picking one
                     fills the hex below; a custom hex is still allowed. --}}
                <div>
                    <div class="bts-field-label mb-2">{{ __('Accent preset') }}</div>
                    <div class="flex flex-wrap gap-3">
                        @foreach (\App\Support\AccentPalette::PRESETS as $presetName => $preset)
                            @php($selected = strcasecmp($accent, $preset['accent']) === 0)
                            <button type="button" wire:click="$set('accent', '{{ $preset['accent'] }}')"
                                    class="flex items-center gap-2 rounded-[11px] border px-3.5 py-2 text-[14px] font-medium capitalize transition {{ $selected ? 'border-accent bg-accent-tint text-accent-ink' : 'border-input-border bg-field text-body hover:border-faint' }}">
                                <span class="size-4 rounded-full" style="background-color: {{ $preset['accent'] }}"></span>{{ $presetName }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <flux:input wire:model="accent" :label="__('Accent color')" :description="__('Hex color, e.g. #6555E4. Sets this salon\'s brand accent.')" placeholder="#6555E4" />
                <div><x-ui.button type="submit">{{ __('Save branding') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Feature flags') }}</h2>
            <form wire:submit="saveFlags" class="flex flex-col gap-5">
                <p class="text-[14px] text-secondary">{{ __('Per-salon toggles. Later phases read these to enable features for this salon.') }}</p>
                <div class="flex flex-col gap-3">
                    @foreach ($this->catalog as $key => $label)
                        <flux:checkbox wire:model="flags.{{ $key }}" :label="__($label)" />
                    @endforeach
                </div>
                <div><x-ui.button type="submit">{{ __('Save flags') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        @can('manageGhlConnection', $salon)
            @include('partials.ghl-connection-card')
        @endcan
    </div>
</div>
