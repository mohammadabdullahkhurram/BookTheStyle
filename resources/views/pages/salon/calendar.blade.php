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

    /** Viewed day (Y-m-d, salon timezone) and view mode. */
    public string $date = '';
    public string $view = 'day';

    /** Stylist ids hidden by the day-view filter. */
    public array $hidden = [];

    public bool $showDetail = false;
    public ?int $detailId = null;

    /** Whether the viewer may create bookings from this calendar. */
    public bool $canCreate = false;

    public function mount(Salon $salon): void
    {
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;

        // Managers: the master board, with click-to-book. EMPLOYEE stylists:
        // the shared salon board too (they work the same floor), read-only.
        // BOOTH RENTERS: their own column ONLY — separate businesses must
        // not see each other's books (SPEC §2) — with click-to-book on it.
        $manages = Auth::user()->can('manageBookings', $salon);
        $boothRenter = Auth::user()->boothRenterMembershipFor($salon) !== null;

        if (! $manages) {
            abort_unless(Auth::user()->stylistMembershipFor($salon) !== null, 403);
        }

        $this->isMaster = $manages || (! $boothRenter);
        $this->stylistId = $manages ? null : Auth::id();
        if ($boothRenter) {
            $this->isMaster = false;
        }
        $this->canCreate = $manages || $boothRenter;

        $this->date = CarbonImmutable::now($salon->timezone)->format('Y-m-d');
    }

    /**
     * The server-side, salon-scoped, role-filtered column-grid feed.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function grid(): array
    {
        $only = $this->isMaster ? null : $this->stylistId;
        $anchor = CarbonImmutable::parse($this->date, $this->salon->timezone);

        return $this->view === 'week'
            ? app(CalendarData::class)->week($this->salon, $anchor, $only)
            : app(CalendarData::class)->day($this->salon, $anchor, $only);
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['day', 'week'], true) ? $view : 'day';
        unset($this->grid);
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->salon->timezone)->format('Y-m-d');
        unset($this->grid);
    }

    public function prev(): void
    {
        $this->shift(-1);
    }

    public function next(): void
    {
        $this->shift(1);
    }

    private function shift(int $direction): void
    {
        $step = $this->view === 'week' ? 7 : 1;
        $this->date = CarbonImmutable::parse($this->date, $this->salon->timezone)
            ->addDays($direction * $step)
            ->format('Y-m-d');
        unset($this->grid);
    }

    public function toggleStylist(int $stylistId): void
    {
        $this->hidden = in_array($stylistId, $this->hidden, true)
            ? array_values(array_diff($this->hidden, [$stylistId]))
            : [...$this->hidden, $stylistId];
    }

    /** Polled refresh — the grid computed re-runs on the new request. */
    public function refresh(): void
    {
        unset($this->grid);
    }

    /**
     * Click-to-book: open the Phase 3 booking form prefilled with the clicked
     * start (and, where known, that stylist). Prefilled values are conveniences
     * only — CreateBooking re-validates authoritatively via the slot engine.
     */
    public function selectSlot(string $start, ?int $stylistId = null): void
    {
        // Managers book anyone; a booth renter books ONLY themselves
        // (employee stylists fail the ability outright).
        $this->authorize('createBookings', $this->salon);

        $local = CarbonImmutable::parse($start)->setTimezone($this->salon->timezone);
        $params = ['salon' => $this->salon, 'date' => $local->format('Y-m-d'), 'time' => $local->format('H:i')];

        $prefillStylist = Auth::user()->can('manageBookings', $this->salon) ? $stylistId : Auth::id();
        if ($prefillStylist !== null) {
            $params['stylist'] = $prefillStylist;
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
        $status = BookingStatus::from($to);
        $action->handle(Auth::user(), $this->salon, $booking, $status);

        unset($this->detail, $this->grid);

        Flux::toast(variant: 'success', text: __($status->actionToast()));
    }

    /**
     * Load a booking in this salon, enforcing that a stylist may only reach
     * bookings they are assigned to (mirrors the appointments screen).
     */
    private function booking(int $id): Booking
    {
        $booking = $this->salon->bookings()->whereKey($id)->firstOrFail();

        // Master viewers (managers + employee stylists on the shared board)
        // may open any booking; a booth renter only their own.
        abort_unless(
            $this->isMaster || $booking->items()->where('stylist_id', Auth::id())->exists(),
            403,
        );

        return $booking;
    }
}; ?>

