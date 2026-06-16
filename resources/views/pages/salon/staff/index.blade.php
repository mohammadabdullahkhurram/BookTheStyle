<?php

use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateStaffMembership;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use App\Models\SalonMembership;
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

    public function invite(InviteStaff $action): void
    {
        $this->authorize('manageStaff', $this->salon);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in($this->roleValues())],
            'staff_type' => ['nullable', Rule::in([StaffType::Stylist->value, StaffType::FrontDesk->value])],
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
        $this->editStaffType = $membership->staff_type?->value ?? 'stylist';
        $this->showEdit = true;
    }

    public function saveEdit(UpdateStaffMembership $action): void
    {
        $membership = $this->membership((int) $this->editingId);

        $this->validate([
            'editRole' => ['required', Rule::in($this->roleValues())],
            'editStaffType' => ['nullable', Rule::in([StaffType::Stylist->value, StaffType::FrontDesk->value])],
        ]);

        $action->handle(Auth::user(), $this->salon, $membership, [
            'salon_role' => $this->editRole,
            'staff_type' => $this->editStaffType,
        ]);

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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Staff') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <form wire:submit="invite" class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
            <flux:heading size="sm" class="font-serif">{{ __('Invite staff') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />
                <flux:select wire:model.live="role" :label="__('Role')">
                    @foreach ($this->assignableRoles as $r)
                        <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($role === 'user')
                    <flux:select wire:model="staff_type" :label="__('Staff type')">
                        <flux:select.option value="stylist">{{ __('Stylist') }}</flux:select.option>
                        <flux:select.option value="front_desk">{{ __('Front Desk') }}</flux:select.option>
                    </flux:select>
                @endif
            </div>
            <div>
                <flux:button type="submit" variant="primary" icon="plus">{{ __('Send invite') }}</flux:button>
            </div>
        </form>

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Role') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Type') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->memberships as $m)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink">{{ $m->user->name }}</div>
                                <div class="text-xs text-secondary">{{ $m->user->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-secondary">{{ $m->salon_role->label() }}</td>
                            <td class="px-4 py-3 text-secondary">{{ $m->staff_type?->label() ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($m->active)
                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $m->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="resetPassword({{ $m->id }})">{{ __('Reset password') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggleActive({{ $m->id }})">
                                        {{ $m->active ? __('Deactivate') : __('Reactivate') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-secondary">{{ __('No staff yet. Invite someone above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal wire:model="showEdit" class="max-w-md">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:heading size="lg" class="font-serif">{{ __('Edit staff member') }}</flux:heading>
            <flux:select wire:model.live="editRole" :label="__('Role')">
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($editRole === 'user')
                <flux:select wire:model="editStaffType" :label="__('Staff type')">
                    <flux:select.option value="stylist">{{ __('Stylist') }}</flux:select.option>
                    <flux:select.option value="front_desk">{{ __('Front Desk') }}</flux:select.option>
                </flux:select>
            @endif
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showTempPassword" class="max-w-md">
        @if ($temporaryPassword)
            <x-temp-password-panel :name="$tempForName" :password="$temporaryPassword" />
        @endif
        <div class="mt-4 flex justify-end">
            <flux:button variant="primary" wire:click="$set('showTempPassword', false)">{{ __('Done') }}</flux:button>
        </div>
    </flux:modal>
</div>
