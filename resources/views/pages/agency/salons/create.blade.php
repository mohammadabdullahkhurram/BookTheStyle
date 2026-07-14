<?php

use App\Actions\Salons\CreateSalon;
use App\Models\Agency;
use App\Rules\SalonSlug;
use App\Support\SalonProfile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New salon')] class extends Component {
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

    public string $timezone = 'America/New_York';

    public string $accent = '';

    public bool $allow_walkins = true;

    public bool $allow_same_day = true;

    public int $max_advance_days = 90;

    public int $min_notice_minutes = 0;

    // GoHighLevel connection — all optional at creation; can be filled later.
    public string $ghl_location_id = '';

    public string $ghl_calendar_id = '';

    public string $ghl_token = '';

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
            // Format + reserved blocklist via the rule; uniqueness across all
            // salons (slugs are live subdomains, so global) via Rule::unique.
            'slug' => ['required', 'string', new SalonSlug, Rule::unique('salons', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'ghl_location_id' => ['nullable', 'string', 'max:255'],
            'ghl_calendar_id' => ['nullable', 'string', 'max:255'],
            'ghl_token' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('manageSalons', $this->agency());
    }

    /** Suggest a slug from the salon name while the slug is still untouched. */
    public function updatedName(string $value): void
    {
        if ($this->slug === '') {
            $this->slug = \Illuminate\Support\Str::slug($value);
        }
    }

    public function agency(): Agency
    {
        $agency = Auth::user()->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    public function save(CreateSalon $action): void
    {
        $this->authorize('manageSalons', $this->agency());

        $data = $this->validate();

        $salon = $action->handle($this->agency(), $data);

        Flux::toast(variant: 'success', text: __('Salon ":name" created.', ['name' => $salon->name]));

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('New salon')">
            <x-slot:subtitle>{{ __('Set up a new sub-account.') }}</x-slot:subtitle>
        </x-ui.page-header>

        <x-ui.card>
        <form wire:submit="save" class="flex flex-col gap-6">
            @include('partials.salon-profile-fields')

            <flux:separator :text="__('Subdomain and preferences')" />

            <div class="flex flex-col gap-2">
                <flux:input wire:model.live="slug" :label="__('Subdomain')"
                    :description="__('This becomes the salon\'s web address. Lowercase letters, numbers, and hyphens only.')"
                    placeholder="demo" required />
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

            <flux:input wire:model="accent" :label="__('Accent color')" :description="__('Optional hex color, e.g. #1F6F6B. Used as this salon\'s brand accent.')" placeholder="#1F6F6B" />

            <flux:separator :text="__('Default booking policy')" />

            <div class="flex flex-col gap-3">
                <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
            </div>

            <flux:separator :text="__('GoHighLevel connection')" />

            <p class="-mt-1 text-[14px] text-secondary">{{ __('Optional — leave blank and connect this salon later from its settings. The token is stored encrypted.') }}</p>

            <flux:input wire:model="ghl_location_id" :label="__('Location ID')" :description="__('The GoHighLevel sub-account / location ID.')" placeholder="e.g. aBcD1234" />
            <flux:input wire:model="ghl_calendar_id" :label="__('Calendar ID')" :description="__('The salon\'s master GoHighLevel calendar ID.')" placeholder="e.g. cal_aBcD1234" />
            <flux:input type="password" wire:model="ghl_token" :label="__('Private integration token')" :description="__('Stored encrypted at rest. Write-only — never shown back.')" autocomplete="off" />

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">{{ __('Create salon') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('dashboard')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
        </x-ui.card>
    </div>
</div>
