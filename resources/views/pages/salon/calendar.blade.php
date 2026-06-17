<?php

use App\Actions\Bookings\TransitionBookingStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Salon;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendar')] class extends Component {
    public Salon $salon;

    /** Master feed (all stylists) vs a single stylist's own calendar. */
    public bool $isMaster = false;
    public ?int $stylistId = null;

    /** Visible range (ISO-8601 UTC), driven by the Toast UI view via Alpine. */
    public string $rangeStart = '';
    public string $rangeEnd = '';

    public bool $showDetail = false;
    public ?int $detailId = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;

        // Master calendar = anyone who manages bookings (owner/admin/front desk
        // + agency operators); otherwise the signed-in stylist's own calendar.
        $this->isMaster = Auth::user()->can('manageBookings', $salon);
        if (! $this->isMaster) {
            abort_unless(Auth::user()->stylistMembershipFor($salon) !== null, 403);
            $this->stylistId = Auth::id();
        }

        // A sensible first range; Alpine immediately reports the exact one.
        $now = CarbonImmutable::now($salon->timezone);
        $this->rangeStart = $now->startOfWeek()->utc()->toIso8601ZuluString();
        $this->rangeEnd = $now->endOfWeek()->utc()->toIso8601ZuluString();
    }

    /**
     * The server-side, salon-scoped, role-filtered feed for the visible range.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function payload(): array
    {
        return app(CalendarData::class)->build(
            $this->salon,
            CarbonImmutable::parse($this->rangeStart),
            CarbonImmutable::parse($this->rangeEnd),
            $this->isMaster ? null : $this->stylistId,
        );
    }

    /** Alpine reports the visible range; reply with the feed for it. */
    public function setRange(string $start, string $end): void
    {
        $this->rangeStart = $start;
        $this->rangeEnd = $end;
        unset($this->payload);
        $this->dispatch('calendar:data', payload: $this->payload);
    }

    /** Polled every few seconds — re-push the current feed. */
    public function refresh(): void
    {
        unset($this->payload);
        $this->dispatch('calendar:data', payload: $this->payload);
    }

    /**
     * Click-to-book: open the existing Phase 3 booking form prefilled with the
     * clicked start (and, on a per-stylist calendar, that stylist). Prefilled
     * values are conveniences only — CreateBooking re-validates authoritatively.
     */
    public function selectSlot(string $start): void
    {
        $this->authorize('accessBookings', $this->salon);

        $local = CarbonImmutable::parse($start)->setTimezone($this->salon->timezone);
        $params = ['salon' => $this->salon, 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i')];
        if (! $this->isMaster && $this->stylistId !== null) {
            $params['stylist'] = $this->stylistId;
        }

        $this->redirectRoute('salon.bookings.create', $params, navigate: true);
    }

    public function openBooking(int $bookingId): void
    {
        $this->booking($bookingId); // authorise scope (anti-IDOR)
        $this->detailId = $bookingId;
        $this->showDetail = true;
    }

    /** The booking shown in the detail panel (or null). */
    #[Computed]
    public function detail(): ?Booking
    {
        if ($this->detailId === null) {
            return null;
        }

        return $this->salon->bookings()
            ->with(['client', 'items.service', 'items.stylist', 'bookedBy', 'statusEvents.actor:id,name'])
            ->whereKey($this->detailId)
            ->first();
    }

    public function changeStatus(int $bookingId, string $to, TransitionBookingStatus $action): void
    {
        $booking = $this->booking($bookingId);
        $action->handle(Auth::user(), $this->salon, $booking, BookingStatus::from($to));

        unset($this->detail, $this->payload);
        $this->dispatch('calendar:data', payload: $this->payload); // refresh the event live

        Flux::toast(variant: 'success', text: __('Booking updated.'));
    }

    /**
     * Load a booking in this salon, enforcing that a stylist may only reach
     * bookings they are assigned to (mirrors the appointments screen).
     */
    private function booking(int $id): Booking
    {
        $booking = $this->salon->bookings()->whereKey($id)->firstOrFail();

        abort_unless(
            $this->isMaster || $booking->items()->where('stylist_id', Auth::id())->exists(),
            403,
        );

        return $booking;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function jsConfig(): array
    {
        return [
            'timezone' => $this->salon->timezone,
            'accent' => $this->salon->accentColor() ?? '#1F6F6B',
            'isMaster' => $this->isMaster,
        ];
    }
}; ?>

<div wire:poll.5s="refresh">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ $isMaster ? __('Master calendar') : __('My calendar') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <div x-data="bookingCalendar(@js($this->jsConfig))" wire:ignore>
            {{-- Toolbar --}}
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <div class="inline-flex overflow-hidden rounded-lg border border-border">
                    <button type="button" @click="setView('day')"
                        class="px-3 py-1.5 text-sm transition"
                        :class="view === 'day' ? 'bg-accent-soft text-accent' : 'text-secondary hover:text-ink'">{{ __('Day') }}</button>
                    <button type="button" @click="setView('week')"
                        class="border-l border-border px-3 py-1.5 text-sm transition"
                        :class="view === 'week' ? 'bg-accent-soft text-accent' : 'text-secondary hover:text-ink'">{{ __('Week') }}</button>
                </div>

                <div class="inline-flex items-center gap-1">
                    <button type="button" @click="prev()" class="rounded-md border border-border p-1.5 text-secondary transition hover:text-ink" aria-label="{{ __('Previous') }}">
                        <flux:icon.chevron-left variant="micro" />
                    </button>
                    <button type="button" @click="today()" class="rounded-md border border-border px-3 py-1.5 text-sm text-secondary transition hover:text-ink">{{ __('Today') }}</button>
                    <button type="button" @click="next()" class="rounded-md border border-border p-1.5 text-secondary transition hover:text-ink" aria-label="{{ __('Next') }}">
                        <flux:icon.chevron-right variant="micro" />
                    </button>
                </div>

                <div class="font-serif text-lg text-ink" x-text="label"></div>

                <flux:spacer />

                @can('manageBookings', $salon)
                    <flux:button :href="route('salon.bookings.create', $salon)" wire:navigate variant="primary" size="sm" icon="plus">{{ __('New booking') }}</flux:button>
                @endcan
            </div>

            {{-- Per-stylist filter (master view) --}}
            <template x-if="calendars.length > 1">
                <div class="mb-4 flex flex-wrap items-center gap-3">
                    <span class="text-xs uppercase tracking-wide text-secondary">{{ __('Stylists') }}</span>
                    <template x-for="c in calendars" :key="c.id">
                        <button type="button" @click="toggle(c.id)"
                            class="inline-flex items-center gap-2 rounded-full border border-border px-3 py-1 text-sm transition"
                            :class="hidden[c.id] ? 'text-secondary opacity-50' : 'text-ink'">
                            <span class="size-2.5 rounded-full" :style="`background-color:${c.color}`"></span>
                            <span x-text="c.name"></span>
                        </button>
                    </template>
                </div>
            </template>

            <div x-ref="cal" class="bts-calendar" style="height: 720px"></div>
        </div>
    </div>

    {{-- Booking detail panel --}}
    <flux:modal wire:model="showDetail" class="max-w-lg">
        @if ($this->detail)
            @php($booking = $this->detail)
            @php($start = $booking->items->min('starts_at'))
            <div class="flex flex-col gap-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg" class="font-serif">{{ $booking->client->name }}</flux:heading>
                        <flux:text class="text-sm text-secondary">
                            {{ $start?->setTimezone($salon->timezone)->format('l, M j · g:i A') }}
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:badge :color="$booking->status->color()" size="sm">{{ $booking->status->label() }}</flux:badge>
                        @if ($booking->is_walkin)<flux:badge color="zinc" size="sm">{{ __('Walk-in') }}</flux:badge>@endif
                    </div>
                </div>

                <div class="flex flex-col gap-2 rounded-lg border border-border bg-muted/40 p-4">
                    @foreach ($booking->items as $item)
                        <div class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full" style="background-color: {{ $item->service->color }}"></span>
                                <span class="font-medium text-ink">{{ $item->service->name }}</span>
                                <span class="text-secondary">· {{ $item->stylist->name }}</span>
                            </div>
                            <span class="text-secondary">
                                {{ $item->starts_at?->setTimezone($salon->timezone)->format('g:i A') }}–{{ $item->ends_at?->setTimezone($salon->timezone)->format('g:i A') }}
                            </span>
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <div class="text-xs uppercase tracking-wide text-secondary">{{ __('Booked by') }}</div>
                        <div class="text-ink">{{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide text-secondary">{{ __('Source') }}</div>
                        <div class="text-ink">{{ $booking->source->label() }}</div>
                    </div>
                </div>

                @if ($booking->notes)
                    <div class="text-sm">
                        <div class="text-xs uppercase tracking-wide text-secondary">{{ __('Notes') }}</div>
                        <div class="text-ink">{{ $booking->notes }}</div>
                    </div>
                @endif

                {{-- Status actions (reuses the Phase 3 transition flow) --}}
                @if ($booking->status->allowedTransitions() !== [])
                    <div class="flex flex-wrap gap-2 border-t border-border pt-4">
                        @foreach ($booking->status->allowedTransitions() as $next)
                            <flux:button size="xs"
                                variant="{{ $next === \App\Enums\BookingStatus::Arrived ? 'primary' : ($next === \App\Enums\BookingStatus::Cancelled ? 'danger' : 'ghost') }}"
                                wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">
                                {{ $next === \App\Enums\BookingStatus::Arrived ? __('Mark arrived') : $next->label() }}
                            </flux:button>
                        @endforeach
                    </div>
                @endif

                {{-- History --}}
                <div class="border-t border-border pt-4">
                    <div class="mb-2 text-xs uppercase tracking-wide text-secondary">{{ __('History') }}</div>
                    <div class="flex flex-col gap-1.5">
                        @foreach ($booking->statusEvents->sortBy('created_at') as $event)
                            <div class="flex items-center justify-between text-sm">
                                <div>
                                    <span class="font-medium text-ink">{{ $event->to_status->label() }}</span>
                                    @if ($event->actor)<span class="text-secondary"> · {{ $event->actor->name }}</span>@endif
                                </div>
                                <span class="text-xs text-secondary">{{ $event->created_at?->setTimezone($salon->timezone)->format('M j, g:i A') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
