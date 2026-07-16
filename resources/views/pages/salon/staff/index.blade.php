<?php

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateStaffMembership;
use App\Actions\Stylists\UpdateStylistProfile;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\StylistProfile;
use App\Support\Permissions\SalonStaffRoles;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff')] class extends Component {
    public Salon $salon;

    public string $name = '';
    public string $email = '';
    public string $role = 'user';
    public string $staff_type = 'stylist';

    public ?int $editingId = null;
    public string $editRole = 'user';
    public string $editStaffType = 'stylist';
    public string $editBio = '';
    public bool $showEdit = false;

    public ?string $temporaryPassword = null;
    public ?string $tempForName = null;
    public bool $showTempPassword = false;

    public function mount(Salon $salon): void
    {
        // resolve.salon already confirmed the actor may reach this salon.
        $this->authorize('manageStaff', $salon);
        $this->salon = $salon;
    }

    /**
     * @return list<SalonRole>
     */
    #[Computed]
    public function assignableRoles(): array
    {
        return (new SalonStaffRoles)->assignable(Auth::user(), $this->salon);
    }

    #[Computed]
    public function memberships()
    {
        return $this->salon->memberships()
            ->with('user:id,name,email')
            ->orderByRaw("CASE salon_role WHEN 'salon_owner' THEN 0 WHEN 'salon_admin' THEN 1 ELSE 2 END")
            ->get();
    }

    private function roleValues(): array
    {
        return array_map(fn (SalonRole $r) => $r->value, $this->assignableRoles());
    }

    /**
     * Allowed staff-type submissions: any type, or '' = no staff function.
     * Type is orthogonal to role and grants no permissions itself.
     */
    private function staffTypeValues(): array
    {
        return ['', ...array_column(StaffType::cases(), 'value')];
    }

    /** Members default to stylist; owners/admins to no staff function. */
    public function updatedRole(string $value): void
    {
        $this->staff_type = $value === 'user' ? 'stylist' : '';
    }

    public function updatedEditRole(string $value): void
    {
        $this->editStaffType = $value === 'user' ? 'stylist' : '';
    }

    /**
     * Whether the current actor may manage a given membership (i.e. has
     * authority over its role). Hides the row actions; the server enforces it
     * regardless in every action.
     */
    public function canManageMembership(SalonMembership $membership): bool
    {
        return (new SalonStaffRoles)->canAssign(Auth::user(), $this->salon, $membership->salon_role);
    }

    public function invite(InviteStaff $action): void
    {
        $this->authorize('manageStaff', $this->salon);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->roleValues())],
            'staff_type' => ['nullable', Rule::in($this->staffTypeValues())],
        ]);

        $result = $action->handle(Auth::user(), $this->salon, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'salon_role' => $validated['role'],
            'staff_type' => $validated['staff_type'] ?? null,
        ]);

        unset($this->memberships);
        $this->reset(['name', 'email', 'role', 'staff_type']);
        $this->role = 'user';
        $this->staff_type = 'stylist';

        if ($result->existing) {
            Flux::toast(variant: 'success', text: __('Existing user added to this salon.'));

            return;
        }

        $this->temporaryPassword = $result->temporaryPassword;
        $this->tempForName = $result->user->name;
        $this->showTempPassword = true;
    }

    public function startEdit(int $membershipId): void
    {
        $membership = $this->membership($membershipId);
        abort_unless((new SalonStaffRoles)->canAssign(Auth::user(), $this->salon, $membership->salon_role), 403);

        $this->editingId = $membership->id;
        $this->editRole = $membership->salon_role->value;
        $this->editStaffType = $membership->staff_type?->value
            ?? ($membership->salon_role === SalonRole::User ? 'stylist' : '');
        $this->editBio = (string) StylistProfile::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $membership->user_id)
            ->value('bio');
        $this->showEdit = true;
    }

    public function saveEdit(UpdateStaffMembership $action, UpdateStylistProfile $profile): void
    {
        $membership = $this->membership((int) $this->editingId);

        $this->validate([
            'editRole' => ['required', Rule::in($this->roleValues())],
            'editStaffType' => ['nullable', Rule::in($this->staffTypeValues())],
            'editBio' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->handle(Auth::user(), $this->salon, $membership, [
            'salon_role' => $this->editRole,
            'staff_type' => $this->editStaffType,
        ]);

        // Bio lives on StylistProfile per (user, salon); only stylists carry one.
        if ($this->editRole === 'user' && $this->editStaffType === StaffType::Stylist->value) {
            $profile->handle(Auth::user(), $this->salon, $membership->user_id, $this->editBio ?: null);
        }

        $this->showEdit = false;
        $this->editingId = null;
        unset($this->memberships);

        Flux::toast(variant: 'success', text: __('Staff member updated.'));
    }

    public function toggleActive(int $membershipId, SetMembershipActive $action): void
    {
        $membership = $this->membership($membershipId);
        $action->handle(Auth::user(), $this->salon, $membership, ! $membership->active);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $membership->active ? __('Staff member reactivated.') : __('Staff member deactivated.'));
    }

    public function resetPassword(int $membershipId, ResetStaffPassword $action): void
    {
        $membership = $this->membership($membershipId);
        $this->temporaryPassword = $action->handle(Auth::user(), $this->salon, $membership);
        $this->tempForName = $membership->user->name;
        $this->showTempPassword = true;
    }

    /**
     * Fetch a membership scoped to this salon — out-of-salon ids 404 (no IDOR).
     */
    private function membership(int $id): SalonMembership
    {
        return $this->salon->memberships()->whereKey($id)->firstOrFail();
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Manage')" :title="__('Staff')" />

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Invite staff') }}</h2>
            <form wire:submit="invite" class="flex flex-col gap-4" novalidate>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:input wire:model="email" type="email" :label="__('Email')" required />
                    <flux:select wire:model.live="role" :label="__('Role')">
                        @foreach ($this->assignableRoles as $r)
                            <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="staff_type" :label="__('Staff type')">
                        @if ($role !== 'user')
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                        @endif
                        <flux:select.option value="stylist">{{ __('Stylist') }}</flux:select.option>
                        <flux:select.option value="front_desk">{{ __('Front desk') }}</flux:select.option>
                        <flux:select.option value="manager">{{ __('Manager') }}</flux:select.option>
                    </flux:select>
                </div>
                <div>
                    <x-ui.button type="submit" loading="invite"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Send invite') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Role') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Type') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th scope="col" class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->memberships as $m)
                        <tr @class(['bg-muted/40' => ! $m->active])>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$m->user->name" :seed="$m->user->id" size="sm" />
                                    <div>
                                        <div class="text-[15px] font-medium text-ink">{{ $m->user->name }}</div>
                                        <div class="text-[12.5px] text-faint">{{ $m->user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $m->salon_role->label() }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $m->staff_type?->label() ?? '—' }}</td>
                            <td class="px-6 py-4">
                                @if ($m->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-4">
                                    @if ($this->canManageMembership($m))
                                        <button type="button" wire:click="startEdit({{ $m->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                                        {{-- Themed confirms (replace wire:confirm); reactivating commits without one, as before. --}}
                                        <button type="button"
                                                x-on:click="$store.confirm.ask({
                                                    title: {{ Js::from(__('Reset password')) }},
                                                    message: {{ Js::from(__('Reset :name\'s password? Their current password stops working immediately and a new temporary password is shown once.', ['name' => $m->user->name])) }},
                                                    confirmLabel: {{ Js::from(__('Reset')) }},
                                                    danger: false,
                                                }, () => $wire.resetPassword({{ $m->id }}))"
                                                class="text-[13px] font-medium text-secondary transition hover:text-ink">{{ __('Reset password') }}</button>
                                        <button type="button"
                                                @if ($m->active)
                                                    x-on:click="$store.confirm.ask({
                                                        title: {{ Js::from(__('Deactivate member')) }},
                                                        message: {{ Js::from(__('Deactivate :name? They lose access to this salon; their bookings and history are kept.', ['name' => $m->user->name])) }},
                                                        confirmLabel: {{ Js::from(__('Deactivate')) }},
                                                        danger: true,
                                                    }, () => $wire.toggleActive({{ $m->id }}))"
                                                @else
                                                    wire:click="toggleActive({{ $m->id }})"
                                                @endif
                                                class="text-[13px] font-medium text-secondary transition hover:text-ink">
                                            {{ $m->active ? __('Deactivate') : __('Reactivate') }}
                                        </button>
                                    @else
                                        <span class="text-[13px] text-faint">{{ __('—') }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No staff yet. Invite someone above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showEdit" class="max-w-md" :heading="__('Edit staff member')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5" novalidate>
            <flux:select wire:model.live="editRole" :label="__('Role')">
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="editStaffType" :label="__('Staff type')">
                @if ($editRole !== 'user')
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                @endif
                <flux:select.option value="stylist">{{ __('Stylist') }}</flux:select.option>
                <flux:select.option value="front_desk">{{ __('Front desk') }}</flux:select.option>
                <flux:select.option value="manager">{{ __('Manager') }}</flux:select.option>
            </flux:select>
            @if ($editRole === 'user' && $editStaffType === 'stylist')
                <flux:textarea wire:model="editBio" :label="__('Bio')" rows="3" :placeholder="__('A short bio for this stylist.')" />
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showTempPassword" class="max-w-md"
        :heading="$tempForName ? __('Temporary password for :name', ['name' => $tempForName]) : __('Temporary password')">
        @if ($temporaryPassword)
            <x-temp-password-panel :name="$tempForName" :password="$temporaryPassword" :show-heading="false" />
        @endif
        <div class="flex justify-end">
            <x-ui.button wire:click="$set('showTempPassword', false)">{{ __('Done') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
