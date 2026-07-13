<x-layouts::app :title="__('Dashboard')">
    @php
        $user = auth()->user();

        // Salon ids the user can open: active memberships + agency reach (every
        // salon for an owner/admin, or assigned salons for an agency_user).
        $ids = $user->salons()->wherePivot('active', true)->pluck('salons.id');
        if ($user->isAgencyOperator() && $user->agency_id) {
            $ids = $ids->merge($user->agency->salons()->pluck('salons.id'));
        } elseif ($user->agency_role === \App\Enums\AgencyRole::User) {
            $ids = $ids->merge($user->assignedSalons()->pluck('salons.id'));
        }
        $ids = $ids->unique()->values();

        // One query for the cards: all salon columns (live from settings) + cheap
        // aggregate counts. No per-card queries → no N+1.
        $salons = \App\Models\Salon::query()
            ->whereIn('id', $ids)
            ->withCount([
                'stylistUsers as active_stylists_count',
                'services as active_services_count' => fn ($q) => $q->where('active', true),
            ])
            ->orderBy('name')
            ->get();

        // The user's salon role per salon (one query), keyed by salon id.
        $memberships = $user->salonMemberships()->where('active', true)->get()->keyBy('salon_id');

        $roleBadge = function (\App\Models\Salon $salon) use ($memberships, $user): ?string {
            $m = $memberships->get($salon->id);

            return match (true) {
                $m && $m->salon_role === \App\Enums\SalonRole::Owner => __('Owner'),
                $m && $m->salon_role === \App\Enums\SalonRole::Admin => __('Admin'),
                $m && $m->staff_type === \App\Enums\StaffType::Stylist => __('Stylist'),
                $m && $m->staff_type === \App\Enums\StaffType::FrontDesk => __('Front desk'),
                $m !== null => __('Staff'),
                // Agency operator reaching a salon without a salon membership.
                default => $user->agency_role?->label(),
            };
        };
    @endphp

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-6">
        <header class="flex flex-col gap-1">
            <flux:heading size="xl" class="font-serif">{{ __('Welcome back, :name', ['name' => $user->name]) }}</flux:heading>
            <flux:text class="text-secondary">{{ __('Choose a salon to open its calendar and bookings.') }}</flux:text>
        </header>

        @if ($user->isAgencyOperator())
            <a href="{{ route('agency.overview') }}" wire:navigate
               class="flex items-center justify-between rounded-xl border border-accent/30 bg-accent-soft p-5 transition hover:border-accent">
                <div>
                    <flux:heading class="font-serif text-accent">{{ __('Agency dashboard') }}</flux:heading>
                    <flux:text class="text-sm text-secondary">{{ __('Salons, reporting, and users across the agency.') }}</flux:text>
                </div>
                <flux:icon.arrow-right class="text-accent" />
            </a>
        @endif

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
                    @php
                        $accent = \App\Support\AccentPalette::resolve($salon->accentColor()) ?? \App\Support\AccentPalette::PRESETS['violet'];
                        $badge = $roleBadge($salon);
                        $locality = collect([$salon->city, $salon->region ?: $salon->country])
                            ->filter(fn ($v) => filled($v))->implode(', ');
                    @endphp

                    {{-- A whole-card link (the primary affordance). Reuses the card
                         tokens (radius 18 / border / shadow) on an <a>. --}}
                    <a
                        href="{{ route('salon.show', $salon) }}"
                        wire:navigate
                        style="--sa: {{ $accent['accent'] }};"
                        class="group flex flex-col overflow-hidden rounded-[18px] border border-border bg-card shadow-card transition hover:border-[var(--sa)] hover:shadow-md"
                    >
                        {{-- Salon's branding accent — the distinguishing detail. --}}
                        <div class="h-1.5 w-full" style="background: {{ $accent['accent'] }};"></div>

                        <div class="flex flex-1 flex-col gap-4 p-5">
                            <div class="flex items-start justify-between gap-3">
                                <span class="flex size-11 shrink-0 items-center justify-center rounded-[13px]"
                                      style="background: {{ $accent['tint'] }}; color: {{ $accent['accent'] }};">
                                    <flux:icon.scissors variant="micro" />
                                </span>
                                <div class="flex items-center gap-1.5">
                                    @unless ($salon->active)
                                        <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                    @endunless
                                    @if ($badge)
                                        <span class="bts-pill" style="background: {{ $accent['tint'] }}; color: {{ $accent['ink'] }};">{{ $badge }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="min-w-0">
                                <h3 class="truncate font-display text-[18px] font-bold text-ink transition group-hover:text-[var(--sa)]">{{ $salon->name }}</h3>
                                @if ($salon->legal_business_name !== '' && $salon->legal_business_name !== $salon->name)
                                    <p class="truncate text-[13px] text-faint">{{ $salon->legal_business_name }}</p>
                                @endif
                            </div>

                            {{-- Business details — show what's set, omit what isn't. --}}
                            <dl class="flex flex-col gap-1.5 text-[13px] text-secondary">
                                <div class="flex items-center gap-2">
                                    <flux:icon.clock variant="micro" class="shrink-0 text-faint" />
                                    <dd class="truncate">{{ $salon->timezone }}</dd>
                                </div>
                                @if ($locality !== '')
                                    <div class="flex items-center gap-2">
                                        <flux:icon.map-pin variant="micro" class="shrink-0 text-faint" />
                                        <dd class="truncate">{{ $locality }}</dd>
                                    </div>
                                @endif
                                @if (filled($salon->business_phone))
                                    <div class="flex items-center gap-2">
                                        <flux:icon.phone variant="micro" class="shrink-0 text-faint" />
                                        <dd class="truncate">{{ $salon->business_phone }}</dd>
                                    </div>
                                @endif
                                @if (filled($salon->contact_email))
                                    <div class="flex items-center gap-2">
                                        <flux:icon.envelope variant="micro" class="shrink-0 text-faint" />
                                        <dd class="truncate">{{ $salon->contact_email }}</dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- At-a-glance stats (eager-loaded counts; no extra queries). --}}
                            <div class="mt-auto flex items-center gap-4 border-t border-divider pt-3 text-[13px] text-secondary">
                                <span><span class="font-semibold text-ink">{{ $salon->active_stylists_count }}</span> {{ \Illuminate\Support\Str::plural('stylist', $salon->active_stylists_count) }}</span>
                                <span class="text-divider">·</span>
                                <span><span class="font-semibold text-ink">{{ $salon->active_services_count }}</span> {{ \Illuminate\Support\Str::plural('service', $salon->active_services_count) }}</span>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts::app>
