<?php

use App\Actions\Salons\SetSalonActive;
use App\Actions\Salons\UpdateSalon;
use App\Models\Salon;
use App\Rules\SalonSlug;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit salon')] class extends Component {
    public Salon $salon;

    public string $name = '';

    public string $slug = '';

    public string $timezone = '';

    public string $accent = '';

    public bool $allow_walkins = true;

    public bool $allow_same_day = true;

    public int $max_advance_days = 90;

    public int $min_notice_minutes = 0;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
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
        $this->name = $salon->name;
        $this->slug = $salon->slug;
        $this->timezone = $salon->timezone;
        $this->accent = $salon->accentColor() ?? '';
        $this->allow_walkins = $salon->allow_walkins;
        $this->allow_same_day = $salon->allow_same_day;
        $this->max_advance_days = $salon->max_advance_days;
        $this->min_notice_minutes = $salon->min_notice_minutes;
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
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-8 py-7">
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
            <flux:input wire:model="name" :label="__('Salon name')" required />

            <flux:input wire:model="slug" :label="__('Subdomain slug')"
                :description="__('Reached at {slug}.bookthestyle.com. Changing it changes the salon\'s URL.')"
                required />

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
