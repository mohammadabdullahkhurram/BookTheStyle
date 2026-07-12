<?php

use App\Actions\Salons\DisconnectGhl;
use App\Actions\Salons\SetSalonActive;
use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Salons\UpdateSalon;
use App\Models\Salon;
use App\Rules\SalonSlug;
use App\Support\SalonProfile;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit salon')] class extends Component {
    public Salon $salon;

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

    public string $slug = '';

    public string $timezone = '';

    public string $accent = '';

    public bool $allow_walkins = true;

    public bool $allow_same_day = true;

    public int $max_advance_days = 90;

    public int $min_notice_minutes = 0;

    // GoHighLevel connection. Token is write-only (never seeded here).
    public string $ghlLocationId = '';

    public string $ghlCalendarId = '';

    public string $ghlToken = '';

    public string $ghlStatus = 'not_connected';

    public bool $tokenIsSet = false;

    public ?string $ghlLastVerified = null;

    /**
     * Users think in subdomains; "slug" is internal naming. Keeps validation
     * messages (format, reserved, taken) speaking their language.
     *
     * @var array<string, string>
     */
    protected array $validationAttributes = ['slug' => 'subdomain'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required business + contact profile (includes the trading name).
            ...SalonProfile::rules(),
            'slug' => ['required', 'string', new SalonSlug, Rule::unique('salons', 'slug')->ignore($this->salon->id)],
            'timezone' => ['required', 'timezone:all'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ];
    }

    public function mount(Salon $salon): void
    {
        // Authorising against the salon's agency rejects out-of-agency ids (403).
        $this->authorize('manageSalons', $salon->agency);

        $this->salon = $salon;
        $this->slug = $salon->slug;
        $this->timezone = $salon->timezone;
        $this->accent = $salon->accentColor() ?? '';
        $this->allow_walkins = $salon->allow_walkins;
        $this->allow_same_day = $salon->allow_same_day;
        $this->max_advance_days = $salon->max_advance_days;
        $this->min_notice_minutes = $salon->min_notice_minutes;

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
        $this->ghlLastVerified = $connection?->last_verified_at?->diffForHumans();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    public function save(UpdateSalon $action): void
    {
        $this->authorize('manageSalons', $this->salon->agency);

        $action->handle($this->salon, $this->validate());

        Flux::toast(variant: 'success', text: __('Salon updated.'));
    }

    public function toggleActive(SetSalonActive $action): void
    {
        $this->authorize('manageSalons', $this->salon->agency);

        $action->handle($this->salon, ! $this->salon->active);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: $this->salon->active ? __('Salon reactivated.') : __('Salon deactivated.'));
    }

    /**
     * Store the salon's GoHighLevel connection. Authorised at the salon level
     * (agency owner/admin pass via SalonPolicy::before; salon owner/admin too).
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

    /**
     * Verify the stored credentials against the GHL API (server-side read
     * call); stamps last-verified on success.
     */
    public function testGhlConnection(TestGhlConnection $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $check = $action->handle($this->salon);
        $this->refreshGhlState();

        Flux::toast(variant: $check->ok ? 'success' : 'danger', text: $check->message);
    }

    public function disconnectGhl(DisconnectGhl $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $action->handle($this->salon);
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('GoHighLevel disconnected. Stylist mappings were kept.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Edit salon')" :title="$salon->name">
            <x-slot:subtitle>{{ __('Edit salon profile and default booking policy.') }}</x-slot:subtitle>
            <x-slot:actions>
                @if ($salon->active)
                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                @else
                    <span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Inactive') }}</span>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card>
        <form wire:submit="save" class="flex flex-col gap-6">
            @include('partials.salon-profile-fields')

            <flux:separator :text="__('Subdomain and preferences')" />

            <div class="flex flex-col gap-2">
                <flux:input wire:model.live="slug" :label="__('Subdomain')"
                    :description="__('This is the salon\'s web address — changing it changes the URL. Lowercase letters, numbers, and hyphens only.')"
                    required />
                <p class="text-[13px] text-faint">
                    {{ __('Web address:') }}
                    <span class="font-mono text-body">{{ ($slug !== '' ? $slug : __('yoursalon')).'.'.config('app.domain') }}</span>
                </p>
            </div>

            <flux:select wire:model="timezone" :label="__('Timezone')">
                @foreach ($this->timezones as $tz)
                    <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="accent" :label="__('Accent color')" :description="__('Optional hex color, e.g. #1F6F6B.')" placeholder="#1F6F6B" />

            <flux:separator :text="__('Default booking policy')" />

            <div class="flex flex-col gap-3">
                <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
            </div>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">{{ __('Save changes') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('agency.salons.index')" wire:navigate>{{ __('Back') }}</x-ui.button>
            </div>
        </form>
        </x-ui.card>

        @can('manageGhlConnection', $salon)
            @include('partials.ghl-connection-card')
        @endcan

        <x-ui.card padding="p-5" class="flex items-center justify-between gap-4">
            <div>
                <h3 class="text-[16px] font-semibold text-ink">{{ $salon->active ? __('Deactivate salon') : __('Reactivate salon') }}</h3>
                <p class="text-[14px] text-secondary">
                    {{ $salon->active ? __('Hides the salon from staff. No data is deleted.') : __('Make the salon available to staff again.') }}
                </p>
            </div>
            <button type="button" wire:click="toggleActive"
                    class="bts-btn bts-btn-sm shrink-0 {{ $salon->active ? 'border border-input-border bg-card text-danger hover:border-danger' : 'bts-btn-primary' }}">
                {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
            </button>
        </x-ui.card>
    </div>
</div>
