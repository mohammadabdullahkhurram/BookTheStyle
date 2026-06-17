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
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-serif">{{ $salon->name }}</flux:heading>
                <flux:text class="text-secondary">{{ __('Edit salon profile and default booking policy.') }}</flux:text>
            </div>
            @if ($salon->active)
                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
            @else
                <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
            @endif
        </div>

        <form wire:submit="save" class="flex flex-col gap-6 rounded-xl border border-border bg-card p-6 shadow-sm">
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
                <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
                <flux:button :href="route('agency.salons.index')" wire:navigate variant="ghost">{{ __('Back') }}</flux:button>
            </div>
        </form>

        <div class="flex items-center justify-between rounded-xl border border-border bg-card p-5 shadow-sm">
            <div>
                <flux:heading size="sm">{{ $salon->active ? __('Deactivate salon') : __('Reactivate salon') }}</flux:heading>
                <flux:text class="text-sm text-secondary">
                    {{ $salon->active ? __('Hides the salon from staff. No data is deleted.') : __('Make the salon available to staff again.') }}
                </flux:text>
            </div>
            <flux:button wire:click="toggleActive" variant="{{ $salon->active ? 'danger' : 'primary' }}" size="sm">
                {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
            </flux:button>
        </div>
    </div>
</div>
