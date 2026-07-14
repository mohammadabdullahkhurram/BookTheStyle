<?php

use App\Actions\Salons\SetSalonActive;
use App\Enums\AgencyRole;
use App\Enums\SalonRole;
use App\Enums\StaffType;
use App\Models\Salon;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Salons — the salon picker AND (for agency salon-managers) the one place
 * salons are managed. "Welcome back, choose a salon" cards for everyone;
 * agency owners/admins additionally get a Gallery | List toggle, per-salon
 * Edit links, and Deactivate/Reactivate — folded in from the retired
 * agency salons index so the sidebar carries exactly ONE Salons entry.
 */
new #[Title('Salons')] class extends Component {
    /** gallery (cards, the default) | list (table — salon managers only). */
    public string $view = 'gallery';

    /** Whether this user manages the agency's salons (edit/deactivate/toggle). */
    #[Computed]
    public function managesSalons(): bool
    {
        $user = Auth::user();

        return $user->agency !== null && $user->can('manageSalons', $user->agency);
    }

    /**
     * Salons the user can open: active memberships + agency reach (every
     * salon for an owner/admin, assigned salons for an agency_user). One
     * query for the cards (withCount aggregates) — no N+1.
     */
    #[Computed]
    public function salons()
    {
        $user = Auth::user();

        $ids = $user->salons()->wherePivot('active', true)->pluck('salons.id');
        if ($user->isAgencyOperator() && $user->agency_id) {
            $ids = $ids->merge($user->agency->salons()->pluck('salons.id'));
        } elseif ($user->agency_role === AgencyRole::User) {
            $ids = $ids->merge($user->assignedSalons()->pluck('salons.id'));
        }

        return Salon::query()
            ->whereIn('id', $ids->unique()->values())
            ->withCount([
                'stylistUsers as active_stylists_count',
                'services as active_services_count' => fn ($q) => $q->where('active', true),
            ])
            ->orderBy('name')
            ->get();
    }

    /** The user's active memberships keyed by salon id (one query). */
    #[Computed]
    public function memberships()
    {
        return Auth::user()->salonMemberships()->where('active', true)->get()->keyBy('salon_id');
    }

    public function roleBadge(Salon $salon): ?string
    {
        $user = Auth::user();
        $m = $this->memberships->get($salon->id);

        return match (true) {
            $m && $m->salon_role === SalonRole::Owner => __('Owner'),
            $m && $m->salon_role === SalonRole::Admin => __('Admin'),
            $m && $m->staff_type === StaffType::Stylist => __('Stylist'),
            $m && $m->staff_type === StaffType::FrontDesk => __('Front desk'),
            $m !== null => __('Staff'),
            // Agency operator reaching a salon without a salon membership.
            default => $user->agency_role?->label(),
        };
    }

    public function toggleActive(int $salonId, SetSalonActive $action): void
    {
        $user = Auth::user();
        abort_if($user->agency === null, 403);
        $this->authorize('manageSalons', $user->agency);

        // Scope the lookup to this agency: an out-of-agency id 404s (no IDOR).
        $salon = $user->agency->salons()->whereKey($salonId)->firstOrFail();

        $action->handle($salon, ! $salon->active);
        unset($this->salons);

        Flux::toast(variant: 'success', text: $salon->active ? __('Salon reactivated.') : __('Salon deactivated.'));
    }
}; ?>

<div>
    @php($user = auth()->user())
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-8 p-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1">
                <flux:heading size="xl" class="font-serif">{{ __('Welcome back, :name', ['name' => $user->name]) }}</flux:heading>
                <flux:text class="text-secondary">{{ __('Choose a salon to open its calendar and bookings.') }}</flux:text>
            </div>
            @if ($this->managesSalons && $this->salons->isNotEmpty())
                <flux:radio.group wire:model.live="view" variant="segmented" :label="__('View')" label:class="sr-only">
                    <flux:radio value="gallery" :label="__('Gallery')" />
                    <flux:radio value="list" :label="__('List')" />
                </flux:radio.group>
            @endif
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

        @if ($this->salons->isEmpty())
            <div class="rounded-xl border border-border bg-card p-8 text-center shadow-sm">
                <flux:heading size="lg" class="font-serif">{{ __('No salons yet') }}</flux:heading>
                <flux:text class="mt-2 text-secondary">
                    @if ($this->managesSalons)
                        {{ __('Create the first salon from the sidebar — it takes a minute.') }}
                    @else
                        {{ __('You are not a member of any salon. An administrator will add you to one.') }}
                    @endif
                </flux:text>
            </div>
        @elseif ($view === 'list' && $this->managesSalons)
            {{-- List view (salon managers): the compact management table. --}}
            <x-ui.card padding="p-0" class="overflow-hidden" data-view="list">
                <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bts-overline border-b border-divider">
                            <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Salon') }}</th>
                            <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Timezone') }}</th>
                            <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Stylists') }}</th>
                            <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3.5"><span class="sr-only">{{ __('Actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-row">
                        @foreach ($this->salons as $salon)
                            <tr @class(['bg-muted/40' => ! $salon->active])>
                                <td class="px-6 py-4">
                                    <a href="{{ route('salon.show', $salon) }}" wire:navigate class="text-[15px] font-medium text-ink transition hover:text-accent">{{ $salon->name }}</a>
                                    <div class="text-[12.5px] text-faint">{{ $salon->slug }}.{{ config('app.domain') }}</div>
                                </td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $salon->timezone }}</td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $salon->active_stylists_count }}</td>
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
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-ui.card>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-view="gallery">
                @foreach ($this->salons as $salon)
                    {{-- Inline php directives only — the BLOCK form's markers
                         mis-pair inside single-file components. --}}
                    @php($accent = \App\Support\AccentPalette::resolve($salon->accentColor()) ?? \App\Support\AccentPalette::PRESETS['violet'])
                    @php($badge = $this->roleBadge($salon))
                    @php($locality = collect([$salon->city, $salon->region ?: $salon->country])->filter(fn ($v) => filled($v))->implode(', '))

                    {{-- A whole-card link (the primary affordance) with the salon's
                         branding accent; managers get edit/deactivate beneath. --}}
                    <div style="--sa: {{ $accent['accent'] }};"
                         @class(['group flex flex-col overflow-hidden rounded-[18px] border border-border bg-card shadow-card transition hover:border-[var(--sa)] hover:shadow-md', 'opacity-70' => ! $salon->active])>
                        <a href="{{ route('salon.show', $salon) }}" wire:navigate class="flex flex-1 flex-col">
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

                        @if ($this->managesSalons)
                            <div class="flex items-center gap-4 border-t border-divider px-5 py-3">
                                <a href="{{ route('agency.salons.edit', $salon) }}" wire:navigate class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</a>
                                <button type="button"
                                        @if ($salon->active) wire:confirm="{{ __('Deactivate :salon? All its staff lose access until it is reactivated. No data is deleted.', ['salon' => $salon->name]) }}" @endif
                                        wire:click="toggleActive({{ $salon->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">
                                    {{ $salon->active ? __('Deactivate') : __('Reactivate') }}
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
