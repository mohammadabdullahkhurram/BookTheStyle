<?php

use App\Models\Agency;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agency users')] class extends Component {
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

    #[Computed]
    public function users()
    {
        return $this->agency()->users()
            ->whereNotNull('agency_role')
            ->with('assignedSalons:id,name')
            ->orderBy('name')
            ->get();
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Agency users')">
            <x-slot:subtitle>{{ __('Operators of the agency and their salon scope.') }}</x-slot:subtitle>
            <x-slot:actions>
                <x-ui.button :href="route('agency.users.create')" wire:navigate><flux:icon.plus variant="micro" class="shrink-0" />{{ __('New user') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Role') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Salon scope') }}</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->users as $user)
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
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('agency.users.edit', $user) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No agency users yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-ui.card>
    </div>
</div>
