<?php

use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Users — the agency-wide directory: every person across the account, both
 * AGENCY operators and the staff of every salon (owners, admins, stylists,
 * front desk), with their role(s), salon(s), and status. Search + filters
 * (role, salon, agency-vs-salon). Two eager-loaded queries total — never one
 * per user or per salon. Agency-scoped: only this agency's operators and
 * this agency's salons' staff, ever.
 */
new #[Title('Users')] class extends Component {
    public string $search = '';

    /** '' | agency | salon */
    public string $scope = '';

    /** '' | an AgencyRole value | a SalonRole value | a StaffType value */
    public string $role = '';

    /** '' | a salon id (narrows the salon-staff section) */
    public string $salonId = '';

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

    /** @return list<int> */
    private function salonIds(): array
    {
        return $this->agency()->salons()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function applySearch($query): void
    {
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
    }

    #[Computed]
    public function agencyUsers()
    {
        if ($this->scope === 'salon' || in_array($this->role, [...array_column(SalonRole::cases(), 'value'), ...array_column(StaffType::cases(), 'value')], true)) {
            return collect();
        }

        $query = $this->agency()->users()
            ->whereNotNull('agency_role')
            ->with('assignedSalons:id,name')
            ->orderBy('name');

        if ($this->role !== '') {
            $query->where('agency_role', $this->role);
        }
        $this->applySearch($query);

        return $query->get();
    }

    #[Computed]
    public function salonUsers()
    {
        if ($this->scope === 'agency' || in_array($this->role, array_column(AgencyRole::cases(), 'value'), true)) {
            return collect();
        }

        $salonIds = $this->salonIds();

        $membershipFilter = function ($q) use ($salonIds): void {
            $q->whereIn('salon_id', $salonIds);
            if ($this->salonId !== '') {
                $q->where('salon_id', (int) $this->salonId);
            }
            match ($this->role) {
                SalonRole::Owner->value => $q->where('salon_role', SalonRole::Owner->value),
                SalonRole::Admin->value => $q->where('salon_role', SalonRole::Admin->value),
                StaffType::Stylist->value => $q->where('staff_type', StaffType::Stylist->value),
                StaffType::FrontDesk->value => $q->where('staff_type', StaffType::FrontDesk->value),
                StaffType::Manager->value => $q->where('staff_type', StaffType::Manager->value),
                default => null,
            };
        };

        $query = User::query()
            ->whereHas('salonMemberships', $membershipFilter)
            ->with(['salonMemberships' => fn ($q) => $q->whereIn('salon_id', $salonIds)->with('salon:id,name')])
            ->orderBy('name');

        $this->applySearch($query);

        return $query->get();
    }

    #[Computed]
    public function salonOptions()
    {
        return $this->agency()->salons()->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Users')">
            <x-slot:subtitle>{{ __('Everyone across the account — agency operators and every salon\'s staff.') }}</x-slot:subtitle>
            <x-slot:actions>
                <x-ui.button :href="route('agency.users.create')" wire:navigate><flux:icon.plus variant="micro" class="shrink-0" />{{ __('New user') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Search + filters. --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input wire:model.live.debounce.300ms="search" :label="__('Search')" :placeholder="__('Name or email')" clearable />
            <flux:select wire:model.live="scope" :label="__('Scope')">
                <flux:select.option value="">{{ __('Agency and salons') }}</flux:select.option>
                <flux:select.option value="agency">{{ __('Agency only') }}</flux:select.option>
                <flux:select.option value="salon">{{ __('Salon staff only') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="role" :label="__('Role')">
                <flux:select.option value="">{{ __('Any role') }}</flux:select.option>
                @foreach (\App\Enums\AgencyRole::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ __('Agency: :role', ['role' => $case->label()]) }}</flux:select.option>
                @endforeach
                <flux:select.option value="{{ \App\Enums\SalonRole::Owner->value }}">{{ __('Salon: Owner') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\SalonRole::Admin->value }}">{{ __('Salon: Admin') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\StaffType::Manager->value }}">{{ __('Salon: Manager') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\StaffType::Stylist->value }}">{{ __('Salon: Stylist') }}</flux:select.option>
                <flux:select.option value="{{ \App\Enums\StaffType::FrontDesk->value }}">{{ __('Salon: Front desk') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="salonId" :label="__('Salon')">
                <flux:select.option value="">{{ __('All salons') }}</flux:select.option>
                @foreach ($this->salonOptions as $option)
                    <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Agency operators. --}}
        @if ($scope !== 'salon')
            <section class="flex flex-col gap-3">
                <h2 class="bts-card-title">{{ __('Agency') }}</h2>
                <x-ui.card padding="p-0" class="overflow-hidden">
                    <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bts-overline border-b border-divider">
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Role') }}</th>
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Salon scope') }}</th>
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                                <th scope="col" class="px-6 py-3.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-row">
                            @forelse ($this->agencyUsers as $user)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$user->name" :seed="$user->id" size="sm" />
                                            <div>
                                                <div class="text-[15px] font-medium text-ink">{{ $user->name }}</div>
                                                <div class="text-[12.5px] text-faint">{{ $user->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-[15px] text-secondary">{{ $user->agency_role->label() }}</td>
                                    <td class="px-6 py-4 text-[15px] text-secondary">
                                        @if ($user->agency_role === \App\Enums\AgencyRole::User)
                                            {{ $user->assignedSalons->pluck('name')->join(', ') ?: __('No salons assigned') }}
                                        @else
                                            {{ __('All salons') }}
                                        @endif
                                    </td>
                                    <td class="px-6 py-4">
                                        @if ($user->must_change_password)
                                            <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Invited') }}</span>
                                        @else
                                            <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <a href="{{ route('agency.users.edit', $user) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No agency users match.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </x-ui.card>
            </section>
        @endif

        {{-- Salon staff across every salon of the agency. --}}
        @if ($scope !== 'agency')
            <section class="flex flex-col gap-3">
                <h2 class="bts-card-title">{{ __('Salon staff') }}</h2>
                <x-ui.card padding="p-0" class="overflow-hidden">
                    <div class="overflow-x-auto" tabindex="0">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bts-overline border-b border-divider">
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Role(s) and salon(s)') }}</th>
                                <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-row">
                            @forelse ($this->salonUsers as $user)
                                <tr>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$user->name" :seed="$user->id" size="sm" />
                                            <div>
                                                <div class="text-[15px] font-medium text-ink">{{ $user->name }}</div>
                                                <div class="text-[12.5px] text-faint">{{ $user->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-[14px] text-secondary">
                                        @foreach ($user->salonMemberships as $membership)
                                            <div>
                                                <span class="font-medium text-ink">{{ $membership->salon->name }}</span>
                                                · {{ $membership->staff_type?->label() ?? $membership->salon_role->label() }}
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="px-6 py-4">
                                        @if ($user->must_change_password)
                                            <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Invited') }}</span>
                                        @else
                                            <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No salon staff match.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </x-ui.card>
            </section>
        @endif
    </div>
</div>
