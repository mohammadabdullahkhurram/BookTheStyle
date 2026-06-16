<?php

use App\Actions\Salons\SetSalonActive;
use App\Models\Agency;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Salons')] class extends Component {
    public function mount(): void
    {
        $this->authorize('manageSalons', $this->agency());
    }

    public function agency(): Agency
    {
        $agency = Auth::user()->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    #[Computed]
    public function salons()
    {
        return $this->agency()->salons()->withCount('memberships')->orderBy('name')->get();
    }

    public function toggleActive(int $salonId, SetSalonActive $action): void
    {
        $this->authorize('manageSalons', $this->agency());

        // Scope the lookup to this agency: an out-of-agency id 404s (no IDOR).
        $salon = $this->agency()->salons()->whereKey($salonId)->firstOrFail();

        $action->handle($salon, ! $salon->active);
        unset($this->salons);

        Flux::toast(variant: 'success', text: $salon->active ? __('Salon reactivated.') : __('Salon deactivated.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl" class="font-serif">{{ __('Salons') }}</flux:heading>
                <flux:text class="text-secondary">{{ __('Create, edit, and deactivate sub-accounts.') }}</flux:text>
            </div>
            <flux:button :href="route('agency.salons.create')" wire:navigate variant="primary" icon="plus">
                {{ __('New salon') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Salon') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Timezone') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Staff') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->salons as $salon)
                        <tr>
                            <td class="px-4 py-3 font-medium text-ink">{{ $salon->name }}</td>
                            <td class="px-4 py-3 text-secondary">{{ $salon->timezone }}</td>
                            <td class="px-4 py-3 text-secondary">{{ $salon->memberships_count }}</td>
                            <td class="px-4 py-3">
                                @if ($salon->active)
                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <flux:link :href="route('agency.salons.edit', $salon)" wire:navigate class="text-sm">{{ __('Edit') }}</flux:link>
                                    <flux:button size="xs" variant="ghost" wire:click="toggleActive({{ $salon->id }})">
                                        {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-secondary">{{ __('No salons yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
