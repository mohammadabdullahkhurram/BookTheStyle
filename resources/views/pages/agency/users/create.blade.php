<?php

use App\Actions\AgencyUsers\CreateAgencyUser;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New agency user')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $agency_role = 'agency_user';

    /** @var array<int, int> */
    public array $salon_ids = [];

    public ?string $temporaryPassword = null;
    public ?string $createdName = null;

    public function mount(): void
    {
        $this->authorize('manageUsers', $this->agency());
    }

    public function agency(): Agency
    {
        $agency = Auth::user()->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    /**
     * @return list<AgencyRole>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        return (new AgencyUserRoles)->assignable(Auth::user());
    }

    #[Computed]
    public function salons()
    {
        return $this->agency()->salons()->orderBy('name')->get(['id', 'name']);
    }

    public function save(CreateAgencyUser $action): void
    {
        $this->authorize('manageUsers', $this->agency());

        $allowed = array_map(fn (AgencyRole $r) => $r->value, $this->assignableRoles());

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->withoutTrashed()],
            'agency_role' => ['required', Rule::in($allowed)],
            'salon_ids' => ['array'],
            'salon_ids.*' => ['integer'],
        ]);

        $result = $action->handle(Auth::user(), $this->agency(), $validated);

        $this->temporaryPassword = $result->temporaryPassword;
        $this->createdName = $result->user->name;

        $this->reset(['name', 'email', 'agency_role', 'salon_ids']);
        $this->agency_role = 'agency_user';
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('New agency user')">
            <x-slot:subtitle>{{ __('Create an operator. They receive a temporary password and must change it on first login.') }}</x-slot:subtitle>
        </x-ui.page-header>

        @if ($temporaryPassword)
            <x-temp-password-panel :name="$createdName" :password="$temporaryPassword" />
            <div class="flex items-center gap-3">
                <x-ui.button :href="route('agency.users.index')" wire:navigate>{{ __('Done') }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="$set('temporaryPassword', null)">{{ __('Add another') }}</x-ui.button>
            </div>
        @else
            <x-ui.card>
            <form wire:submit="save" class="flex flex-col gap-6" novalidate>
                <flux:input wire:model="name" :label="__('Name')" required autofocus />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />

                <flux:select wire:model.live="agency_role" :label="__('Agency role')">
                    @foreach ($this->assignableRoles as $role)
                        <flux:select.option value="{{ $role->value }}">{{ $role->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($agency_role === 'agency_user')
                    <div>
                        <flux:label>{{ __('Salon access') }}</flux:label>
                        <flux:text class="mb-2 text-sm text-secondary">{{ __('Choose which salons this user can manage.') }}</flux:text>
                        <div class="flex flex-col gap-2">
                            @foreach ($this->salons as $salon)
                                <flux:checkbox wire:model="salon_ids" value="{{ $salon->id }}" :label="$salon->name" />
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit">{{ __('Create user') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('agency.users.index')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                </div>
            </form>
            </x-ui.card>
        @endif
    </div>
</div>
