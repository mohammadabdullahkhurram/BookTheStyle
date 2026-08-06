<?php

use App\Actions\Salons\SetSalonOwner;
use App\Actions\Staff\DeleteStaffUser;
use App\Actions\Staff\InviteStaff;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\SetMembershipActive;
use App\Actions\Staff\UpdateMemberDetails;
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
    public string $arrangement = 'employee';
    /** Takes-bookings at invite time — meaningful for the optionally-bookable
     *  roles (manager here; owners are never invited). Stylists are
     *  inherently bookable, so the box never shows for them. */
    public bool $takesBookings = false;
    public bool $showAdd = false;

    public ?int $editingId = null;
    public string $editRole = 'stylist';
    public string $editArrangement = 'employee';
    public string $editBio = '';
    /** Takes-bookings in the edit modal — the same staff_type flag the
     *  self-row switch and the Add modal write. Shown when the selected
     *  role is Manager; initialised from the member's CURRENT bookable
     *  state, so a stylist switched to Manager defaults to staying
     *  bookable and can be unticked in the same save. */
    public bool $editTakesBookings = false;
    public bool $showEdit = false;
    public bool $editingOwner = false;

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
            ->with('user:id,name,email,phone')
            ->orderByRaw("CASE salon_role WHEN 'salon_owner' THEN 0 WHEN 'salon_manager' THEN 1 ELSE 2 END")
            ->get();
    }

    private function roleValues(): array
    {
        return array_map(fn (SalonRole $r) => $r->value, $this->assignableRoles());
    }

    /** Only MIX salons choose per stylist; other types force the arrangement. */
    #[Computed]
    public function arrangementSelectable(): bool
    {
        return $this->salon->salon_type === \App\Enums\SalonType::Mix;
    }

    /**
     * Whether the current actor may manage a given membership (i.e. has
     * authority over its role). Hides the row actions; the server enforces it
     * regardless in every action.
     */
    public function canManageMembership(SalonMembership $membership): bool
    {
        return (new SalonStaffRoles)->canManage(Auth::user(), $this->salon, $membership->salon_role);
    }

    /** Open the add-user modal with a clean slate. */
    public function startAdd(): void
    {
        $this->authorize('manageStaff', $this->salon);

        $this->reset(['name', 'email', 'phone', 'arrangement', 'takesBookings']);
        $this->role = 'stylist';
        $this->resetErrorBag();
        $this->showAdd = true;
    }

    public function invite(InviteStaff $action): void
    {
        $this->authorize('manageStaff', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in($this->roleValues())],
            'arrangement' => ['required', Rule::in(array_column(\App\Enums\StylistArrangement::cases(), 'value'))],
            'takesBookings' => ['boolean'],
        ]);

        $result = $action->handle(Auth::user(), $this->salon, [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'salon_role' => $validated['role'],
            // Same bookability field the edit-flow toggle writes: an explicit
            // 'stylist' marks an optionally-bookable role bookable at invite;
            // otherwise the role's default applies (stylists inherently).
            'staff_type' => $validated['role'] === SalonRole::Manager->value && $this->takesBookings
                ? \App\Enums\StaffType::Stylist->value
                : null,
            'arrangement' => $validated['arrangement'],
        ]);

        unset($this->memberships);
        $this->reset(['name', 'email', 'phone', 'role', 'arrangement', 'takesBookings']);
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
        abort_unless((new SalonStaffRoles)->canManage(Auth::user(), $this->salon, $membership->salon_role), 403);

        $this->editingId = $membership->id;
        $this->editingOwner = $membership->salon_role === SalonRole::Owner;
        $this->editRole = $membership->salon_role->value;
        $this->editTakesBookings = $membership->staff_type === StaffType::Stylist;
        $this->editArrangement = $membership->arrangement->value;
        $this->editBio = (string) StylistProfile::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $membership->user_id)
            ->value('bio');
        $this->showEdit = true;
    }

    public function saveEdit(UpdateStaffMembership $action, UpdateStylistProfile $profile): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership((int) $this->editingId);
        $isOwner = $membership->salon_role === SalonRole::Owner;

        $this->validate([
            'editRole' => ['required', Rule::in([...$this->roleValues(), ...($isOwner ? [SalonRole::Owner->value] : [])])],
            'editArrangement' => ['required', Rule::in(array_column(\App\Enums\StylistArrangement::cases(), 'value'))],
            'editBio' => ['nullable', 'string', 'max:2000'],
            'editTakesBookings' => ['boolean'],
        ]);

        if ($isOwner) {
            // The only membership edit an owner row offers is a role change,
            // and stripping the owner role is an ownership TRANSFER: the
            // salon must end with an owner, so a replacement is designated
            // in the same flow before anything happens.
            if ($this->editRole === SalonRole::Owner->value) {
                $this->showEdit = false;
                $this->editingId = null;

                return;
            }

            $this->startOwnerTransfer(
                $membership->id,
                'demote',
                $this->editRole,
                demoteBookable: $this->editRole === SalonRole::Manager->value && $this->editTakesBookings,
            );

            return;
        }

        $data = [
            'salon_role' => $this->editRole,
            'arrangement' => $this->editArrangement,
        ];

        // For the Manager role the checkbox is authoritative — the same
        // staff_type flag the self-row switch writes (explicit null clears
        // it). Other roles keep their own semantics: stylists inherently
        // bookable, so the key stays absent.
        if ($this->editRole === SalonRole::Manager->value) {
            $data['staff_type'] = $this->editTakesBookings ? StaffType::Stylist->value : null;
        }

        try {
            $action->handle(Auth::user(), $this->salon, $membership, $data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Server-side rejections must be VISIBLE. The action speaks in
            // domain keys (salon_role, staff_type, …) no modal field is
            // bound to — unmapped, the modal would sit there as a silent
            // no-op (the live "manager won't save" failure mode). Re-key
            // onto the form's own fields so the message always renders.
            throw \Illuminate\Validation\ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => [
                    match ($key) {
                        'staff_type' => 'editTakesBookings',
                        'arrangement' => 'editArrangement',
                        default => 'editRole',
                    } => $messages,
                ])->all(),
            );
        }

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
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership($membershipId);
        $action->handle(Auth::user(), $this->salon, $membership, ! $membership->active);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $membership->active ? __('Staff member reactivated.') : __('Staff member deactivated.'));
    }

    public function deleteMember(int $membershipId, DeleteStaffUser $action): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership($membershipId);
        $accountDeleted = $action->handle(Auth::user(), $this->salon, $membership);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $accountDeleted
            ? __('Staff member deleted. Their bookings and history are kept.')
            : __('Removed from this salon. They keep access elsewhere; bookings and history are kept.'));
    }

    public function resetPassword(int $membershipId, ResetStaffPassword $action): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Password resets are disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership($membershipId);
        $this->temporaryPassword = $action->handle(Auth::user(), $this->salon, $membership);
        $this->tempForName = $membership->user->name;
        $this->showTempPassword = true;
    }

    // ------------------------------------------------------------------
    // Member details (SPEC §2 refinement): AGENCY owner/admin may edit ANY
    // member's name/email/phone across their own agency's salons — owner
    // included; they operate the platform and created the salon. Salon
    // managers/stylists never can; people edit themselves through account
    // settings. (Method/property names keep the original owner-* spelling
    // from when this surface was owner-only.)
    // ------------------------------------------------------------------

    public bool $showOwnerEdit = false;
    public ?int $ownerEditId = null;
    public string $ownerName = '';
    public string $ownerEmail = '';
    public string $ownerPhone = '';
    /** Takes-bookings in the DETAILS modal, for MANAGER members only — this
     *  modal has no role selector, so it keys off the member's existing
     *  role. Same staff_type flag as every other surface; persisted through
     *  UpdateStaffMembership alongside the details save. Owners keep their
     *  own controls (self-row switch + agency console); stylists are
     *  inherently bookable and show no box. */
    public bool $ownerEditIsManager = false;
    public bool $ownerEditTakesBookings = false;
    /** The target account also exists under another agency (memberships or
     *  console role elsewhere): name/phone edits apply account-wide, and the
     *  login email is locked to the account holder (UpdateMemberDetails
     *  enforces the block server-side — this flag only drives the notice). */
    public bool $ownerEditShared = false;

    public function canEditMemberDetails(): bool
    {
        return Auth::user()->isAgencyOperator()
            && Auth::user()->agency_id === $this->salon->agency_id;
    }

    public function startOwnerEdit(int $membershipId): void
    {
        $membership = $this->membership($membershipId);
        abort_unless($this->canEditMemberDetails(), 403);

        $this->ownerEditId = $membership->id;
        $this->ownerName = $membership->user->name;
        $this->ownerEmail = $membership->user->email;
        $this->ownerPhone = (string) $membership->user->phone;
        $this->ownerEditShared = $membership->user->sharedOutsideAgency($this->salon->agency_id);
        $this->ownerEditIsManager = $membership->salon_role === SalonRole::Manager;
        $this->ownerEditTakesBookings = $membership->staff_type === StaffType::Stylist;
        $this->resetErrorBag();
        $this->showOwnerEdit = true;
    }

    public function saveOwnerDetails(UpdateMemberDetails $action, UpdateStaffMembership $membershipAction): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership((int) $this->ownerEditId);
        abort_unless($this->canEditMemberDetails(), 403);

        $validated = $this->validate([
            'ownerName' => ['required', 'string', 'max:255'],
            'ownerEmail' => ['required', 'string', 'email', 'max:255', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($membership->user_id)->withoutTrashed()],
            'ownerPhone' => ['nullable', 'string', 'max:32'],
            'ownerEditTakesBookings' => ['boolean'],
        ]);

        try {
            $action->handle(Auth::user(), $this->salon, $membership, [
                'name' => $validated['ownerName'],
                'email' => $validated['ownerEmail'],
                'phone' => $validated['ownerPhone'] ?: null,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The action speaks in account-field names; this form's inputs
            // are owner-prefixed. Re-key so errors land under the field.
            throw \Illuminate\Validation\ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => [
                    'owner'.ucfirst($key) => $messages,
                ])->all(),
            );
        }

        // MANAGER members: the details modal also carries the takes-bookings
        // box (this modal has no role selector, so it keys off the member's
        // existing role). Applied through the same UpdateStaffMembership
        // mechanism as every other surface — only when it actually changed.
        $wantsType = $this->ownerEditTakesBookings ? StaffType::Stylist : null;
        if ($membership->salon_role === SalonRole::Manager && $membership->staff_type !== $wantsType) {
            try {
                $membershipAction->handle(Auth::user(), $this->salon, $membership, [
                    'salon_role' => SalonRole::Manager->value,
                    'staff_type' => $wantsType?->value,
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(
                    ['ownerEditTakesBookings' => collect($e->errors())->flatten()->all()],
                );
            }
        }

        $this->showOwnerEdit = false;
        $this->ownerEditId = null;
        unset($this->memberships);

        Flux::toast(variant: 'success', text: __('Member details updated.'));
    }

    /**
     * The takes-bookings switch: an OWNER or MANAGER, on their OWN row only,
     * may flip whether they take bookings (staff_type stylist ⇄ null) —
     * the owner-who-cuts-hair capability, extended to managers. Stylists
     * are inherently bookable and have no switch.
     */
    public function toggleOwnerBookable(int $membershipId): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Managing staff is disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership($membershipId);

        abort_unless(
            $membership->user_id === Auth::id()
                && $membership->salon_role->isManager(),
            403,
        );

        // Turning bookings OFF must never orphan upcoming appointments.
        if ($membership->staff_type === StaffType::Stylist) {
            $upcoming = $this->salon->bookings()
                ->where('status', '!=', \App\Enums\BookingStatus::Cancelled->value)
                ->whereHas('items', fn ($q) => $q->where('stylist_id', $membership->user_id)->where('starts_at', '>', now()))
                ->count();

            if ($upcoming > 0) {
                Flux::toast(variant: 'danger', text: trans_choice('You still have :count upcoming appointment — reassign or cancel it first.|You still have :count upcoming appointments — reassign or cancel them first.', $upcoming, ['count' => $upcoming]));

                return;
            }
        }

        $membership->update([
            'staff_type' => $membership->staff_type === StaffType::Stylist ? null : StaffType::Stylist,
        ]);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: $membership->staff_type === StaffType::Stylist
            ? __('You now appear as a bookable stylist.')
            : __('You no longer take bookings.'));
    }

    // ------------------------------------------------------------------
    // Owner-transfer safeguard: any action that would strip the salon's
    // owner (demote / deactivate / delete) is blocked until a replacement
    // is designated IN THE SAME FLOW. Two explicit paths: ADD A NEW OWNER
    // (the standard add-user provisioning — same fields, same engine, same
    // invite mails; an existing account for that email is linked instead,
    // exactly like any invite) or MAKE ME THE OWNER (the acting operator's
    // take-over — no mail, you don't invite yourself). SetSalonOwner
    // enforces the one-active-owner invariant server-side; this modal is
    // the UI at the point of removal. Agency operators only: salon roles
    // never reach it because they hold no authority over the owner.
    // ------------------------------------------------------------------

    public bool $showTransfer = false;
    public ?int $transferMembershipId = null;
    public string $transferAction = '';
    public string $transferChoice = '';
    public string $transferDemoteRole = '';
    /** When demoting the owner TO MANAGER, the edit modal's takes-bookings
     *  checkbox is authoritative for the demoted ex-owner too — its value is
     *  captured here and applied after the transfer completes. */
    public bool $transferDemoteBookable = false;
    public string $newOwnerName = '';
    public string $newOwnerEmail = '';
    public string $newOwnerPhone = '';

    public function startOwnerTransfer(int $membershipId, string $action, ?string $demoteRole = null, bool $demoteBookable = false): void
    {
        $membership = $this->membership($membershipId);

        abort_unless(in_array($action, ['demote', 'deactivate', 'delete'], true), 404);
        abort_unless($membership->salon_role === SalonRole::Owner, 404);
        abort_unless((new SalonStaffRoles)->canManage(Auth::user(), $this->salon, SalonRole::Owner), 403);

        $this->transferMembershipId = $membership->id;
        $this->transferAction = $action;
        $this->transferDemoteRole = $demoteRole ?? '';
        $this->transferDemoteBookable = $demoteBookable;
        $this->reset(['transferChoice', 'newOwnerName', 'newOwnerEmail', 'newOwnerPhone']);
        $this->resetErrorBag();
        $this->showEdit = false;
        $this->showTransfer = true;
    }

    public function confirmTransfer(SetSalonOwner $transfer, UpdateStaffMembership $update, SetMembershipActive $setActive, DeleteStaffUser $delete): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Ownership changes are disabled in the demo.'))) {
            return;
        }

        $membership = $this->membership((int) $this->transferMembershipId);
        abort_unless($membership->salon_role === SalonRole::Owner, 404);

        $this->validate(
            ['transferChoice' => ['required', Rule::in(['new', 'me'])]],
            ['transferChoice.required' => __('Choose who takes over first.'), 'transferChoice.in' => __('Choose who takes over first.')],
        );

        if ($this->transferChoice === 'me' && $membership->user_id === Auth::id()) {
            $this->addError('transferChoice', __('You are the owner being replaced — add a new owner instead.'));

            return;
        }

        if ($this->transferChoice === 'new') {
            // The add-user field set and rules, verbatim (invite()).
            $this->validate([
                'newOwnerName' => ['required', 'string', 'max:255'],
                'newOwnerEmail' => ['required', 'string', 'email', 'max:255'],
                'newOwnerPhone' => ['nullable', 'string', 'max:32'],
            ]);

            if (strcasecmp($this->newOwnerEmail, (string) $membership->user?->email) === 0) {
                $this->addError('newOwnerEmail', __('That is the owner being replaced — name a different person.'));

                return;
            }
        }

        try {
            $result = $this->transferChoice === 'new'
                ? $transfer->handle(Auth::user(), $this->salon, [
                    'name' => $this->newOwnerName,
                    'email' => $this->newOwnerEmail,
                    'phone' => $this->newOwnerPhone ?: null,
                ])
                : $transfer->handle(Auth::user(), $this->salon, ['make_actor_owner' => true]);

            // The transfer demoted the outgoing owner (stylist if bookable,
            // manager otherwise); now finish what the actor started.
            $membership->refresh();

            if ($this->transferAction === 'deactivate') {
                $setActive->handle(Auth::user(), $this->salon, $membership, false);
            } elseif ($this->transferAction === 'delete') {
                $delete->handle(Auth::user(), $this->salon, $membership);
            } elseif ($this->transferDemoteRole !== '') {
                // For a demote TO MANAGER the edit modal's takes-bookings box
                // is authoritative over the ex-owner's bookability; other
                // demote roles keep the transfer's own result untouched.
                $toManager = $this->transferDemoteRole === SalonRole::Manager->value;
                $wantsType = $toManager && $this->transferDemoteBookable ? StaffType::Stylist : null;

                if ($membership->salon_role->value !== $this->transferDemoteRole
                    || ($toManager && $membership->staff_type !== $wantsType)) {
                    $data = ['salon_role' => $this->transferDemoteRole];
                    if ($toManager) {
                        $data['staff_type'] = $wantsType?->value;
                    }
                    $update->handle(Auth::user(), $this->salon, $membership, $data);
                }
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Same visibility rule as saveEdit: SetSalonOwner and the
            // completion actions speak in domain keys (owner, salon_role,
            // name/email/phone) — re-key so nothing fails silently.
            throw \Illuminate\Validation\ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => [
                    match ($key) {
                        'name' => 'newOwnerName',
                        'email' => 'newOwnerEmail',
                        'phone' => 'newOwnerPhone',
                        default => 'transferChoice',
                    } => $messages,
                ])->all(),
            );
        }

        $this->showTransfer = false;
        $this->showEdit = false;
        $this->editingId = null;
        $this->reset(['transferMembershipId', 'transferAction', 'transferChoice', 'transferDemoteRole', 'transferDemoteBookable', 'newOwnerName', 'newOwnerEmail', 'newOwnerPhone']);
        unset($this->memberships);

        Flux::toast(variant: 'success', text: __('Ownership transferred.'));

        // A freshly provisioned owner gets their temporary password shown
        // once — the same panel every add-user flow uses.
        if ($result->temporaryPassword !== null) {
            $this->temporaryPassword = $result->temporaryPassword;
            $this->tempForName = $result->user->name;
            $this->showTempPassword = true;
        }
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
        @if ($salon->is_demo)
            <p class="text-[13px] text-faint">{{ __('Browse the team setup freely — staff changes are disabled in the demo.') }}</p>
        @endif
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
                                    @if ($m->salon_role === \App\Enums\SalonRole::Stylist && $m->arrangement === \App\Enums\StylistArrangement::BoothRental)
                                        <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('Booth renter') }}</span>
                                    @endif
                                    @if ($m->salon_role->isManager() && $m->staff_type === \App\Enums\StaffType::Stylist)
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
                                    @include('pages.salon.users.partials.row-actions', ['m' => $m, 'canManage' => $this->canManageMembership($m), 'canEditDetails' => $this->canEditMemberDetails()])
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
                            @include('pages.salon.users.partials.row-actions', ['m' => $m, 'canManage' => $this->canManageMembership($m), 'canEditDetails' => $this->canEditMemberDetails()])
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 ps-11 text-[13px] text-secondary">
                        <span>{{ $m->salon_role->label() }}</span>
                        @if ($m->salon_role->isManager() && $m->staff_type === \App\Enums\StaffType::Stylist)
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
            <flux:select wire:model.live="role" :label="__('Role')"
                :description="__('Stylists always take bookings and see only their own schedule; managers run the salon and can optionally take bookings too.')">
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($role === 'salon_manager')
                {{-- Bookability at invite time — the same staff_type flag the
                     edit-flow switch writes. Stylists never see this box:
                     they are inherently bookable. --}}
                <flux:checkbox wire:model="takesBookings" :label="__('Takes bookings')"
                    :description="__('Give this manager a schedule and calendar column — bookable like a stylist, with full manager permissions.')" />
            @endif
            @if ($this->arrangementSelectable && $role === 'stylist')
                <flux:select wire:model="arrangement" :label="__('Arrangement')"
                    :description="__('Employees see the shared calendar; booth renters run their own book, clients, and revenue.')">
                    <flux:select.option value="employee">{{ __('Employee') }}</flux:select.option>
                    <flux:select.option value="booth_rental">{{ __('Booth renter') }}</flux:select.option>
                </flux:select>
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showAdd', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" loading="invite">{{ __('Send invite') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showEdit" class="max-w-md" :heading="__('Edit user')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5" novalidate>
            <flux:select wire:model.live="editRole" :label="__('Role')"
                :description="$editingOwner ? __('Choosing another role transfers ownership — you will pick the new owner first.') : null">
                @if ($editingOwner)
                    <flux:select.option value="{{ \App\Enums\SalonRole::Owner->value }}">{{ __('Owner (current)') }}</flux:select.option>
                @endif
                @foreach ($this->assignableRoles as $r)
                    <flux:select.option value="{{ $r->value }}">{{ $r->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($editRole === 'salon_manager')
                {{-- The takes-bookings flag, editable in place — reflects the
                     member's current bookable state (a stylist switched to
                     Manager defaults to staying bookable). Same staff_type
                     mechanism as the self-row switch and the Add modal. --}}
                <flux:checkbox wire:model="editTakesBookings" :label="__('Takes bookings')"
                    :description="__('Give this manager a schedule and calendar column — bookable like a stylist, with full manager permissions.')" />
            @endif
            @if ($this->arrangementSelectable && $editRole === 'stylist')
                <flux:select wire:model="editArrangement" :label="__('Arrangement')">
                    <flux:select.option value="employee">{{ __('Employee') }}</flux:select.option>
                    <flux:select.option value="booth_rental">{{ __('Booth renter') }}</flux:select.option>
                </flux:select>
            @endif
            @if ($editRole === 'stylist')
                <flux:textarea wire:model="editBio" :label="__('Bio')" rows="3" :placeholder="__('A short bio for this stylist.')" />
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal wire:model="showOwnerEdit" class="max-w-md" :heading="__('Edit member details')">
        <form wire:submit="saveOwnerDetails" class="flex flex-col gap-5" novalidate>
            @if ($ownerEditShared)
                {{-- Shared-account notice: informational for name/phone; the
                     EMAIL block itself is enforced server-side in
                     UpdateMemberDetails, never here. --}}
                <div class="rounded-[10px] border border-divider bg-muted/40 px-3.5 py-2.5 text-[13px] leading-relaxed text-secondary">
                    {{ __('This person also works at a salon under another agency; changes to their name and phone apply to their account everywhere. Their login email can only be changed by the account holder.') }}
                </div>
            @endif
            <flux:input wire:model="ownerName" :label="__('Name')" required />
            <flux:input wire:model="ownerEmail" type="email" :label="__('Email')" required :disabled="$ownerEditShared" />
            <flux:input wire:model="ownerPhone" type="tel" :label="__('Phone')" />
            @if ($ownerEditIsManager)
                {{-- Managers only: the same staff_type flag as the Edit-user
                     modal and the self-row switch, editable where members
                     are actually edited. Owners keep their own controls;
                     stylists are inherently bookable. --}}
                <flux:checkbox wire:model="ownerEditTakesBookings" :label="__('Takes bookings')"
                    :description="__('Bookable like a stylist — a schedule and calendar column, with full manager permissions.')" />
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showOwnerEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Owner-transfer safeguard: the salon must end with an owner, so the
         stripping action (demote / deactivate / delete) waits behind this
         choice. Two explicit paths: provision a brand-new owner (the
         standard add-user form + invite mails) or the acting agency
         operator's take-over — never a silent side effect. --}}
    <x-ui.modal wire:model="showTransfer" class="max-w-md" :heading="__('This salon needs an owner — choose one')">
        <form wire:submit="confirmTransfer" class="flex flex-col gap-5" novalidate>
            <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('A salon can never be left without an owner. Pick who takes over; then this change goes through.') }}</p>
            <flux:radio.group wire:model.live="transferChoice" variant="segmented">
                <flux:radio value="new" label="{{ __('Add a new owner') }}" />
                <flux:radio value="me" label="{{ __('Make me the owner') }}" />
            </flux:radio.group>
            @if ($transferChoice === 'new')
                {{-- The add-user field set: a new account is provisioned with
                     the normal invite/welcome mails and a one-time temporary
                     password; an existing account for this email is linked
                     as owner instead, exactly like any invite. --}}
                <flux:input wire:model="newOwnerName" :label="__('Name')" required autofocus />
                <flux:input wire:model="newOwnerEmail" type="email" :label="__('Email')" required />
                <flux:input wire:model="newOwnerPhone" type="tel" :label="__('Phone')" autocomplete="tel" />
            @elseif ($transferChoice === 'me')
                <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('You become this salon\'s owner: your account gets (or keeps) a membership here, promoted to Owner. No invite is sent.') }}</p>
            @endif
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showTransfer', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" loading="confirmTransfer">{{ __('Transfer ownership') }}</x-ui.button>
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
