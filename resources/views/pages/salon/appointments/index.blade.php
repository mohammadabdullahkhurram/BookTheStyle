<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appointments')] class extends Component {
    public Salon $salon;
    public string $date = '';
    public string $search = '';

    public bool $showTimeline = false;
    public ?int $timelineId = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;
        $this->date = CarbonImmutable::now($salon->timezone)->format('Y-m-d');
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    #[Computed]
    public function bookings()
    {
        $tz = $this->salon->timezone;
        $dayStart = CarbonImmutable::parse($this->date, $tz)->startOfDay();
        $dayEnd = $dayStart->addDay();
        $term = trim($this->search);

        return $this->salon->bookings()
            ->with(['client', 'items.service', 'items.stylist', 'bookedBy'])
            ->whereHas('items', fn ($q) => $q
                ->where('starts_at', '>=', $dayStart->utc())
                ->where('starts_at', '<', $dayEnd->utc()))
            ->when(! $this->isManager, fn ($q) => $q
                ->whereHas('items', fn ($w) => $w->where('stylist_id', Auth::id())))
            ->when($term !== '', fn ($q) => $q
                ->whereHas('client', fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")))
            ->get()
            ->sortBy(fn (Booking $b) => $b->items->min('starts_at'))
            ->values();
    }

    public function changeStatus(int $bookingId, string $to, TransitionBookingStatus $action): void
    {
        $booking = $this->booking($bookingId);
        $action->handle(Auth::user(), $this->salon, $booking, BookingStatus::from($to));
        unset($this->bookings);

        Flux::toast(variant: 'success', text: __('Booking updated.'));
    }

    public function openTimeline(int $bookingId): void
    {
        $this->booking($bookingId); // authorise scope
        $this->timelineId = $bookingId;
        $this->showTimeline = true;
    }

    #[Computed]
    public function timeline()
    {
        if ($this->timelineId === null) {
            return collect();
        }

        return $this->salon->bookings()
            ->whereKey($this->timelineId)
            ->first()?->statusEvents()
            ->with('actor:id,name')->orderBy('created_at')->get() ?? collect();
    }

    private function booking(int $id): Booking
    {
        $booking = $this->salon->bookings()->whereKey($id)->firstOrFail();

        // Stylists may only touch bookings they are assigned to.
        abort_unless(
            $this->isManager || $booking->items()->where('stylist_id', Auth::id())->exists(),
            403,
        );

        return $booking;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Appointments') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <flux:input type="date" wire:model.live="date" class="max-w-44" />
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search client')" class="max-w-64" />
            <flux:spacer />
            <flux:button :href="route('salon.bookings.create', $salon)" wire:navigate variant="primary" icon="plus">{{ __('New booking') }}</flux:button>
        </div>

        <div class="flex flex-col gap-3">
            @forelse ($this->bookings as $booking)
                @php($start = $booking->items->min('starts_at'))
                <div class="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-serif text-lg text-ink">{{ $start?->setTimezone($salon->timezone)->format('g:i A') }}</span>
                                <flux:badge :color="$booking->status->color()" size="sm">{{ $booking->status->label() }}</flux:badge>
                                @if ($booking->is_walkin)<flux:badge color="zinc" size="sm">{{ __('Walk-in') }}</flux:badge>@endif
                            </div>
                            <div class="font-medium text-ink">{{ $booking->client->name }}</div>
                            <div class="text-sm text-secondary">
                                @foreach ($booking->items as $item)
                                    <span class="inline-flex items-center gap-1">
                                        <span class="size-2 rounded-full" style="background-color: {{ $item->service->color }}"></span>
                                        {{ $item->service->name }} · {{ $item->stylist->name }}@if (! $loop->last), @endif
                                    </span>
                                @endforeach
                            </div>
                            <div class="text-xs text-secondary">{{ __('Booked by') }} {{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }} · {{ $booking->source->label() }}</div>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                @foreach ($booking->status->allowedTransitions() as $next)
                                    <flux:button size="xs"
                                        variant="{{ $next === \App\Enums\BookingStatus::Arrived ? 'primary' : 'ghost' }}"
                                        wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">
                                        {{ $next === \App\Enums\BookingStatus::Arrived ? __('Mark arrived') : $next->label() }}
                                    </flux:button>
                                @endforeach
                            </div>
                            <flux:button size="xs" variant="ghost" wire:click="openTimeline({{ $booking->id }})">{{ __('History') }}</flux:button>
                        </div>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-border bg-card p-8 text-center text-secondary shadow-sm">
                    {{ __('No appointments for this day.') }}
                </div>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model="showTimeline" class="max-w-md">
        <flux:heading size="lg" class="font-serif">{{ __('Status history') }}</flux:heading>
        <div class="mt-4 flex flex-col gap-3">
            @forelse ($this->timeline as $event)
                <div class="flex items-center justify-between text-sm">
                    <div>
                        <span class="font-medium text-ink">{{ $event->to_status->label() }}</span>
                        @if ($event->actor)<span class="text-secondary"> · {{ $event->actor->name }}</span>@endif
                    </div>
                    <span class="text-xs text-secondary">{{ $event->created_at?->setTimezone($salon->timezone)->format('M j, g:i A') }}</span>
                </div>
            @empty
                <flux:text class="text-sm text-secondary">{{ __('No history yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:modal>
</div>
