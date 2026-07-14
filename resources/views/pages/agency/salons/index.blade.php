<?php

use App\Actions\Salons\SetSalonActive;
use App\Models\Agency;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Salons')] class extends Component {
    /** gallery (cards, the default) | list (table) — two views of one page. */
    public string $view = 'gallery';

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

        {{-- One Salons page, two presentations: Gallery (default) and List. --}}
        <div>
            <flux:radio.group wire:model.live="view" variant="segmented" :label="__('View')" label:class="sr-only">
                <flux:radio value="gallery" :label="__('Gallery')" />
                <flux:radio value="list" :label="__('List')" />
            </flux:radio.group>
        </div>

        @if ($view === 'gallery')
        {{-- Gallery: one card per salon. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-view="gallery">
            @forelse ($this->salons as $salon)
                <div @class(['flex flex-col gap-3 rounded-[16px] border border-border bg-card p-5 shadow-card', 'opacity-70' => ! $salon->active])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ route('salon.show', $salon) }}" wire:navigate class="block truncate text-[16px] font-semibold text-ink transition hover:text-accent">{{ $salon->name }}</a>
                            <div class="truncate text-[12.5px] text-faint">{{ $salon->slug }}.{{ config('app.domain') }}</div>
                        </div>
                        @if ($salon->active)
                            <span class="bts-pill shrink-0" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                        @else
                            <span class="bts-pill shrink-0" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
                        @endif
                    </div>
                    <div class="text-[13.5px] text-secondary">
                        {{ $salon->timezone }} · {{ trans_choice(':count staff member|:count staff members', $salon->memberships_count, ['count' => $salon->memberships_count]) }}
                    </div>
                    <div class="mt-auto flex items-center gap-4 border-t border-row pt-3">
                        <a href="{{ route('agency.salons.edit', $salon) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                        <button type="button"
                                @if ($salon->active) wire:confirm="{{ __('Deactivate :salon? All its staff lose access until it is reactivated. No data is deleted.', ['salon' => $salon->name]) }}" @endif
                                wire:click="toggleActive({{ $salon->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">
                            {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
                        </button>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-[16px] border border-border bg-card px-6 py-12 text-center text-[15px] text-faint">{{ __('No salons yet.') }}</div>
            @endforelse
        </div>
        @else
        {{-- List: the table. --}}
        <x-ui.card padding="p-0" class="overflow-hidden" data-view="list">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Salon') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Timezone') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Staff') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th scope="col" class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->salons as $salon)
                        <tr @class(['bg-muted/40' => ! $salon->active])>
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
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
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
        @endif
    </div>
</div>
