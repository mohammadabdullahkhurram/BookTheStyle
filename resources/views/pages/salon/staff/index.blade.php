<?php

use App\Actions\Staff\DeleteStaffUser;
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

new #[Title('Users')] class extends Component {
    public Salon $salon;

    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = 'stylist';
    public bool $showAdd = false;

    public ?int $editingId = null;
    public string $editRole = 'stylist';
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
            ->orderByRaw("CASE salon_role WHEN 'salon_owner' THEN 0 WHEN 'salon_manager' THEN 1 ELSE 2 END")
            ->get();
    }

    private function roleValues(): array
    {
        return array_map(fn (SalonRole $r) => $r->value, $this->assignableRoles());
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

    /** Open the add-user modal with a clean slate. */
    public function startAdd(): void
    {
        $this->authorize('manageStaff', $this->salon);

        $this->reset(['name', 'email', 'phone']);
        $this->role = 'stylist';
        $this->resetErrorBag();
        $this->showAdd = true;
    }

    public function invite(InviteStaff $action): void
    {
        $this->authorize('manageStaff', $this->salon);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in($this->roleValues())],
        ]);

        $result = $action->handle(Auth::user(), $this->salon, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'salon_role' => $validated['role'],
        ]);

        unset($this->memberships);
        $this->reset(['name', 'email', 'phone', 'role']);
        $this->role = 'stylist';
        $this->showAdd = false;

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
            'editBio' => ['nullable', 'string', 'max:2000'],
        ]);

        $action->handle(Auth::user(), $this->salon, $membership, [
            'salon_role' => $this->editRole,
        ]);

        // Bio lives on StylistProfile per (user, salon); only stylists carry one.
        if ($this->editRole === SalonRole::Stylist->value) {
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

    public function deleteMember(int $membershipId, DeleteStaffUser $action): void
    {
        $membership = $this->membership($membershipId);
        $accountDeleted = $action->handle(Auth::user(), $this->salon, $membership);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $accountDeleted
            ? __('Staff member deleted. Their bookings and history are kept.')
            : __('Removed from this salon. They keep access elsewhere; bookings and history are kept.'));
    }

    public function resetPassword(int $membershipId, ResetStaffPassword $action): void
    {
        $membership = $this->membership($membershipId);
        $this->temporaryPassword = $action->handle(Auth::user(), $this->salon, $membership);
        $this->tempForName = $membership->user->name;
        $this->showTempPassword = true;
    }

    // ------------------------------------------------------------------
    // Owner details (SPEC §2 refinement): AGENCY owner/admin may edit the
    // salon owner's name/email/phone — they operate the platform and created
    // the salon. Salon managers/stylists never can; the owner edits
    // themselves through account settings.
    // ------------------------------------------------------------------

    public bool $showOwnerEdit = false;
    public ?int $ownerEditId = null;
    public string $ownerName = '';
    public string $ownerEmail = '';
    public string $ownerPhone = '';

    public function canEditOwnerDetails(SalonMembership $membership): bool
    {
        return $membership->salon_role === SalonRole::Owner
            && Auth::user()->isAgencyOperator()
            && Auth::user()->agency_id === $this->salon->agency_id;
    }

    public function startOwnerEdit(int $membershipId): void
    {
        $membership = $this->membership($membershipId);
        abort_unless($this->canEditOwnerDetails($membership), 403);

        $this->ownerEditId = $membership->id;
        $this->ownerName = $membership->user->name;
        $this->ownerEmail = $membership->user->email;
        $this->ownerPhone = (string) $membership->user->phone;
        $this->resetErrorBag();
        $this->showOwnerEdit = true;
    }

    public function saveOwnerDetails(): void
    {
        $membership = $this->membership((int) $this->ownerEditId);
        abort_unless($this->canEditOwnerDetails($membership), 403);

        $validated = $this->validate([
            'ownerName' => ['required', 'string', 'max:255'],
            'ownerEmail' => ['required', 'string', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($membership->user_id)->withoutTrashed()],
            'ownerPhone' => ['nullable', 'string', 'max:32'],
        ]);

        $membership->user->forceFill([
            'name' => $validated['ownerName'],
            'email' => $validated['ownerEmail'],
            'phone' => $validated['ownerPhone'] ?: null,
        ])->save();

        $this->showOwnerEdit = false;
        $this->ownerEditId = null;
        unset($this->memberships);

        Flux::toast(variant: 'success', text: __('Owner details updated.'));
    }

    /**
     * The owner-who-cuts-hair switch: ONLY the owner, on their OWN row, may
     * flip whether they take bookings (staff_type stylist ⇄ null). Nobody
     * else can touch the owner (canAssign refuses Owner as a target).
     */
    public function toggleOwnerBookable(int $membershipId): void
    {
        $membership = $this->membership($membershipId);

        abort_unless(
            $membership->user_id === Auth::id()
                && $membership->salon_role === SalonRole::Owner,
            403,
        );

        $membership->update([
            'staff_type' => $membership->staff_type === StaffType::Stylist ? null : StaffType::Stylist,
        ]);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $membership->staff_type === StaffType::Stylist
            ? __('You now appear as a bookable stylist.')
            : __('You no longer take bookings.'));
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
        <x-ui.page-header :overline="__('Manage')" :title="__('Users')">
            <x-slot:subtitle>{{ __('Everyone with access to this salon.') }}</x-slot:subtitle>
            <x-slot:actions>
                <x-ui.button wire:click="startAdd"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add user') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Desktop table (mirrors the Clients directory rhythm). --}}
        <x-ui.card padding="p-0" class="hidden overflow-hidden md:block">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Phone') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Role') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th scope="col" class="px-6 py-3.5 text-right"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->memberships as $m)
                        <tr wire:key="member-{{ $m->id }}" @class(['bg-muted/40' => ! $m->active])>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$m->user->name" :seed="$m->user->id" size="sm" />
                                    <div class="min-w-0">
                                        <div class="truncate text-[15px] font-medium text-ink">{{ $m->user->name }}</div>
                                        <div class="truncate text-[12.5px] text-faint">{{ $m->user->email }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[14px] text-secondary">{{ $m->user->phone ?: '—' }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2 text-[14px] text-secondary">
                                    <span>{{ $m->salon_role->label() }}</span>
                                    @if ($m->salon_role === \App\Enums\SalonRole::Owner && $m->staff_type === \App\Enums\StaffType::Stylist)
                                        <span class="bts-pill" style="background-color:var(--accent-tint);color:var(--accent-ink);">{{ __('Takes bookings') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($m->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-3">
                                    @include('pages.salon.staff.partials.row-actions', ['m' => $m, 'canManage' => $this->canManageMembership($m), 'canEditOwner' => $this->canEditOwnerDetails($m)])
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-[15px] font-medium text-body">{{ __('No users yet.') }}</p>
                                <p class="mt-1 text-[13.5px] text-secondary">{{ __('Use Add user to invite your first manager or stylist.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-ui.card>

        {{-- Mobile: stacked cards (the Clients directory pattern). --}}
        <div class="flex flex-col divide-y divide-row border-t border-divider md:hidden">
            @forelse ($this->memberships as $m)
                <div wire:key="member-m-{{ $m->id }}" @class(['flex flex-col gap-2.5 py-4', 'opacity-70' => ! $m->active])>
                    <div class="flex items-center gap-3">
                        <x-ui.avatar :name="$m->user->name" :seed="$m->user->id" size="sm" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[15px] font-medium text-ink">{{ $m->user->name }}</div>
                            <div class="truncate text-[12.5px] text-faint">{{ $m->user->email }}</div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            @include('pages.salon.staff.partials.row-actions', ['m' => $m, 'canManage' => $this->canManageMembership($m), 'canEditOwner' => $this->canEditOwnerDetails($m)])
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 ps-11 text-[13px] text-secondary">
                        <span>{{ $m->salon_role->label() }}</span>
                        @if ($m->salon_role === \App\Enums\SalonRole::Owner && $m->staff_type === \App\Enums\StaffType::Stylist)
                            <span class="bts-pill" style="background-color:var(--accent-tint);color:var(--accent-ink);">{{ __('Takes bookings') }}</span>
                        @endif
                        @unless ($m->active)
                            <span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
                        @endunless
                        @if ($m->user->phone)
                            <span class="text-faint">· {{ $m->user->phone }}</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-12 text-center">
                    <p class="text-[15px] font-medium text-body">{{ __('No users yet.') }}</p>
                    <p class="mt-1 text-[13.5px] text-secondary">{{ __('Use Add user to invite your first manager or stylist.') }}</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Add user: revealed on demand so the LIST leads the page (same modal
         pattern as Edit below). Single column — no grid for helper text to
         break; the role note sits under its own field. --}}
    <x-ui.modal wire:model="showAdd" class="max-w-md" :heading="__('Add user')">
        <form wire:submit="invite" class="flex flex-col gap-5" novalidate>
            <flux:input wire:model="name" :label="__('Name')" required autofocus />
            <flux:input wire:model="email" type="email" :label="__('Email')" required />
            <flux:input wire:model="phone" type="tel" :label="__('Phone')" autocomplete="tel" />
            <flux:select wire:model="role" :label="__('Role')"
                :description="__('Stylists are bookable and see only their own schedule; managers run the salon.')">
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showAdd', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" loading="invite">{{ __('Send invite') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showEdit" class="max-w-md" :heading="__('Edit user')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5" novalidate>
            <flux:select wire:model.live="editRole" :label="__('Role')">
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($editRole === 'stylist')
                <flux:textarea wire:model="editBio" :label="__('Bio')" rows="3" :placeholder="__('A short bio for this stylist.')" />
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showOwnerEdit" class="max-w-md" :heading="__('Edit owner details')">
        <form wire:submit="saveOwnerDetails" class="flex flex-col gap-5" novalidate>
            <flux:input wire:model="ownerName" :label="__('Name')" required />
            <flux:input wire:model="ownerEmail" type="email" :label="__('Email')" required />
            <flux:input wire:model="ownerPhone" type="tel" :label="__('Phone')" />
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showOwnerEdit', false)">{{ __('Cancel') }}</x-ui.button>
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
