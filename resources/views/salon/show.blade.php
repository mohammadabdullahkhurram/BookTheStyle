<x-layouts::app :title="$salon->name">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-6">
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-secondary transition hover:text-accent">
                {{ __('← All salons') }}
            </a>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-col gap-1">
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ __('Salon') }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ $salon->name }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Details') }}</flux:heading>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('Timezone') }}</dt>
                        <dd class="text-ink">{{ $salon->timezone }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('GHL connected') }}</dt>
                        <dd class="text-ink">{{ $salon->ghl_location_id ? __('Yes') : __('Not yet') }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Booking policy') }}</flux:heading>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('Walk-ins') }}</dt>
                        <dd class="text-ink">{{ $salon->allow_walkins ? __('Allowed') : __('Off') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('Same-day booking') }}</dt>
                        <dd class="text-ink">{{ $salon->allow_same_day ? __('Allowed') : __('Off') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('Max advance') }}</dt>
                        <dd class="text-ink">{{ $salon->max_advance_days }} {{ __('days') }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-secondary">{{ __('Min notice') }}</dt>
                        <dd class="text-ink">{{ $salon->min_notice_minutes }} {{ __('min') }}</dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="flex items-start gap-3 rounded-lg border border-border bg-muted/50 p-4 text-sm text-secondary">
            <flux:icon.information-circle variant="micro" class="mt-0.5 shrink-0 text-accent" />
            <p>{{ __('Services, stylists, availability, and the master calendar arrive in later phases.') }}</p>
        </div>
    </div>
</x-layouts::app>
