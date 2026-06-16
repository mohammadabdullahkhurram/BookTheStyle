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
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-serif">{{ __('Agency users') }}</flux:heading>
                <flux:text class="text-secondary">{{ __('Operators of the agency and their salon scope.') }}</flux:text>
            </div>
            <flux:button :href="route('agency.users.create')" wire:navigate variant="primary" icon="plus">
                {{ __('New user') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Role') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Salon scope') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->users as $user)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="font-medium text-ink">{{ $user->name }}</div>
                                <div class="text-xs text-secondary">{{ $user->email }}</div>
                            </td>
                            <td class="px-4 py-3 text-secondary">{{ $user->agency_role->label() }}</td>
                            <td class="px-4 py-3 text-secondary">
                                @if ($user->agency_role === \App\Enums\AgencyRole::User)
                                    {{ $user->assignedSalons->pluck('name')->join(', ') ?: __('No salons assigned') }}
                                @else
                                    {{ __('All salons') }}
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <flux:link :href="route('agency.users.edit', $user)" wire:navigate class="text-sm">{{ __('Edit') }}</flux:link>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-secondary">{{ __('No agency users yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
