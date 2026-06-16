<?php

use App\Actions\Salons\CreateSalon;
use App\Models\Agency;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('New salon')] class extends Component {
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|timezone:all')]
    public string $timezone = 'America/New_York';

    #[Validate('nullable|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $accent = '';

    #[Validate('boolean')]
    public bool $allow_walkins = true;

    #[Validate('boolean')]
    public bool $allow_same_day = true;

    #[Validate('required|integer|min:1|max:365')]
    public int $max_advance_days = 90;

    #[Validate('required|integer|min:0|max:10080')]
    public int $min_notice_minutes = 0;

    public function mount(): void
    {
        $this->authorize('manageSalons', $this->agency());
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

        $this->redirectRoute('agency.salons.index', navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl" class="font-serif">{{ __('New salon') }}</flux:heading>
            <flux:text class="text-secondary">{{ __('Set up a new sub-account.') }}</flux:text>
        </div>

        <form wire:submit="save" class="flex flex-col gap-6 rounded-xl border border-border bg-card p-6 shadow-sm">
            <flux:input wire:model="name" :label="__('Salon name')" required autofocus />

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

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Create salon') }}</flux:button>
                <flux:button :href="route('agency.salons.index')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</div>
