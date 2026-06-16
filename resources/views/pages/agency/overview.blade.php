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

<div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-6">
        <header class="flex flex-col gap-1">
            <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ __('Agency') }}</flux:text>
            <flux:heading size="xl" class="font-serif">{{ $this->agency()->name }}</flux:heading>
            <flux:text class="text-secondary">{{ __('Manage salons and agency users across the account.') }}</flux:text>
        </header>

        <div class="grid gap-4 sm:grid-cols-2">
            <a href="{{ route('agency.salons.index') }}" wire:navigate
               class="group rounded-xl border border-border bg-card p-5 shadow-sm transition hover:border-accent hover:shadow-md">
                <flux:text class="text-3xl font-serif text-ink">{{ $this->salons->count() }}</flux:text>
                <flux:heading class="mt-1 transition group-hover:text-accent">{{ __('Salons') }}</flux:heading>
                <flux:text class="text-sm text-secondary">{{ __('Create and manage sub-accounts') }}</flux:text>
            </a>
            <a href="{{ route('agency.users.index') }}" wire:navigate
               class="group rounded-xl border border-border bg-card p-5 shadow-sm transition hover:border-accent hover:shadow-md">
                <flux:text class="text-3xl font-serif text-ink">{{ $this->agencyUserCount }}</flux:text>
                <flux:heading class="mt-1 transition group-hover:text-accent">{{ __('Agency users') }}</flux:heading>
                <flux:text class="text-sm text-secondary">{{ __('Operators and their salon scope') }}</flux:text>
            </a>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between">
                <flux:heading size="lg" class="font-serif">{{ __('Salons') }}</flux:heading>
                <flux:button :href="route('agency.salons.create')" wire:navigate variant="primary" size="sm" icon="plus">
                    {{ __('New salon') }}
                </flux:button>
            </div>

            <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ __('Salon') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Staff') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->salons as $salon)
                            <tr>
                                <td class="px-4 py-3">
                                    <a href="{{ route('salon.show', $salon) }}" wire:navigate class="font-medium text-ink hover:text-accent">{{ $salon->name }}</a>
                                    <div class="text-xs text-secondary">{{ $salon->timezone }}</div>
                                </td>
                                <td class="px-4 py-3 text-secondary">{{ $salon->memberships_count }}</td>
                                <td class="px-4 py-3">
                                    @if ($salon->active)
                                        <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <flux:link :href="route('agency.salons.edit', $salon)" wire:navigate class="text-sm">{{ __('Edit') }}</flux:link>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-secondary">{{ __('No salons yet.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