@php($grid = $this->grid)
@php($ppm = 72 / 60)
@php($startMin = $grid['dayStartMin'])
@php($height = ($grid['dayEndMin'] - $startMin) * $ppm)
@php($colWidth = $grid['view'] === 'week' ? 'w-[150px]' : 'w-[208px]')

<div wire:poll.5s="refresh">
    <div class="mx-auto flex w-full max-w-[1400px] flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Calendar')" :title="$isMaster ? __('Master calendar') : __('My calendar')">
            <x-slot:actions>
                @can('createBookings', $salon)
                    <x-ui.button :href="route('salon.bookings.create', $salon)" wire:navigate>
                        <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add booking') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Toolbar --}}
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-[8px] bg-muted p-1">
                <button type="button" wire:click="setView('day')" aria-pressed="{{ $grid['view'] === 'day' ? 'true' : 'false' }}"
                        class="rounded-[6px] px-3 py-1 text-[14px] font-medium transition {{ $grid['view'] === 'day' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink' }}">{{ __('Day') }}</button>
                <button type="button" wire:click="setView('week')" aria-pressed="{{ $grid['view'] === 'week' ? 'true' : 'false' }}"
                        class="rounded-[6px] px-3 py-1 text-[14px] font-medium transition {{ $grid['view'] === 'week' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink' }}">{{ __('Week') }}</button>
            </div>

            <div class="inline-flex items-center gap-1">
                <button type="button" wire:click="prev" class="rounded-[8px] border border-input-border p-1.5 text-secondary transition hover:text-ink" aria-label="{{ __('Previous') }}">
                    <flux:icon.chevron-left variant="micro" />
                </button>
                <button type="button" wire:click="today" class="rounded-[8px] border border-input-border px-3 py-1.5 text-[14px] font-medium text-secondary transition hover:text-ink">{{ __('Today') }}</button>
                <button type="button" wire:click="next" class="rounded-[8px] border border-input-border p-1.5 text-secondary transition hover:text-ink" aria-label="{{ __('Next') }}">
                    <flux:icon.chevron-right variant="micro" />
                </button>
            </div>

            <div class="font-display text-[18px] font-bold text-ink">{{ $grid['rangeLabel'] ?? $grid['dateLabel'] }}</div>

            {{-- Stylist show/hide filter (day master view) --}}
            @if ($grid['view'] === 'day' && count($grid['columns']) > 1)
                <div class="ms-auto flex flex-wrap items-center gap-2">
                    @foreach ($grid['columns'] as $col)
                        @php($isHidden = in_array($col['stylistId'], $hidden, true))
                        {{-- Pressed = shown. A hidden stylist reads as a hollow,
                             muted chip (state is not colour/opacity alone). --}}
                        <button type="button" wire:click="toggleStylist({{ $col['stylistId'] }})"
                                aria-pressed="{{ $isHidden ? 'false' : 'true' }}"
                                class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[13px] font-medium transition {{ $isHidden ? 'border-dashed border-input-border bg-muted/50 text-faint' : 'border-input-border text-ink' }}">
                            <span class="size-2.5 rounded-full {{ $isHidden ? 'opacity-40' : '' }}" style="background-color: {{ $col['family']['avatar'] }}"></span>
                            {{ $col['name'] }}
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Column grid. Dims while a navigation/filter recompute is in flight
             (targets exclude the background poll, which must stay invisible). --}}
        @php($visible = collect($grid['columns'])->reject(fn ($c) => $grid['view'] === 'day' && in_array($c['stylistId'], $hidden, true))->values())
        <div wire:loading.class="pointer-events-none opacity-60" wire:target="setView, prev, next, today, toggleStylist"
             class="overflow-x-auto rounded-[18px] border border-border bg-card shadow-card transition-opacity">
            <div class="flex min-w-max">
                {{-- Time axis --}}
                <div class="sticky left-0 z-30 w-[64px] shrink-0 border-e border-divider bg-card">
                    <div class="h-[60px] border-b border-divider"></div>
                    <div class="relative" style="height: {{ $height }}px">
                        @foreach ($grid['hours'] as $hour)
                            <div class="absolute end-2 text-[12px] text-faint" style="top: {{ ($hour['min'] - $startMin) * $ppm - 7 }}px">{{ $hour['label'] }}</div>
                        @endforeach
                    </div>
                </div>

                @forelse ($visible as $col)
                    <div class="{{ $colWidth }} shrink-0 border-e border-divider last:border-e-0">
                        {{-- Header --}}
                        <div class="flex h-[60px] items-center gap-2.5 border-b border-divider px-3">
                            @if ($grid['view'] === 'day')
                                <x-ui.avatar :name="$col['name']" :seed="$col['stylistId']" size="sm" />
                                <span class="truncate text-[14px] font-semibold text-ink">{{ $col['name'] }}</span>
                            @else
                                <div class="{{ $col['isToday'] ? 'text-accent' : 'text-ink' }}">
                                    <div class="text-[13px] font-semibold uppercase tracking-wide">{{ $col['name'] }}</div>
                                    <div class="text-[12.5px] text-faint">{{ $col['sublabel'] }}</div>
                                </div>
                            @endif
                        </div>

                        {{-- Body --}}
                        <div class="relative">
                            {{-- Slot cells: shading (working = card, non-working = muted) + click-to-book --}}
                            <div class="flex flex-col">
                                @foreach ($col['slots'] as $slot)
                                    @if ($slot['bookable'] && $canCreate)
                                        <button type="button" wire:click="selectSlot('{{ $slot['iso'] }}', {{ $col['stylistId'] ?? 'null' }})"
                                                aria-label="{{ __('Book :who at :time', ['who' => $col['name'], 'time' => $slot['label']]) }}"
                                                class="group block w-full bg-card transition hover:bg-accent-tint/50" style="height: {{ 30 * $ppm }}px"></button>
                                    @else
                                        <div class="w-full bg-muted/45" style="height: {{ 30 * $ppm }}px"></div>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Hour gridlines --}}
                            @foreach ($grid['hours'] as $i => $hour)
                                @if ($i > 0)
                                    <div class="pointer-events-none absolute inset-x-0 border-t border-divider" style="top: {{ ($hour['min'] - $startMin) * $ppm }}px"></div>
                                @endif
                            @endforeach

                            {{-- Blocked: breaks + time off --}}
                            @foreach ($col['blocked'] as $bl)
                                <div class="pointer-events-none absolute inset-x-1 flex items-start justify-center overflow-hidden rounded-[8px] px-2 py-1"
                                     style="top: {{ ($bl['startMin'] - $startMin) * $ppm }}px; height: {{ ($bl['endMin'] - $bl['startMin']) * $ppm }}px; background-image: repeating-linear-gradient(45deg, rgba(107,104,98,.08), rgba(107,104,98,.08) 6px, rgba(107,104,98,.02) 6px, rgba(107,104,98,.02) 12px);">
                                    <span class="text-[12px] font-medium text-secondary">{{ $bl['label'] }}</span>
                                </div>
                            @endforeach

                            {{-- Appointment blocks. Concurrent bookings render in
                                 side-by-side lanes (leftPct/widthPct from
                                 CalendarData) so none is ever hidden behind
                                 another — a lone block keeps the full width. --}}
                            @foreach ($col['bookings'] as $b)
                                @php($dimmed = in_array($b['status'], ['completed', 'no_show', 'cancelled'], true))
                                @php($lane = 'left: calc('.$b['leftPct'].'% + 4px); width: calc('.$b['widthPct'].'% - 8px);')
                                {{-- Done/no-show blocks fade their FILL toward white
                                     (color-mix) so the ink text keeps AA contrast —
                                     never whole-block opacity, which dims the text. --}}
                                @php($blockBg = $dimmed ? "color-mix(in srgb, {$b['color']['bg']} 45%, white)" : $b['color']['bg'])
                                @php($blockBorder = $dimmed ? "color-mix(in srgb, {$b['color']['border']} 55%, white)" : $b['color']['border'])
                                <button type="button" wire:click="openBooking({{ $b['bookingId'] }})"
                                        class="absolute overflow-hidden rounded-[11px] border px-[11px] py-2 text-start transition hover:brightness-[.97]"
                                        style="{{ $lane }} top: {{ ($b['startMin'] - $startMin) * $ppm }}px; height: {{ max(28, ($b['endMin'] - $b['startMin']) * $ppm - 2) }}px; background-color: {{ $blockBg }}; border-color: {{ $blockBorder }}; color: {{ $b['color']['ink'] }};">
                                    <div class="text-[11px] font-semibold">{{ $b['startLabel'] }}–{{ $b['endLabel'] }}</div>
                                    <div class="truncate text-[13px] font-semibold leading-tight">{{ $b['client'] }}</div>
                                    <div class="truncate text-[12px] leading-tight">{{ $b['service'] }}</div>
                                </button>
                                {{-- Cleanup buffer: muted, non-bookable tail (same lane as its block). --}}
                                @if (($b['bufferMin'] ?? 0) > 0)
                                    <div class="pointer-events-none absolute rounded-b-[8px] border border-t-0 border-divider bg-muted/70"
                                         style="{{ $lane }} top: {{ ($b['endMin'] - $startMin) * $ppm }}px; height: {{ max(4, $b['bufferMin'] * $ppm) }}px;"
                                         aria-hidden="true"></div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="flex h-40 flex-1 items-center justify-center p-8 text-[15px] text-faint">
                        {{ __('No stylists to show. Adjust the filter or add stylists on the Staff page.') }}
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Booking detail panel --}}
    <x-ui.modal wire:model="showDetail" class="max-w-lg"
        :heading="$this->detail?->client->name"
        :subheading="$this->detail?->items->min('starts_at')?->setTimezone($salon->timezone)->format('l, M j · g:i A')">
        @if ($this->detail)
            @php($booking = $this->detail)
            @php($start = $booking->items->min('starts_at'))
            {{-- Status pills sit in the shared header's pill slot, so they stay
                 clear of the modal's close × at any status length. --}}
            <x-slot:pill>
                <x-ui.status-pill :status="$booking->status" />
                @if ($booking->is_walkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Walk-in') }}</span>@endif
                @if ($booking->ghl_sync_status === 'failed')
                    @can('manage', $salon)
                        <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;" title="{{ $booking->ghl_sync_error }}">{{ __('GoHighLevel sync failed') }}</span>
                    @endcan
                @endif
            </x-slot:pill>
            <div class="flex flex-col gap-6">
                {{-- Services: a flat, hairline-divided list — no inner box. --}}
                <div class="flex flex-col divide-y divide-row border-y border-divider">
                    @foreach ($booking->items as $item)
                        <div class="flex items-center justify-between py-2.5 text-[14px]">
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-full" style="background-color: {{ $item->service->palette()['dot'] }}"></span>
                                <span class="font-medium text-ink">{{ $item->service->name }}</span>
                                <span class="text-secondary">· {{ $item->stylist->name }}</span>
                                @if ($item->service->price_cents !== null)
                                    <span class="text-faint">· {{ $item->service->priceLabel($salon->currency) }}</span>
                                @endif
                            </div>
                            <span class="text-secondary">
                                {{ $item->starts_at?->setTimezone($salon->timezone)->format('g:i A') }}–{{ $item->ends_at?->setTimezone($salon->timezone)->format('g:i A') }}
                            </span>
                        </div>
                    @endforeach
                    @if ($booking->items->contains(fn ($item) => $item->service->price_cents !== null))
                        {{-- Informational estimate only — no payments in the app. --}}
                        <div class="flex items-center justify-between py-2.5 text-[13px]">
                            <span class="text-secondary">{{ __('Estimated price') }}</span>
                            <span class="font-medium text-ink">{{ \App\Support\Money::format($booking->items->sum(fn ($item) => $item->service->price_cents ?? 0), $salon->currency) }}</span>
                        </div>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-3 text-[14px]">
                    <div>
                        <div class="bts-overline">{{ __('Booked by') }}</div>
                        <div class="mt-1 text-ink">{{ $booking->bookedBy?->name ?? $booking->booked_by_type->label() }}</div>
                    </div>
                    <div>
                        <div class="bts-overline">{{ __('Source') }}</div>
                        <div class="mt-1 text-ink">{{ $booking->source->label() }}</div>
                    </div>
                </div>

                @if ($booking->notes)
                    <div class="text-[14px]">
                        <div class="bts-overline">{{ __('Notes') }}</div>
                        <div class="mt-1 text-ink">{{ $booking->notes }}</div>
                    </div>
                @endif

                {{-- Status actions (reuses the Phase 3 transition flow). Check-in
                     is front-desk level — owner/admin/front-desk only; stylists
                     never see these (the server rejects them regardless). --}}
                @php($canActOnBooking = Auth::user()->can('manageBookings', $salon) || (Auth::user()->boothRenterMembershipFor($salon) !== null && $booking->items()->where('stylist_id', Auth::id())->exists()))
                @if ($canActOnBooking)
                    @if ($booking->status->allowedTransitions() !== [])
                        <div class="flex flex-wrap gap-2 border-t border-divider pt-4">
                            @foreach ($booking->status->allowedTransitions() as $next)
                                @if ($next === \App\Enums\BookingStatus::Arrived)
                                    <x-ui.button size="sm" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ __($next->actionLabel()) }}</x-ui.button>
                                @elseif ($next === \App\Enums\BookingStatus::Cancelled)
                                    {{-- Themed confirm (replaces wire:confirm) — the confirm <dialog> top-layers above this detail dialog. --}}
                                    <button type="button" x-on:click="$store.confirm.ask({ title: {{ Js::from(__($next->actionLabel())) }}, message: {{ Js::from(__($next->confirmMessage())) }}, confirmLabel: {{ Js::from(__($next->actionLabel())) }}, danger: true }, () => $wire.changeStatus({{ $booking->id }}, {{ Js::from($next->value) }}))" class="bts-btn bts-btn-sm border border-input-border bg-card text-danger hover:border-danger">{{ __($next->actionLabel()) }}</button>
                                @elseif ($next->confirmMessage())
                                    <x-ui.button size="sm" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__($next->actionLabel())) }}, message: {{ Js::from(__($next->confirmMessage())) }}, confirmLabel: {{ Js::from(__($next->actionLabel())) }}, danger: true }, () => $wire.changeStatus({{ $booking->id }}, {{ Js::from($next->value) }}))">{{ __($next->actionLabel()) }}</x-ui.button>
                                @else
                                    <x-ui.button size="sm" variant="secondary" wire:click="changeStatus({{ $booking->id }}, '{{ $next->value }}')">{{ __($next->actionLabel()) }}</x-ui.button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- History --}}
                <div class="border-t border-divider pt-4">
                    <div class="bts-overline mb-2">{{ __('History') }}</div>
                    <div class="flex flex-col gap-2">
                        @foreach ($booking->statusEvents->sortBy('created_at') as $event)
                            <div class="flex items-center justify-between gap-3 text-[14px]">
                                <x-ui.status-pill :status="$event->to_status" />
                                <span class="flex-1 truncate text-secondary">{{ $event->actor?->name }}</span>
                                <span class="text-[12.5px] text-faint">{{ $event->created_at?->setTimezone($salon->timezone)->format('M j, g:i A') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </x-ui.modal>
</div>
