<?php

use App\Actions\AgencyUsers\UpdateAgencyUser;
use App\Enums\AgencyRole;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit agency user')] class extends Component {
    public User $user;
    public string $name = '';
    public string $agency_role = '';

    /** @var array<int, int> */
    public array $salon_ids = [];

    public function mount(User $user): void
    {
        // Same-agency + operator check (out-of-agency ids 403 here).
        $this->authorize('manageUsers', $user->agency);
        abort_if($user->agency_role === null, 404);

        // The actor must have authority over the target's current role.
        abort_unless((new AgencyUserRoles)->canAssign(Auth::user(), $user->agency_role), 403);

        $this->user = $user;
        $this->name = $user->name;
        $this->agency_role = $user->agency_role->value;
        $this->salon_ids = $user->assignedSalons()->pluck('salons.id')->all();
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
        return $this->user->agency->salons()->orderBy('name')->get(['id', 'name']);
    }

    public function save(UpdateAgencyUser $action): void
    {
        $this->authorize('manageUsers', $this->user->agency);

        $allowed = array_map(fn (AgencyRole $r) => $r->value, $this->assignableRoles());

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'agency_role' => ['required', Rule::in($allowed)],
            'salon_ids' => ['array'],
            'salon_ids.*' => ['integer'],
        ]);

        $action->handle(Auth::user(), $this->user->agency, $this->user, $validated);
        $this->user->refresh();

        Flux::toast(variant: 'success', text: __('User updated.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Edit agency user')" :title="$user->name">
            <x-slot:subtitle>{{ $user->email }}</x-slot:subtitle>
        </x-ui.page-header>

        <x-ui.card>
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" required />

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
                <x-ui.button type="submit">{{ __('Save changes') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('agency.users.index')" wire:navigate>{{ __('Back') }}</x-ui.button>
            </div>
        </form>
        </x-ui.card>
    </div>
</div>
