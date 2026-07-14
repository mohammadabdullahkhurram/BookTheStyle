<?php

use App\Models\Agency;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
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

<div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
    <x-ui.page-header :overline="__('Agency')" :title="$this->agency()->name">
        <x-slot:subtitle>{{ __('Manage salons and agency users across the account.') }}</x-slot:subtitle>
    </x-ui.page-header>

    {{-- A true dashboard: the numbers, each linking into its own tab. The
         salon LISTING lives on the Salons tab (gallery/list) — not
         duplicated here. --}}
    <div class="grid gap-4 sm:grid-cols-3">
        <a href="{{ route('agency.salons.index') }}" wire:navigate
           class="group rounded-[16px] border border-border bg-card p-[18px] shadow-card transition hover:border-accent">
            <div class="font-display text-[34px] font-bold leading-none text-ink">{{ $this->salons->count() }}</div>
            <div class="mt-2 text-[16px] font-semibold text-ink transition group-hover:text-accent">{{ __('Salons') }}</div>
            <div class="text-[14px] text-secondary">{{ __('Create and manage sub-accounts') }}</div>
        </a>
        <a href="{{ route('agency.reports') }}" wire:navigate
           class="group rounded-[16px] border border-border bg-card p-[18px] shadow-card transition hover:border-accent">
            <div class="font-display text-[34px] font-bold leading-none text-ink">{{ $this->salons->where('active', true)->count() }}</div>
            <div class="mt-2 text-[16px] font-semibold text-ink transition group-hover:text-accent">{{ __('Reporting') }}</div>
            <div class="text-[14px] text-secondary">{{ __('Active salons — bookings, outcomes, sources') }}</div>
        </a>
        <a href="{{ route('agency.users.index') }}" wire:navigate
           class="group rounded-[16px] border border-border bg-card p-[18px] shadow-card transition hover:border-accent">
            <div class="font-display text-[34px] font-bold leading-none text-ink">{{ $this->agencyUserCount }}</div>
            <div class="mt-2 text-[16px] font-semibold text-ink transition group-hover:text-accent">{{ __('Agency users') }}</div>
            <div class="text-[14px] text-secondary">{{ __('Operators and their salon scope') }}</div>
        </a>
    </div>

    @if ($this->salons->isEmpty())
        <x-ui.card class="py-12 text-center text-[15px] text-faint">
            {{ __('No salons yet.') }}
            <a href="{{ route('agency.salons.create') }}" wire:navigate class="font-semibold text-accent transition hover:text-accent-hover">{{ __('Create the first one.') }}</a>
        </x-ui.card>
    @endif
</div>
