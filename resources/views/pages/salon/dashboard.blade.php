<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    public Salon $salon;
    public string $date = '';
    public string $filterStylist = '';
    public string $filterService = '';
    public string $filterStatus = '';

    public function mount(Salon $salon): void
    {
        $this->authorize('view', $salon);
        $this->salon = $salon;
        $this->date = CarbonImmutable::now($salon->timezone)->format('Y-m-d');
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    /** The day's bookings within the actor's scope, before list filters. */
    #[Computed]
    public function dayBookings()
    {
        $tz = $this->salon->timezone;
        $dayStart = CarbonImmutable::parse($this->date, $tz)->startOfDay();

        return $this->salon->bookings()
            ->with(['client', 'items.service', 'items.stylist', 'bookedBy'])
            ->whereHas('items', fn ($q) => $q
                ->where('starts_at', '>=', $dayStart->utc())
                ->where('starts_at', '<', $dayStart->addDay()->utc()))
            ->when(! $this->isManager, fn ($q) => $q
                ->whereHas('items', fn ($w) => $w->where('stylist_id', Auth::id())))
            ->get()
            ->sortBy(fn (Booking $b) => $b->items->min('starts_at'))
            ->values();
    }

    #[Computed]
    public function bookings()
    {
        return $this->dayBookings
            ->when($this->filterStatus !== '', fn ($c) => $c->where('status', BookingStatus::from($this->filterStatus)))
            ->when($this->filterStylist !== '', fn ($c) => $c->filter(fn (Booking $b) => $b->items->contains('stylist_id', (int) $this->filterStylist)))
            ->when($this->filterService !== '', fn ($c) => $c->filter(fn (Booking $b) => $b->items->contains('service_id', (int) $this->filterService)))
            ->values();
    }

    /**
     * @return array{total: int, waiting: int, completed: int, no_shows: int, per_stylist: array<string, int>}
     */
    #[Computed]
    public function stats(): array
    {
        $day = $this->dayBookings;
        $perStylist = [];
        foreach ($day as $booking) {
            foreach ($booking->items as $item) {
                $name = $item->stylist->name;
                $perStylist[$name] = ($perStylist[$name] ?? 0) + 1;
            }
        }
        ksort($perStylist);

        return [
            'total' => $day->count(),
            'waiting' => $day->where('status', BookingStatus::Arrived)->count(),
            'completed' => $day->where('status', BookingStatus::Completed)->count(),
            'no_shows' => $day->where('status', BookingStatus::NoShow)->count(),
            'per_stylist' => $perStylist,
        ];
    }

    #[Computed]
    public function stylists()
    {
        return $this->salon->stylistUsers()->orderBy('name')->get(['users.id', 'name']);
    }

    #[Computed]
    public function services()
    {
        return $this->salon->services()->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
        {{-- Header: the editorial signature — wide-tracked plum overline date
             over a Fraunces display title. --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="bts-overline">{{ \Carbon\CarbonImmutable::parse($date, $salon->timezone)->translatedFormat('l, j F') }}</div>
                <h1 class="mt-2 font-display text-[30px] font-semibold leading-[1.05] text-ink">{{ __('Today at the salon') }}</h1>
            </div>
            @can('manageBookings', $salon)
                <x-ui.button :href="route('salon.bookings.create', $salon)" wire:navigate>
                    <flux:icon.plus variant="micro" class="shrink-0" />
                    {{ __('Add booking') }}
                </x-ui.button>
            @endcan
        </div>

        {{-- Stats: open editorial figures on the page, separated by rules
             and whitespace — no stat-card boxes. --}}
        <div class="grid gap-x-8 gap-y-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card :label="__('Total bookings')" :value="$this->stats['total']"
                :sublabel="trans_choice('across :count stylist|across :count stylists', count($this->stats['per_stylist']), ['count' => count($this->stats['per_stylist'])])" />
            <x-ui.stat-card :label="__('Waiting')" :value="$this->stats['waiting']" :sublabel="__('arrived, not started')" tone="info" />
            <x-ui.stat-card :label="__('Completed')" :value="$this->stats['completed']" :sublabel="__('so far today')" tone="success" />
            <x-ui.stat-card :label="__('No-shows')" :value="$this->stats['no_shows']" :sublabel="__('today')" tone="danger" />
        </div>

        {{-- Filters (compact; keeps date switching + manager filters) --}}
        <div class="flex flex-wrap items-end gap-3">
            <flux:input type="date" wire:model.live="date" class="max-w-44" :label="__('Date')" />
            @if ($this->isManager)
                <flux:select wire:model.live="filterStylist" :label="__('Stylist')" class="max-w-48">
                    <flux:select.option value="">{{ __('All stylists') }}</flux:select.option>
                    @foreach ($this->stylists as $stylist)
                        <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:select wire:model.live="filterService" :label="__('Service')" class="max-w-48">
                <flux:select.option value="">{{ __('All services') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="filterStatus" :label="__('Status')" class="max-w-44">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (\App\Enums\BookingStatus::cases() as $status)
                    <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Today's bookings: a flat editorial section — heading and count on
             the page, hairline rules and row dividers doing the structure. --}}
        <section wire:loading.class="pointer-events-none opacity-60" wire:target="date, filterStylist, filterService, filterStatus"
             class="flex flex-col transition-opacity">
            <div class="flex items-baseline justify-between gap-4 pb-4">
                <h2 class="bts-card-title">{{ __("Today's bookings") }}</h2>
                <div class="flex items-baseline gap-2 text-[14px] text-secondary">
                    <span class="font-display text-[22px] font-semibold leading-none text-ink">{{ $this->bookings->count() }}</span>
                    {{ __('appointments') }}
                </div>
            </div>

            {{-- Desktop table (keyboard-scrollable when it overflows). --}}
            <div class="hidden overflow-x-auto md:block" tabindex="0">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-y border-divider text-[12.5px] font-semibold uppercase tracking-[0.04em] text-faint">
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('Time') }}</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('Client') }}</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('Service') }}</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('Stylist') }}</th>
                            <th scope="col" class="py-3 pr-4 font-semibold">{{ __('Status') }}</th>
                            <th scope="col" class="py-3 font-semibold">{{ __('Booked by') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-row">
                        @forelse ($this->bookings as $booking)
                            @php($start = $booking->items->min('starts_at'))
                            @php($stylistSeed = $booking->items->first()?->stylist_id ?? 0)
                            @php($bookedLabel = $booking->source === \App\Enums\BookingSource::InApp ? $booking->booked_by_type->label() : $booking->source->label())
                            <tr>
                                <td class="py-4 pr-4 align-top text-[14px] tabular-nums text-faint">{{ $start?->setTimezone($salon->timezone)->format('g:i A') }}</td>
                                <td class="py-4 pr-4 align-top">
                                    <div class="flex items-center gap-3">
                                        <x-ui.avatar :name="$booking->client->name" :seed="$stylistSeed" size="sm" />
                                        <span class="text-[15px] font-medium leading-tight text-ink">{{ $booking->client->name }}</span>
                                        @if ($booking->is_walkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Walk-in') }}</span>@endif
                                    </div>
                                </td>
                                <td class="py-4 pr-4 align-top text-[15px] text-secondary">{{ $booking->items->map(fn ($i) => $i->service->name)->unique()->join(', ') }}</td>
                                <td class="py-4 pr-4 align-top text-[15px] text-secondary">{{ $booking->items->map(fn ($i) => $i->stylist->name)->unique()->join(', ') }}</td>
                                <td class="py-4 pr-4 align-top"><x-ui.status-pill :status="$booking->status" /></td>
                                <td class="py-4 align-top"><x-ui.booked-by :label="$bookedLabel" :source="$booking->source" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="flex flex-col items-center gap-3 py-16 text-center">
                                        <span class="flex size-12 items-center justify-center rounded-full bg-accent-tint">
                                            <flux:icon.calendar-days variant="outline" class="size-6 text-accent-ink" />
                                        </span>
                                        <p class="text-[15px] font-medium text-body">{{ __('No bookings for this day.') }}</p>
                                        <p class="-mt-2 text-[14px] text-faint">{{ __('A quiet page — new appointments appear here as they land.') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Narrow screens: stacked rows (same data, readable on a phone). --}}
            <div class="flex flex-col divide-y divide-row border-t border-divider md:hidden">
                @forelse ($this->bookings as $booking)
                    @php($start = $booking->items->min('starts_at'))
                    @php($stylistSeed = $booking->items->first()?->stylist_id ?? 0)
                    @php($bookedLabel = $booking->source === \App\Enums\BookingSource::InApp ? $booking->booked_by_type->label() : $booking->source->label())
                    <div class="flex flex-col gap-2 py-4">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <x-ui.avatar :name="$booking->client->name" :seed="$stylistSeed" size="sm" />
                            <span class="text-[15px] font-medium leading-tight text-ink">{{ $booking->client->name }}</span>
                            @if ($booking->is_walkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Walk-in') }}</span>@endif
                            <span class="ms-auto"><x-ui.status-pill :status="$booking->status" /></span>
                        </div>
                        <div class="text-[14px] text-secondary">
                            <span class="font-medium text-faint">{{ $start?->setTimezone($salon->timezone)->format('g:i A') }}</span>
                            · {{ $booking->items->map(fn ($i) => $i->service->name)->unique()->join(', ') }}
                            · {{ $booking->items->map(fn ($i) => $i->stylist->name)->unique()->join(', ') }}
                        </div>
                        <x-ui.booked-by :label="$bookedLabel" :source="$booking->source" />
                    </div>
                @empty
                    <div class="py-10 text-center text-[15px] text-faint">{{ __('No bookings for this day.') }}</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
