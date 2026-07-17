<?php

use App\Actions\Bookings\CreateBooking;
use App\Models\Salon;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The staff-operated new-booking flow: client → service lines → date →
 * REAL availability. Every line is one service + one deliberately chosen
 * stylist + one clickable start time computed by the slot engine — no time
 * is ever typed, no invalid pick is ever offered, and there is no "any
 * available" auto-assignment. Multi-service and multi-stylist are both just
 * extra lines. The engine's slots are filtered against the OTHER lines'
 * in-form picks for the same stylist (the engine only knows persisted
 * bookings), and follow-on lines default to back-to-back after the previous
 * pick — adjustable per line. CreateBooking remains the source of truth and
 * re-validates everything under lock on save.
 */
new #[Title('New booking')] class extends Component {
    public Salon $salon;

    public string $clientMode = 'existing';
    public string $clientSearch = '';
    public ?int $clientId = null;
    public string $newName = '';
    public string $newPhone = '';
    public string $newEmail = '';

    /** @var array<int, array{service_id: string, stylist_id: string, time: string}> */
    public array $items = [];

    public string $date = '';
    public bool $isWalkin = false;
    public string $notes = '';

    public function mount(Salon $salon): void
    {
        // Managers book anyone; a BOOTH-RENTING stylist books their own
        // clients (CreateBooking pins every item to them). Employee stylists
        // never book — the desk does.
        $this->authorize('createBookings', $salon);
        $this->salon = $salon;
        $this->date = CarbonImmutable::now($salon->timezone)->format('Y-m-d');
        $this->items = [$this->blankLine()];

        // A stylist booking their own visit is locked to themselves.
        if ($this->lockedStylistId() !== null) {
            $this->items[0]['stylist_id'] = (string) $this->lockedStylistId();
        }

        // Click-to-book prefill from the calendar (date/time, and stylist for
        // a manager). Conveniences only — CreateBooking re-validates
        // everything, so a stale value can never bypass the slot engine.
        $request = request();
        if ($request->filled('date')) {
            $this->date = $request->date('date')?->format('Y-m-d') ?? $this->date;
        }
        if ($request->filled('time') && preg_match('/^\d{2}:\d{2}$/', (string) $request->query('time'))) {
            $this->items[0]['time'] = (string) $request->query('time');
        }
        if ($this->lockedStylistId() === null && $request->filled('stylist')) {
            $this->items[0]['stylist_id'] = (string) (int) $request->query('stylist');
        }
    }

    /** @return array{service_id: string, stylist_id: string, time: string} */
    private function blankLine(): array
    {
        return ['service_id' => '', 'stylist_id' => $this->lockedStylistId() !== null ? (string) $this->lockedStylistId() : '', 'time' => ''];
    }

    /** The signed-in user's id when they can only book themselves. */
    private function lockedStylistId(): ?int
    {
        $user = Auth::user();

        return ! $user->can('manageBookings', $this->salon) && $user->stylistMembershipFor($this->salon) !== null
            ? (int) $user->id
            : null;
    }

    #[Computed]
    public function services()
    {
        return $this->salon->services()->where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function clientResults()
    {
        $term = trim($this->clientSearch);

        return $this->salon->clients()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")))
            ->orderBy('name')->limit(20)->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    /**
     * Qualified stylists for a service, each with THEIR resolved
     * client-facing minutes (per-stylist durations) for the dropdown label.
     *
     * @return list<array{id: int, name: string, minutes: int}>
     */
    public function stylistsFor(string $serviceId): array
    {
        if ($serviceId === '') {
            return [];
        }

        $service = $this->salon->services()->where('active', true)->whereKey($serviceId)->first();
        if ($service === null) {
            return [];
        }

        $resolver = app(DurationResolver::class);
        $activeIds = $this->salon->stylistUsers()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        // A booth renter books only their own column; managers book anyone.
        if (! Auth::user()->can('manageBookings', $this->salon)) {
            $activeIds = array_values(array_intersect($activeIds, [Auth::id()]));
        }

        return $service->stylists()
            ->orderBy('name')
            ->get(['users.id', 'name'])
            ->filter(fn ($stylist) => in_array((int) $stylist->id, $activeIds, true))
            ->map(fn ($stylist): array => [
                'id' => (int) $stylist->id,
                'name' => $stylist->name,
                'minutes' => $resolver->resolve($this->salon, $service, (int) $stylist->id)->clientFacingMinutes(),
            ])
            ->values()
            ->all();
    }

    /**
     * The REAL bookable start times for one line: the slot engine's slots for
     * that line's stylist + resolved duration on the chosen date, minus any
     * overlap with the OTHER lines' in-form picks for the same stylist
     * (which the engine cannot know about yet).
     *
     * @return list<string> 'H:i' start times in the salon timezone
     */
    public function slotsForLine(int $index): array
    {
        $line = $this->items[$index] ?? null;
        if ($line === null || $line['service_id'] === '' || $line['stylist_id'] === '' || $this->date === '' || $this->isWalkin) {
            return [];
        }

        $service = $this->salon->services()->where('active', true)->whereKey($line['service_id'])->first();
        if ($service === null) {
            return [];
        }

        $stylistId = (int) $line['stylist_id'];
        $blocked = app(DurationResolver::class)->resolve($this->salon, $service, $stylistId)->blockedMinutes();
        $tz = $this->salon->timezone;

        // Windows this submission already occupies for the same stylist.
        $taken = [];
        foreach ($this->items as $otherIndex => $other) {
            if ($otherIndex === $index || (int) $other['stylist_id'] !== $stylistId || $other['time'] === '' || $other['service_id'] === '') {
                continue;
            }
            $otherService = $this->salon->services()->whereKey($other['service_id'])->first();
            if ($otherService === null) {
                continue;
            }
            $otherStart = CarbonImmutable::parse("{$this->date} {$other['time']}", $tz);
            $taken[] = [$otherStart, $otherStart->addMinutes(app(DurationResolver::class)->resolve($this->salon, $otherService, $stylistId)->blockedMinutes())];
        }

        $out = [];
        foreach (app(SlotEngine::class)->slotsFor($this->salon, $stylistId, $blocked, $this->date) as $slot) {
            $end = $slot->addMinutes($blocked);
            foreach ($taken as [$takenStart, $takenEnd]) {
                if ($slot->lt($takenEnd) && $end->gt($takenStart)) {
                    continue 2; // overlaps an in-form pick for this stylist
                }
            }
            $out[] = $slot->setTimezone($tz)->format('H:i');
        }

        return $out;
    }

    /**
     * Line rows for the review summary — only fully specified lines. Price is
     * informational (display only; null = price varies).
     *
     * @return list<array{service: string, stylist: string, time: string, end: string, minutes: int, price_cents: int|null, price: string|null}>
     */
    #[Computed]
    public function summary(): array
    {
        if ($this->date === '') {
            return [];
        }

        $resolver = app(DurationResolver::class);
        $names = $this->salon->stylistUsers()->pluck('name', 'users.id');
        $rows = [];

        foreach ($this->items as $line) {
            if ($line['service_id'] === '' || $line['stylist_id'] === '' || ($line['time'] === '' && ! $this->isWalkin)) {
                continue;
            }
            $service = $this->salon->services()->whereKey($line['service_id'])->first();
            if ($service === null) {
                continue;
            }
            $minutes = $resolver->resolve($this->salon, $service, (int) $line['stylist_id'])->clientFacingMinutes();
            $start = $this->isWalkin
                ? CarbonImmutable::now($this->salon->timezone)
                : CarbonImmutable::parse("{$this->date} {$line['time']}", $this->salon->timezone);

            $rows[] = [
                'service' => $service->name,
                'stylist' => (string) ($names[(int) $line['stylist_id']] ?? ''),
                'time' => $start->format('g:i A'),
                'end' => $start->addMinutes($minutes)->format('g:i A'),
                'minutes' => $minutes,
                'price_cents' => $service->price_cents,
                'price' => $service->priceLabel($this->salon->currency),
            ];
        }

        return $rows;
    }

    public function addItem(): void
    {
        $this->items[] = $this->blankLine();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        if ($this->items === []) {
            $this->items = [$this->blankLine()];
        }
    }

    /**
     * Keep picks coherent as selections change: a new service resets that
     * line's stylist + time, a new stylist resets its time, and a new date
     * resets every picked time (the old ones may no longer exist).
     */
    public function updated(string $property): void
    {
        if ($property === 'date' || $property === 'isWalkin') {
            foreach ($this->items as $i => $line) {
                $this->items[$i]['time'] = '';
            }

            return;
        }

        if (preg_match('/^items\.(\d+)\.(service_id|stylist_id)$/', $property, $m) === 1) {
            $index = (int) $m[1];
            $line = $this->items[$index];

            // A stylist no longer qualified for the new service is cleared
            // (a still-qualified one survives, so calendar prefills work).
            if ($m[2] === 'service_id' && $line['stylist_id'] !== '' && $this->lockedStylistId() === null) {
                $stillQualified = collect($this->stylistsFor($line['service_id']))
                    ->contains(fn (array $stylist): bool => $stylist['id'] === (int) $line['stylist_id']);

                if (! $stillQualified) {
                    $this->items[$index]['stylist_id'] = '';
                }
            }

            // A picked time that is no longer among the line's real slots
            // (different duration, different stylist) is cleared.
            if ($this->items[$index]['time'] !== '' && ! in_array($this->items[$index]['time'], $this->slotsForLine($index), true)) {
                $this->items[$index]['time'] = '';
            }
        }
    }

    /**
     * Pick a start time for one line, then default any still-empty later
     * lines to back-to-back after the latest pick (adjustable per line).
     */
    public function pickTime(int $index, string $time): void
    {
        if (! isset($this->items[$index])) {
            return;
        }

        $this->items[$index]['time'] = $time;
        $this->defaultFollowingLines($index);
    }

    private function defaultFollowingLines(int $fromIndex): void
    {
        $tz = $this->salon->timezone;
        $resolver = app(DurationResolver::class);

        $previous = $this->items[$fromIndex];
        $previousService = $this->salon->services()->whereKey($previous['service_id'])->first();
        if ($previousService === null || $previous['time'] === '') {
            return;
        }

        $cursor = CarbonImmutable::parse("{$this->date} {$previous['time']}", $tz)
            ->addMinutes($resolver->resolve($this->salon, $previousService, (int) $previous['stylist_id'])->blockedMinutes());

        foreach ($this->items as $i => $line) {
            if ($i <= $fromIndex || $line['time'] !== '' || $line['service_id'] === '' || $line['stylist_id'] === '') {
                continue;
            }

            foreach ($this->slotsForLine($i) as $candidate) {
                $candidateAt = CarbonImmutable::parse("{$this->date} {$candidate}", $tz);
                if ($candidateAt->gte($cursor)) {
                    $this->items[$i]['time'] = $candidate;

                    $service = $this->salon->services()->whereKey($line['service_id'])->first();
                    if ($service !== null) {
                        $cursor = $candidateAt->addMinutes($resolver->resolve($this->salon, $service, (int) $line['stylist_id'])->blockedMinutes());
                    }

                    break;
                }
            }
        }
    }

    public function save(CreateBooking $action): void
    {
        $this->authorize('createBookings', $this->salon);

        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required'],
            'items.*.stylist_id' => ['required'],
        ];
        if (! $this->isWalkin) {
            $rules['date'] = ['required', 'date'];
            $rules['items.*.time'] = ['required', 'date_format:H:i'];
        }
        if ($this->clientMode === 'existing') {
            $rules['clientId'] = ['required'];
        } else {
            $rules['newName'] = ['required', 'string', 'max:255'];
        }
        $this->validate($rules, [
            'items.*.time.required' => __('Pick a start time for every service.'),
            'items.*.stylist_id.required' => __('Choose a stylist for every service.'),
        ]);

        $client = $this->clientMode === 'existing'
            ? ['id' => $this->clientId]
            : ['name' => $this->newName, 'phone' => $this->newPhone ?: null, 'email' => $this->newEmail ?: null];

        $items = collect($this->items)
            ->filter(fn ($line) => $line['service_id'] !== '')
            ->map(fn ($line) => [
                'service_id' => (int) $line['service_id'],
                'stylist_id' => (int) $line['stylist_id'],
                'start' => $this->isWalkin || $line['time'] === '' ? null : "{$this->date} {$line['time']}",
            ])
            ->values();

        $earliest = $items->pluck('start')->filter()->sort()->first();

        $booking = $action->handle(Auth::user(), $this->salon, [
            'client' => $client,
            'items' => $items->all(),
            'start' => $this->isWalkin ? null : $earliest,
            'is_walkin' => $this->isWalkin,
            'notes' => $this->notes ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Booking created.'));
        $this->redirectRoute('salon.appointments', $this->salon, navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('New booking')" :title="__('Create a booking')" />

        @error('start') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
        @error('items') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
        @error('client') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror

        <form wire:submit="save" class="flex flex-col gap-6" novalidate>
            {{-- Client — first and prominent. --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Client') }}</h2>
                <flux:radio.group wire:model.live="clientMode" variant="segmented">
                    <flux:radio value="existing" label="{{ __('Existing') }}" />
                    <flux:radio value="new" label="{{ __('Quick add') }}" />
                </flux:radio.group>

                @if ($clientMode === 'existing')
                    <flux:input wire:model.live.debounce.300ms="clientSearch" icon="magnifying-glass" :placeholder="__('Search by name, phone, or email')" />
                    @if ($clientId)
                        <a href="{{ route('salon.client', ['salon' => $salon, 'clientId' => $clientId]) }}" wire:navigate class="self-start text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('View client profile') }}</a>
                    @endif
                    <flux:select wire:model="clientId" :label="__('Select client')">
                        <flux:select.option value="">{{ __('— choose —') }}</flux:select.option>
                        @foreach ($this->clientResults as $client)
                            <flux:select.option value="{{ $client->id }}">{{ $client->name }} {{ $client->phone ? "({$client->phone})" : '' }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="newName" :label="__('Name')" />
                        <flux:input wire:model="newPhone" :label="__('Phone')" />
                        <flux:input wire:model="newEmail" type="email" :label="__('Email (optional)')" />
                    </div>
                @endif
            </x-ui.card>

            {{-- Date + service lines, each with its own real availability. --}}
            <x-ui.card class="flex flex-col gap-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <h2 class="bts-card-title">{{ __('Services and times') }}</h2>
                    @if ($salon->allow_walkins)
                        <flux:checkbox wire:model.live="isWalkin" :label="__('Walk-in — start now and check in')" />
                    @endif
                </div>

                @unless ($isWalkin)
                    <div class="max-w-56">
                        <flux:input type="date" wire:model.live="date" :label="__('Date')" />
                    </div>
                @endunless

                <div class="flex flex-col gap-4">
                    @foreach ($items as $i => $item)
                        <div class="flex flex-col gap-3 rounded-[11px] border border-border bg-paper p-4" wire:key="line-{{ $i }}">
                            <div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                                <flux:select wire:model.live="items.{{ $i }}.service_id" :label="__('Service')">
                                    <flux:select.option value="">{{ __('— choose —') }}</flux:select.option>
                                    @foreach ($this->services as $service)
                                        <flux:select.option value="{{ $service->id }}">{{ $service->name }}{{ $service->price_cents !== null ? ' · '.$service->priceLabel($salon->currency) : '' }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model.live="items.{{ $i }}.stylist_id" :label="__('Stylist')" :disabled="! $this->canManage">
                                    <flux:select.option value="">{{ __('— choose —') }}</flux:select.option>
                                    @foreach ($this->stylistsFor($item['service_id']) as $stylist)
                                        <flux:select.option value="{{ $stylist['id'] }}">{{ $stylist['name'] }} · {{ $stylist['minutes'] }} {{ __('min') }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @if (count($items) > 1)
                                    <flux:button type="button" variant="subtle" size="sm" wire:click="removeItem({{ $i }})" icon="trash" :aria-label="__('Remove service')" />
                                @else
                                    <div></div>
                                @endif
                            </div>

                            @unless ($isWalkin)
                                @if ($item['service_id'] !== '' && $item['stylist_id'] !== '')
                                    @php($slots = $this->slotsForLine($i))
                                    @if ($slots === [])
                                        <p class="text-[13.5px] font-medium text-[#8A5A1E]">
                                            {{ __('No open times for this stylist on this date. Try another date or stylist.') }}
                                        </p>
                                    @else
                                        <div>
                                            <div class="bts-field-label mb-2">{{ __('Available start times') }}</div>
                                            <div class="flex flex-wrap gap-2">
                                                @foreach ($slots as $slot)
                                                    <button type="button" wire:click="pickTime({{ $i }}, '{{ $slot }}')"
                                                            aria-pressed="{{ $item['time'] === $slot ? 'true' : 'false' }}"
                                                            class="rounded-[9px] border px-3 py-1.5 text-[14px] font-medium transition {{ $item['time'] === $slot ? 'border-accent bg-accent-tint text-accent-ink' : 'border-input-border bg-field text-body hover:border-faint' }}">
                                                        {{ $slot }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <p class="text-[13px] text-faint">{{ __('Choose a service and stylist to see open times.') }}</p>
                                @endif
                            @endunless
                        </div>
                    @endforeach
                </div>

                <div>
                    <button type="button" wire:click="addItem"
                            class="inline-flex items-center gap-1.5 rounded-[9px] px-2.5 py-1.5 text-[14px] font-semibold text-accent transition hover:bg-accent-tint">
                        <flux:icon.plus variant="micro" />{{ __('Add service') }}
                    </button>
                </div>
            </x-ui.card>

            {{-- Review + confirm. --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Review') }}</h2>

                @if ($this->summary === [])
                    <p class="text-[13.5px] text-faint">{{ __('The summary appears once every service has a stylist and a time.') }}</p>
                @else
                    <div class="flex flex-col divide-y divide-row rounded-[11px] border border-border">
                        @foreach ($this->summary as $row)
                            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-[14px]">
                                <span class="font-medium text-ink">{{ $row['service'] }}</span>
                                <span class="text-secondary">{{ $row['stylist'] }}</span>
                                <span class="text-body">{{ $isWalkin ? __('Now') : $row['time'].' – '.$row['end'] }} · {{ $row['minutes'] }} {{ __('min') }}@if ($row['price'] !== null) · {{ $row['price'] }}@endif</span>
                            </div>
                        @endforeach
                        <div class="flex items-center justify-between px-4 py-2.5 text-[14px]">
                            <span class="font-semibold text-ink">{{ __('Total') }}</span>
                            <span class="font-semibold text-ink">
                                {{ collect($this->summary)->sum('minutes') }} {{ __('min') }}@if (collect($this->summary)->whereNotNull('price_cents')->isNotEmpty()) · {{ \App\Support\Money::format(collect($this->summary)->sum('price_cents'), $salon->currency) }} {{ __('est.') }}@endif
                            </span>
                        </div>
                    </div>
                @endif

                <flux:textarea wire:model="notes" :label="__('Notes (optional)')" rows="2" />

                <div class="flex items-center gap-3">
                    <x-ui.button type="submit" loading="save">{{ __('Create booking') }}</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('salon.show', $salon)" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                </div>
            </x-ui.card>
        </form>
    </div>
</div>
