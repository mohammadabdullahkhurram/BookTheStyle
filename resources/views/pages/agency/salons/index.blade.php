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
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Salons')">
            <x-slot:subtitle>{{ __('Create, edit, and deactivate sub-accounts.') }}</x-slot:subtitle>
            <x-slot:actions>
                <x-ui.button :href="route('agency.salons.create')" wire:navigate><flux:icon.plus variant="micro" class="shrink-0" />{{ __('New salon') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th class="px-6 py-3.5 font-semibold">{{ __('Salon') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Timezone') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Staff') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->salons as $salon)
                        <tr @class(['opacity-65' => ! $salon->active])>
                            <td class="px-6 py-4">
                                <div class="text-[15px] font-medium text-ink">{{ $salon->name }}</div>
                                <div class="text-[12.5px] text-faint">{{ $salon->slug }}.{{ config('app.domain') }}</div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $salon->timezone }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $salon->memberships_count }}</td>
                            <td class="px-6 py-4">
                                @if ($salon->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-4">
                                    <a href="{{ route('agency.salons.edit', $salon) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                                    <button type="button"
                                            @if ($salon->active) wire:confirm="{{ __('Deactivate :salon? All its staff lose access until it is reactivated. No data is deleted.', ['salon' => $salon->name]) }}" @endif
                                            wire:click="toggleActive({{ $salon->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">
                                        {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No salons yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-ui.card>
    </div>
</div>
