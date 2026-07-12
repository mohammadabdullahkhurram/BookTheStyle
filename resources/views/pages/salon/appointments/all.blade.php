<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The "look anything up" view: EVERY appointment, past and upcoming, with
 * client/phone search, a date range, and a status filter — distinct from the
 * today-focused check-in. Read-only apart from the status-history modal:
 * status changes stay on check-in. Managers and front desk browse the whole
 * salon; a stylist sees only bookings they are assigned to.
 */
new #[Title('Appointments')] class extends Component {
    use WithPagination;

    public Salon $salon;
    public string $search = '';
    public string $from = '';
    public string $to = '';
    public string $status = '';

    public bool $showTimeline = false;
    public ?int $timelineId = null;

    public function mount(Salon $salon): void
    {
        // Browsing is open to everyone with a bookings surface (managers,
        // front desk, stylists); scoping below narrows stylists to their own.
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'from', 'to', 'status'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function bookings()
    {
        $tz = $this->salon->timezone;
        $term = trim($this->search);

        return $this->salon->bookings()
            ->with(['client', 'items.service', 'items.stylist', 'bookedBy'])
            ->when(! $this->isManager, fn ($q) => $q
                ->whereHas('items', fn ($w) => $w->where('stylist_id', Auth::id())))
            ->when($term !== '', fn ($q) => $q
                ->whereHas('client', fn ($w) => $w
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")))
            ->when($this->from !== '', fn ($q) => $q
                ->whereHas('items', fn ($w) => $w
                    ->where('starts_at', '>=', CarbonImmutable::parse($this->from, $tz)->startOfDay()->utc())))
            ->when($this->to !== '', fn ($q) => $q
                ->whereHas('items', fn ($w) => $w
                    ->where('starts_at', '<', CarbonImmutable::parse($this->to, $tz)->startOfDay()->addDay()->utc())))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->orderByDesc(
                BookingItem::select('starts_at')
                    ->whereColumn('booking_id', 'bookings.id')
                    ->orderBy('starts_at')
                    ->limit(1)
            )
            ->paginate(25);
    }

    public function openTimeline(int $bookingId): void
    {
        $this->booking($bookingId); // authorise scope (anti-IDOR)
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


    public function changeStatus(int $bookingId, string $to, \App\Actions\Bookings\TransitionBookingStatus $action): void
    {
        $booking = $this->booking($bookingId);
        $action->handle(Auth::user(), $this->salon, $booking, BookingStatus::from($to));
        unset($this->bookings);

        Flux::toast(variant: 'success', text: __('Booking updated.'));
    }

    // --- Reschedule (front-desk level; same stylist + services) -----------

    public bool $showReschedule = false;

    public ?int $rescheduleId = null;

    public string $rescheduleDate = '';

    public function openReschedule(int $bookingId): void
    {
        abort_unless(Auth::user()->can('manageBookings', $this->salon), 403);
        $booking = $this->booking($bookingId);

        $this->resetErrorBag('start');
        $this->rescheduleId = $booking->id;
        $this->rescheduleDate = $booking->items()->orderBy('starts_at')->first()
            ?->starts_at->setTimezone($this->salon->timezone)->format('Y-m-d')
            ?? CarbonImmutable::now($this->salon->timezone)->format('Y-m-d');
        $this->showReschedule = true;
    }

    /**
     * Real slot-engine start times on the picked date where the WHOLE visit
     * fits — every service item, in order, with its stylist and buffers — not
     * just the first service. The booking's own current slots are excluded
     * from conflicts (so nearby times stay offered).
     *
     * @return list<string>
     */
    #[Computed]
    public function rescheduleSlots(): array
    {
        if ($this->rescheduleId === null || $this->rescheduleDate === '') {
            return [];
        }

        $booking = $this->salon->bookings()->with('items')->whereKey($this->rescheduleId)->first();
        if ($booking === null) {
            return [];
        }

        $tz = $this->salon->timezone;

        return array_map(
            fn ($slot): string => $slot->setTimezone($tz)->format('H:i'),
            app(\App\Services\Booking\RescheduleSlots::class)
                ->startTimes($this->salon, $booking, $this->rescheduleDate),
        );
    }

    public function reschedule(string $time, \App\Actions\Bookings\RescheduleBooking $action): void
    {
        $booking = $this->booking((int) $this->rescheduleId);

        $action->handle(Auth::user(), $this->salon, $booking, "{$this->rescheduleDate} {$time}");

        $this->showReschedule = false;
        $this->rescheduleId = null;
        unset($this->bookings);

        Flux::toast(variant: 'success', text: __('Booking rescheduled.'));
    }

    private function booking(int $id): Booking
    {
        $booking = $this->salon->bookings()->whereKey($id)->firstOrFail();

        // Stylists may only open bookings they are assigned to.
        abort_unless(
            $this->isManager || $booking->items()->where('stylist_id', Auth::id())->exists(),
            403,
        );

        return $booking;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('All dates')" :title="__('Appointments')">
            <x-slot:actions>
                @can('manageBookings', $salon)
                    <x-ui.button :href="route('salon.bookings.create', $salon)" wire:navigate>
                        <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add booking') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search client or phone')" :label="__('Search')" class="max-w-64" />
            <flux:input type="date" wire:model.live="from" class="max-w-44" :label="__('From')" />
            <flux:input type="date" wire:model.live="to" class="max-w-44" :label="__('To')" />
            <flux:select wire:model.live="status" :label="__('Status')" class="max-w-44">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (\App\Enums\BookingStatus::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <div class="flex flex-col gap-3">
            @forelse ($this->bookings as $booking)
                @php($start = $booking->items->min('starts_at'))
                @php($dimmed = in_array($booking->status, [\App\Enums\BookingStatus::Completed, \App\Enums\BookingStatus::NoShow, \App\Enums\BookingStatus::Cancelled], true))
                @php($seed = $booking->items->first()?->stylist_id ?? 0)
                <x-ui.card padding="p-5" class="{{ $dimmed ? 'opacity-65' : '' }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-4">
                            <div class="w-28 shrink-0 pt-0.5 text-[13.5px] font-medium leading-snug text-faint">
                                {{ $start?->setTimezone($salon->timezone)->format('D, M j') }}<br>
                                {{ $start?->setTimezone($salon->timezone)->format('g:i A') }}
                            </div>
                            <x-ui.avatar :name="$booking->client->name" :seed="$seed" size="sm" class="mt-0.5" />
                            <div class="flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('salon.client', ['salon' => $salon, 'clientId' => $booking->client_id]) }}" wire:navigate class="text-[15px] font-semibold text-ink transition hover:text-accent">{{ $booking->client->name }}</a>
                                    <x-ui.status-pill :status="$booking->status" />
                                    @if ($booking->is_walkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Walk-in') }}</span>@endif
                                    @can('manage', $salon)
                                        @if ($booking->ghl_sync_status === 'failed')
                                            <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;" title="{{ $booking->ghl_sync_error }}">{{ __('Sync failed') }}</span>
                                        @endif
                                    @endcan
                                </div>
                                <div class="text-[14px] text-secondary">
                                    @foreach ($booking->items as $item)
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="size-2 rounded-full" style="background-color: {{ $item->service->palette()['dot'] }}"></span>
                                            {{ $item->service->name }} · {{ $item->stylist->name }}@if (! $loop->last), @endif
                                        </span>
                                    @endforeach
                                </div>
                                <div class="text-[12.5px] text-faint">{{ __('Booked by') }} {{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }} · {{ $booking->source->label() }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            @if ($this->isManager)
                                <div class="flex flex-wrap justify-end gap-2">
                                    @foreach ($booking->status->allowedTransitions() as $next)
                                        @if ($next === \App\Enums\BookingStatus::Arrived)
                                            <x-ui.button size="sm" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ __('Checked in') }}</x-ui.button>
                                        @else
                                            <x-ui.button size="sm" variant="secondary" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ $next->label() }}</x-ui.button>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                            <div class="flex items-center gap-3">
                                @if ($this->isManager)
                                    <button type="button" wire:click="openReschedule({{ $booking->id }})" class="text-[13px] font-medium text-secondary transition hover:text-accent">{{ __('Reschedule') }}</button>
                                @endif
                                <button type="button" wire:click="openTimeline({{ $booking->id }})" class="text-[13px] font-medium text-secondary transition hover:text-accent">{{ __('History') }}</button>
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card padding="p-10" class="text-center text-[15px] text-faint">
                    {{ __('No appointments match these filters.') }}
                </x-ui.card>
            @endforelse

            {{ $this->bookings->links() }}
        </div>
    </div>

    @can('manageBookings', $salon)
        @include('partials.booking-reschedule-modal')
    @endcan

    <x-ui.modal wire:model="showTimeline" class="max-w-md" :heading="__('Status history')">
        <div class="flex flex-col gap-3">
            @forelse ($this->timeline as $event)
                <div class="flex flex-col gap-1">
                    <div class="flex items-center justify-between gap-3 text-[14px]">
                        <x-ui.status-pill :status="$event->to_status" />
                        <span class="flex-1 truncate text-secondary">{{ $event->actor?->name }}</span>
                        <span class="text-[12.5px] text-faint">{{ $event->created_at?->setTimezone($salon->timezone)->format('M j, g:i A') }}</span>
                    </div>
                    @if ($event->note)
                        <p class="text-[13px] text-secondary">{{ $event->note }}</p>
                    @endif
                </div>
            @empty
                <div class="text-[14px] text-secondary">{{ __('No history yet.') }}</div>
            @endforelse
        </div>
    </x-ui.modal>
</div>
