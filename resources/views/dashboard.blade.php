<x-layouts::app :title="__('Dashboard')">
    @php
        $user = auth()->user();
        // Salons the user can open: their active memberships, plus every salon
        // in their agency when they are a privileged agency user.
        $salons = $user->salons()->wherePivot('active', true)->get();
        if ($user->agency_role?->isPrivileged() && $user->agency_id) {
            $salons = $salons->merge($user->agency->salons()->get())->unique('id')->values();
        }
    @endphp

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-6">
        <header class="flex flex-col gap-1">
            <flux:heading size="xl" class="font-serif">{{ __('Welcome back, :name', ['name' => $user->name]) }}</flux:heading>
            <flux:text class="text-secondary">{{ __('Choose a salon to open its calendar and bookings.') }}</flux:text>
        </header>

        @if ($salons->isEmpty())
            <div class="rounded-xl border border-border bg-card p-8 text-center shadow-sm">
                <flux:heading size="lg" class="font-serif">{{ __('No salons yet') }}</flux:heading>
                <flux:text class="mt-2 text-secondary">
                    {{ __('You are not a member of any salon. An administrator will add you to one.') }}
                </flux:text>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($salons as $salon)
                    <a
                        href="{{ route('salon.show', $salon) }}"
                        wire:navigate
                        class="group flex flex-col gap-3 rounded-xl border border-border bg-card p-5 shadow-sm transition hover:border-accent hover:shadow-md"
                    >
                        <span class="flex size-10 items-center justify-center rounded-lg bg-accent-soft text-accent">
                            <flux:icon.scissors variant="micro" />
                        </span>
                        <div>
                            <flux:heading class="font-serif transition group-hover:text-accent">{{ $salon->name }}</flux:heading>
                            <flux:text class="text-xs text-secondary">{{ $salon->timezone }}</flux:text>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
