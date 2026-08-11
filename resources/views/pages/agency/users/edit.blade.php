<?php

use App\Actions\AgencyUsers\DeleteAgencyUser;
use App\Actions\AgencyUsers\UpdateAgencyUser;
use App\Enums\AgencyRole;
use App\Models\User;
use App\Support\Permissions\AgencyUserRoles;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit agency user')] class extends Component {
    public User $user;
    public bool $readOnly = false;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $agency_role = '';

    /** @var array<int, int> */
    public array $salon_ids = [];

    public function mount(User $user): void
    {
        // Same-agency + operator check (out-of-agency ids 403 here).
        $this->authorize('manageUsers', $user->agency);
        abort_if($user->agency_role === null, 404);

        // The MANAGE axis decides edit vs view: owner and admin manage every
        // Admin/User, but the AGENCY OWNER is a target nobody manages — that
        // row renders READ-ONLY (never a 403; the owner self-serves their
        // own account via account settings, not this list).
        $this->readOnly = ! (new AgencyUserRoles)->canManage(Auth::user(), $user->agency_role);

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
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
        abort_if($this->readOnly, 403); // a forged save against the view-only owner record

        // Grantable roles PLUS the target's current role — keeping the role
        // unchanged is always legal for anyone who may manage the target.
        $allowed = array_unique([
            ...array_map(fn (AgencyRole $r) => $r->value, $this->assignableRoles()),
            $this->user->agency_role->value,
        ]);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'password' => ['nullable', 'string', Password::defaults()],
            'agency_role' => ['required', Rule::in($allowed)],
            'salon_ids' => ['array'],
            'salon_ids.*' => ['integer'],
        ]);

        $action->handle(Auth::user(), $this->user->agency, $this->user, $validated);
        $this->user->refresh();
        $this->password = '';

        Flux::toast(variant: 'success', text: __('User updated.'));
    }

    public function deleteUser(DeleteAgencyUser $action): void
    {
        $action->handle(Auth::user(), $this->user);

        session()->flash('status', __('User deleted. Anything they created keeps their name.'));
        $this->redirectRoute('agency.users.index', navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="$readOnly ? __('Agency user') : __('Edit agency user')" :title="$user->name">
            <x-slot:subtitle>{{ $user->email }}</x-slot:subtitle>
        </x-ui.page-header>

        @if ($readOnly)
            {{-- The agency owner's record: read-only by design, never a 403.
                 The owner manages their own account via account settings. --}}
            <x-ui.card class="flex flex-col gap-5">
                <dl class="flex flex-col gap-4">
                    <div>
                        <dt class="bts-overline">{{ __('Name') }}</dt>
                        <dd class="text-[15px] text-ink">{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt class="bts-overline">{{ __('Email') }}</dt>
                        <dd class="text-[15px] text-ink">{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt class="bts-overline">{{ __('Agency role') }}</dt>
                        <dd class="text-[15px] text-ink">{{ $user->agency_role->label() }}</dd>
                    </div>
                </dl>
                <flux:text class="text-sm text-secondary">{{ __('The agency owner\'s record is view-only here. The owner updates their own details in account settings.') }}</flux:text>
                <div>
                    <x-ui.button variant="secondary" :href="route('agency.users.index')" wire:navigate>{{ __('Back') }}</x-ui.button>
                </div>
            </x-ui.card>
        @else
        <x-ui.card>
        <form wire:submit="save" class="flex flex-col gap-6" novalidate>
            <flux:input wire:model="name" :label="__('Name')" required />

            <flux:input wire:model="email" type="email" :label="__('Email')" required
                :description="$user->sharedOutsideAgency($user->agency_id) ? __('This login is also used in another agency\'s salon — only the account holder can change its email.') : null" />

            <flux:input wire:model="password" type="password" :label="__('New password')" autocomplete="new-password"
                :description="__('Leave blank to keep the current password. If you set one, they\'ll be asked to choose their own at next sign-in.')" />

            <flux:select wire:model.live="agency_role" :label="__('Agency role')">
                @foreach (collect($this->assignableRoles)->push($user->agency_role)->unique() as $role)
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
        @endif

        {{-- Deletion stays on the narrow GRANT axis (an admin manages a
             peer's details but cannot delete them) — hide the card when
             the action would refuse. --}}
        @if (! $readOnly && $user->id !== Auth::id() && (new AgencyUserRoles)->canAssign(Auth::user(), $user->agency_role))
            <x-ui.card class="flex flex-col gap-3">
                <h2 class="bts-card-title">{{ __('Delete user') }}</h2>
                <flux:text class="text-sm text-secondary">{{ __('Permanently removes this account and its salon access. Anything they created — bookings, notes, history — is kept under their name. Prefer editing their salon scope if they just need less access.') }}</flux:text>
                <div>
                    <x-ui.button variant="danger"
                        x-on:click="$store.confirm.ask({
                            title: {{ Js::from(__('Delete user')) }},
                            message: {{ Js::from(__('Delete this user? Their account and salon access are removed permanently. Bookings and history are kept.')) }},
                            confirmLabel: {{ Js::from(__('Delete')) }},
                            danger: true,
                        }, () => $wire.deleteUser())">{{ __('Delete user') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
