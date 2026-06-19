<?php

use App\Models\Agency;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Agency console')] class extends Component {
    public function mount(): void
    {
        $this->authorize('accessConsole', $this->agency());
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

    #[Computed]
    public function agencyUserCount(): int
    {
        return $this->agency()->users()->whereNotNull('agency_role')->count();
    }
}; ?>

<div class="mx-auto flex w-full max-w-5xl flex-col gap-7 px-8 py-7">
    <x-ui.page-header :overline="__('Agency')" :title="$this->agency()->name">
        <x-slot:subtitle>{{ __('Manage salons and agency users across the account.') }}</x-slot:subtitle>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-2">
        <a href="{{ route('agency.salons.index') }}" wire:navigate
           class="group rounded-[16px] border border-border bg-card p-[18px] shadow-card transition hover:border-accent">
            <div class="font-display text-[34px] font-bold leading-none text-ink">{{ $this->salons->count() }}</div>
            <div class="mt-2 text-[16px] font-semibold text-ink transition group-hover:text-accent">{{ __('Salons') }}</div>
            <div class="text-[14px] text-secondary">{{ __('Create and manage sub-accounts') }}</div>
        </a>
        <a href="{{ route('agency.users.index') }}" wire:navigate
           class="group rounded-[16px] border border-border bg-card p-[18px] shadow-card transition hover:border-accent">
            <div class="font-display text-[34px] font-bold leading-none text-ink">{{ $this->agencyUserCount }}</div>
            <div class="mt-2 text-[16px] font-semibold text-ink transition group-hover:text-accent">{{ __('Agency users') }}</div>
            <div class="text-[14px] text-secondary">{{ __('Operators and their salon scope') }}</div>
        </a>
    </div>

    <section class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <h2 class="bts-card-title">{{ __('Salons') }}</h2>
            <x-ui.button :href="route('agency.salons.create')" wire:navigate size="sm"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('New salon') }}</x-ui.button>
        </div>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th class="px-6 py-3.5 font-semibold">{{ __('Salon') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Staff') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->salons as $salon)
                        <tr>
                            <td class="px-6 py-4">
                                <a href="{{ route('salon.show', $salon) }}" wire:navigate class="text-[15px] font-medium text-ink transition hover:text-accent">{{ $salon->name }}</a>
                                <div class="text-[12.5px] text-faint">{{ $salon->slug }}.{{ config('app.domain') }} · {{ $salon->timezone }}</div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $salon->memberships_count }}</td>
                            <td class="px-6 py-4">
                                @if ($salon->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('agency.salons.edit', $salon) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No salons yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </section>
</div>
