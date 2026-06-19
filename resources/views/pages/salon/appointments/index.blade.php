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
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('Check-in')" :title="__('Appointments')">
            <x-slot:actions>
                @can('manageBookings', $salon)
                    <x-ui.button :href="route('salon.bookings.create', $salon)" wire:navigate>
                        <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add booking') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input type="date" wire:model.live="date" class="max-w-44" :label="__('Date')" />
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search client')" :label="__('Search')" class="max-w-64" />
        </div>

        <div class="flex flex-col gap-3">
            @forelse ($this->bookings as $booking)
                @php($start = $booking->items->min('starts_at'))
                @php($dimmed = in_array($booking->status, [\App\Enums\BookingStatus::Completed, \App\Enums\BookingStatus::NoShow, \App\Enums\BookingStatus::Cancelled], true))
                @php($seed = $booking->items->first()?->stylist_id ?? 0)
                <x-ui.card padding="p-5" class="{{ $dimmed ? 'opacity-65' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-4">
                            <div class="w-16 shrink-0 pt-0.5 text-[14px] font-medium text-faint">{{ $start?->setTimezone($salon->timezone)->format('g:i A') }}</div>
                            <x-ui.avatar :name="$booking->client->name" :seed="$seed" size="sm" class="mt-0.5" />
                            <div class="flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-[15px] font-semibold text-ink">{{ $booking->client->name }}</span>
                                    <x-ui.status-pill :status="$booking->status" />
                                    @if ($booking->is_walkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Walk-in') }}</span>@endif
                                </div>
                                <div class="text-[14px] text-secondary">
                                    @foreach ($booking->items as $item)
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="size-2 rounded-full" style="background-color: {{ $item->service->color }}"></span>
                                            {{ $item->service->name }} · {{ $item->stylist->name }}@if (! $loop->last), @endif
                                        </span>
                                    @endforeach
                                </div>
                                <div class="text-[12.5px] text-faint">{{ __('Booked by') }} {{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }} · {{ $booking->source->label() }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            <div class="flex flex-wrap justify-end gap-2">
                                @foreach ($booking->status->allowedTransitions() as $next)
                                    @if ($next === \App\Enums\BookingStatus::Arrived)
                                        <x-ui.button size="sm" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ __('Mark arrived') }}</x-ui.button>
                                    @else
                                        <x-ui.button size="sm" variant="secondary" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ $next->label() }}</x-ui.button>
                                    @endif
                                @endforeach
                            </div>
                            <button type="button" wire:click="openTimeline({{ $booking->id }})" class="text-[13px] font-medium text-secondary transition hover:text-accent">{{ __('History') }}</button>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card padding="p-10" class="text-center text-[15px] text-faint">
                    {{ __('No appointments for this day.') }}
                </x-ui.card>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model="showTimeline" class="max-w-md">
        <h2 class="bts-card-title">{{ __('Status history') }}</h2>
        <div class="mt-4 flex flex-col gap-3">
            @forelse ($this->timeline as $event)
                <div class="flex items-center justify-between gap-3 text-[14px]">
                    <x-ui.status-pill :status="$event->to_status" />
                    <span class="flex-1 truncate text-secondary">{{ $event->actor?->name }}</span>
                    <span class="text-[12.5px] text-faint">{{ $event->created_at?->setTimezone($salon->timezone)->format('M j, g:i A') }}</span>
                </div>
            @empty
                <div class="text-[14px] text-secondary">{{ __('No history yet.') }}</div>
            @endforelse
        </div>
    </flux:modal>
</div>
