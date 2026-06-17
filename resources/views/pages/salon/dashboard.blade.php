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
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-secondary transition hover:text-accent">{{ __('← All salons') }}</a>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Today') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        {{-- Stats --}}
        <div class="grid gap-4 sm:grid-cols-4">
            @foreach (['total' => __('Total'), 'waiting' => __('Arrived / waiting'), 'completed' => __('Completed'), 'no_shows' => __('No-shows')] as $key => $label)
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <flux:text class="text-3xl font-serif text-ink">{{ $this->stats[$key] }}</flux:text>
                    <flux:text class="text-xs text-secondary">{{ $label }}</flux:text>
                </div>
            @endforeach
        </div>

        @if ($this->stats['per_stylist'] !== [])
            <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Per-stylist load') }}</flux:heading>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($this->stats['per_stylist'] as $name => $count)
                        <flux:badge color="zinc" size="sm">{{ $name }}: {{ $count }}</flux:badge>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Filters --}}
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
            <flux:spacer />
            @can('manageBookings', $salon)
                <flux:button :href="route('salon.bookings.create', $salon)" wire:navigate variant="primary" icon="plus">{{ __('New booking') }}</flux:button>
            @endcan
        </div>

        {{-- Today's bookings --}}
        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Time') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Client') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Services / stylists') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Booked by') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->bookings as $booking)
                        <tr>
                            <td class="px-4 py-3 text-ink">{{ $booking->items->min('starts_at')?->setTimezone($salon->timezone)->format('g:i A') }}</td>
                            <td class="px-4 py-3 font-medium text-ink">{{ $booking->client->name }}</td>
                            <td class="px-4 py-3 text-secondary">
                                @foreach ($booking->items as $item)
                                    {{ $item->service->name }} · {{ $item->stylist->name }}@if (! $loop->last) <br> @endif
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge :color="$booking->status->color()" size="sm">{{ $booking->status->label() }}</flux:badge>
                                @if ($booking->is_walkin)<flux:badge color="zinc" size="sm">{{ __('Walk-in') }}</flux:badge>@endif
                            </td>
                            <td class="px-4 py-3 text-xs text-secondary">{{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }} · {{ $booking->source->label() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-secondary">{{ __('No bookings for this day.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
