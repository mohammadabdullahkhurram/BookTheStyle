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

new #[Title('Check-in')] class extends Component {
    public Salon $salon;
    public string $search = '';

    public bool $showTimeline = false;
    public ?int $timelineId = null;

    public function mount(Salon $salon): void
    {
        // Check-in / status management is owner / admin / front-desk only.
        // Stylists are denied the appointments screen outright (no status edits).
        $this->authorize('manageBookings', $salon);
        $this->salon = $salon;
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    #[Computed]
    public function bookings()
    {
        // Strictly TODAY — check-in is the live "who's in today" tool.
        // Computed per request, so the view rolls over at midnight by itself.
        $dayStart = CarbonImmutable::now($this->salon->timezone)->startOfDay();
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

    /**
     * ONE BLOCK PER CLIENT: today's bookings grouped by client, each block
     * carrying every service line (time · service · stylist) plus the
     * aggregate flags the action row is built from. A client with two
     * separately-booked visits today is still one block — never two rows.
     */
    #[Computed]
    public function clientBlocks()
    {
        $active = [BookingStatus::Booked, BookingStatus::Confirmed];

        return $this->bookings
            ->groupBy('client_id')
            ->map(function ($group) use ($active) {
                $bookings = $group->sortBy(fn (Booking $b) => $b->items->min('starts_at'))->values();
                $statuses = $bookings->pluck('status');

                return (object) [
                    'client' => $bookings->first()->client,
                    'bookings' => $bookings,
                    'start' => $bookings->first()->items->min('starts_at'),
                    'hasBooked' => $statuses->contains(fn (BookingStatus $s) => in_array($s, $active, true)),
                    'hasArrived' => $statuses->contains(BookingStatus::Arrived),
                    'hasNoShow' => $statuses->contains(BookingStatus::NoShow),
                    'isWalkin' => $bookings->contains(fn (Booking $b) => $b->is_walkin),
                    'syncFailed' => $bookings->contains(fn (Booking $b) => $b->ghl_sync_status === 'failed'),
                    // The header pill: the most action-relevant state wins.
                    'overall' => match (true) {
                        $statuses->contains(BookingStatus::Arrived) => BookingStatus::Arrived,
                        $statuses->contains(BookingStatus::InService) => BookingStatus::InService,
                        $statuses->contains(fn (BookingStatus $s) => in_array($s, $active, true)) => BookingStatus::Booked,
                        $statuses->contains(BookingStatus::NoShow) => BookingStatus::NoShow,
                        $statuses->contains(BookingStatus::Completed) => BookingStatus::Completed,
                        default => BookingStatus::Cancelled,
                    },
                    'closed' => $statuses->every(fn (BookingStatus $s) => in_array($s, [BookingStatus::Completed, BookingStatus::Cancelled, BookingStatus::NoShow], true)),
                ];
            })
            ->sortBy('start')
            ->values();
    }

    /** This client's TODAY bookings currently in one of the given states. */
    private function clientBookingsIn(int $clientId, array $statuses)
    {
        return $this->bookings
            ->where('client_id', $clientId)
            ->filter(fn (Booking $b) => in_array($b->status, $statuses, true));
    }

    /**
     * Block-level transition: the client arrived / didn't / cancelled —
     * applied to every one of their today bookings the move is valid for.
     */
    private function transitionClient(int $clientId, array $from, BookingStatus $to, TransitionBookingStatus $action): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Status changes are disabled in the demo.'))) {
            return;
        }

        $bookings = $this->clientBookingsIn($clientId, $from);
        abort_if($bookings->isEmpty(), 404);

        foreach ($bookings as $booking) {
            $action->handle(Auth::user(), $this->salon, $this->booking($booking->id), $to);
        }

        unset($this->bookings, $this->clientBlocks);
        Flux::toast(variant: 'success', text: __($to->actionToast()));
    }

    public function checkInClient(int $clientId, TransitionBookingStatus $action): void
    {
        $this->transitionClient($clientId, [BookingStatus::Booked, BookingStatus::Confirmed], BookingStatus::Arrived, $action);
    }

    public function markNoShowClient(int $clientId, TransitionBookingStatus $action): void
    {
        $this->transitionClient($clientId, [BookingStatus::Booked, BookingStatus::Confirmed], BookingStatus::NoShow, $action);
    }

    public function cancelClient(int $clientId, TransitionBookingStatus $action): void
    {
        $this->transitionClient($clientId, [BookingStatus::Booked, BookingStatus::Confirmed, BookingStatus::Arrived, BookingStatus::InService], BookingStatus::Cancelled, $action);
    }

    public function undoNoShowClient(int $clientId, TransitionBookingStatus $action): void
    {
        $this->transitionClient($clientId, [BookingStatus::NoShow], BookingStatus::Booked, $action);
    }

    public function changeStatus(int $bookingId, string $to, TransitionBookingStatus $action): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Status changes are disabled in the demo.'))) {
            return;
        }

        $booking = $this->booking($bookingId);
        $status = BookingStatus::from($to);
        $action->handle(Auth::user(), $this->salon, $booking, $status);
        unset($this->bookings);

        Flux::toast(variant: 'success', text: __($status->actionToast()));
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


    // --- Check out (payments placeholder) ---------------------------------

    /** The "coming soon" checkout popup — no payment logic exists yet. */
    public bool $showCheckout = false;

    public ?int $checkoutClientId = null;

    public function openCheckout(int $clientId): void
    {
        $this->salon->clients()->whereKey($clientId)->firstOrFail(); // authorise scope
        $this->checkoutClientId = $clientId;
        $this->showCheckout = true;
    }

    /** Whether the checkout client has checked-in visits to complete. */
    #[Computed]
    public function checkoutCanComplete(): bool
    {
        return $this->checkoutClientId !== null
            && $this->clientBookingsIn($this->checkoutClientId, [BookingStatus::Arrived])->isNotEmpty();
    }

    /** Close the visit without payment: every Checked in → Completed. */
    public function completeVisit(TransitionBookingStatus $action): void
    {
        $clientId = (int) $this->checkoutClientId;
        $this->transitionClient($clientId, [BookingStatus::Arrived], BookingStatus::Completed, $action);

        $this->showCheckout = false;
        $this->checkoutClientId = null;
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
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Today')" :title="__('Check-in')">
            <x-slot:actions>
                @can('manageBookings', $salon)
                    <x-ui.button :href="route('salon.bookings.create', $salon)" wire:navigate>
                        <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add booking') }}
                    </x-ui.button>
                @endcan
            </x-slot:actions>
        </x-ui.page-header>

        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search today\'s clients')" :label="__('Search')" class="max-w-64" />
        </div>

        <div wire:loading.class="pointer-events-none opacity-60" wire:target="search"
             class="flex flex-col gap-3 transition-opacity">
            @forelse ($this->clientBlocks as $block)
                @php($seed = $block->bookings->first()->items->first()?->stylist_id ?? 0)
                <x-ui.card padding="p-5" class="{{ $block->closed ? '!bg-paper' : '' }}" wire:key="client-{{ $block->client->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-start gap-4">
                            <div class="w-16 shrink-0 pt-0.5 text-[14px] font-medium text-faint">{{ $block->start?->setTimezone($salon->timezone)->format('g:i A') }}</div>
                            <x-ui.avatar :name="$block->client->name" :seed="$seed" size="sm" class="mt-0.5" />
                            <div class="flex flex-col gap-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($block->client->trashed())
                                        <span class="text-[15px] font-semibold text-ink">{{ $block->client->name }} <span class="font-normal text-faint">{{ __('(removed)') }}</span></span>
                                    @else
                                        <a href="{{ route('salon.client', ['salon' => $salon, 'clientId' => $block->client->id]) }}" wire:navigate class="text-[15px] font-semibold text-ink transition hover:text-accent">{{ $block->client->name }}</a>
                                    @endif
                                    <x-ui.status-pill :status="$block->overall" />
                                    @if ($block->isWalkin)<span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Walk-in') }}</span>@endif
                                    @if ($block->client->is_test)<span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;" title="{{ __('Created by a connection check or widget preview — never synced to GHL, swept automatically.') }}">{{ __('TEST') }}</span>@endif
                                    @can('manage', $salon)
                                        @if ($block->syncFailed)
                                            <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;">{{ __('Sync failed') }}</span>
                                        @endif
                                    @endcan
                                </div>
                                {{-- EVERY service the client has today, one line each —
                                     a client with several bookings is still THIS one
                                     block. Per-visit pills appear only when the visits
                                     are in different states. --}}
                                <div class="flex flex-col gap-1 text-[14px] text-secondary">
                                    @foreach ($block->bookings as $booking)
                                        @foreach ($booking->items->sortBy('starts_at') as $item)
                                            <div class="flex flex-wrap items-center gap-1.5" wire:key="line-{{ $item->id }}">
                                                <span class="w-16 shrink-0 text-[12.5px] text-faint">{{ $item->starts_at->setTimezone($salon->timezone)->format('g:i A') }}</span>
                                                <span class="size-2 rounded-full" style="background-color: {{ $item->service->palette()['dot'] }}"></span>
                                                <span>{{ $item->service->name }}@if ($item->service->trashed()) {{ __('(removed)') }}@endif · {{ $item->stylist->name }}@if ($item->stylist->trashed()) {{ __('(removed)') }}@endif</span>
                                                @if ($block->bookings->count() > 1 && $loop->first && $booking->status !== $block->overall)
                                                    <x-ui.status-pill :status="$booking->status" />
                                                @endif
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                                <div class="text-[12.5px] text-faint">{{ __('Booked by') }} {{ $block->bookings->first()->bookedBy?->name ?? $block->bookings->first()->booked_by_type->label() }} · {{ $block->bookings->map(fn ($b) => $b->source->label())->unique()->join(', ') }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col items-end gap-2">
                            {{-- Client-level visit flow (no rescheduling here — that
                                 lives on Calendar/Appointments). Check out is on EVERY
                                 block and stays LAST, so the payment flow slots in
                                 behind it later with no layout change. --}}
                            <div class="flex flex-wrap justify-end gap-2">
                                @if ($block->hasBooked)
                                    <x-ui.button size="sm" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Mark no-show')) }}, message: {{ Js::from(__(\App\Enums\BookingStatus::NoShow->confirmMessage())) }}, confirmLabel: {{ Js::from(__('Mark no-show')) }}, danger: true }, () => $wire.markNoShowClient({{ $block->client->id }}))">{{ __('Mark no-show') }}</x-ui.button>
                                @endif
                                @if ($block->hasBooked || $block->hasArrived)
                                    <x-ui.button size="sm" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Cancel booking')) }}, message: {{ Js::from(__(\App\Enums\BookingStatus::Cancelled->confirmMessage())) }}, confirmLabel: {{ Js::from(__('Cancel booking')) }}, danger: true }, () => $wire.cancelClient({{ $block->client->id }}))">{{ __('Cancel booking') }}</x-ui.button>
                                @endif
                                @if ($block->hasBooked)
                                    <x-ui.button size="sm" variant="secondary" wire:click="checkInClient({{ $block->client->id }})">{{ __('Check in') }}</x-ui.button>
                                @endif
                                @if (! $block->hasBooked && ! $block->hasArrived && $block->hasNoShow)
                                    <x-ui.button size="sm" variant="secondary" wire:click="undoNoShowClient({{ $block->client->id }})">{{ __('Undo no-show') }}</x-ui.button>
                                @endif
                                <x-ui.button size="sm" wire:click="openCheckout({{ $block->client->id }})">{{ __('Check out') }}</x-ui.button>
                            </div>
                            <div class="flex items-center gap-3">
                                @foreach ($block->bookings as $booking)
                                    <button type="button" wire:click="openTimeline({{ $booking->id }})" class="text-[13px] font-medium text-secondary transition hover:text-accent">{{ $block->bookings->count() > 1 ? __('History :n', ['n' => $loop->iteration]) : __('History') }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </x-ui.card>
            @empty
                <x-ui.card padding="p-10" class="text-center text-[15px] text-faint">
                    {{ __('No appointments today.') }}
                </x-ui.card>
            @endforelse
        </div>
    </div>

    {{-- Check out: the payments PLACEHOLDER — no payment logic exists.
         "Mark visit complete" is the only real action (Arrived → Completed);
         the checkout/payment flow plugs into this modal later. --}}
    <x-ui.modal wire:model="showCheckout" class="max-w-md" :heading="__('Check out')">
        <div class="flex flex-col gap-4">
            <div class="flex items-start gap-3 rounded-[12px] border border-divider bg-muted/40 px-4 py-3">
                <flux:icon.credit-card variant="mini" class="mt-0.5 shrink-0 text-faint" />
                <div>
                    <p class="text-[14px] font-semibold text-ink">{{ __('In-app payments are coming soon') }}</p>
                    <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Taking payment at check-out — card, cash, totals and receipts — is on the way. For now, take payment the way you usually do and mark the visit complete.') }}</p>
                </div>
            </div>
            <div class="flex items-center justify-end gap-2">
                <x-ui.button variant="secondary" wire:click="$set('showCheckout', false)">{{ __('Close') }}</x-ui.button>
                @if ($this->checkoutCanComplete)
                    <x-ui.button wire:click="completeVisit" loading="completeVisit">{{ __('Mark visit complete') }}</x-ui.button>
                @endif
            </div>
        </div>
    </x-ui.modal>

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
