<?php

use App\Actions\Bookings\CreateBooking;
use App\Models\Salon;
use App\Models\Service;
use App\Services\Booking\DurationResolver;
use App\Services\Booking\SlotEngine;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New booking')] class extends Component {
    public Salon $salon;

    public string $clientMode = 'existing';
    public string $clientSearch = '';
    public ?int $clientId = null;
    public string $newName = '';
    public string $newPhone = '';
    public string $newEmail = '';

    /** @var array<int, array{service_id: string, stylist_id: string}> */
    public array $items = [];

    public string $date = '';
    public string $startTime = '';
    public bool $isWalkin = false;
    public string $notes = '';

    public function mount(Salon $salon): void
    {
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;
        $this->date = CarbonImmutable::now($salon->timezone)->format('Y-m-d');
        $this->items = [['service_id' => '', 'stylist_id' => '']];

        // A stylist booking their own visit is locked to themselves.
        $isStylist = ! Auth::user()->can('manageBookings', $salon) && Auth::user()->stylistMembershipFor($salon);
        if ($isStylist) {
            $this->items = [['service_id' => '', 'stylist_id' => (string) Auth::id()]];
        }

        // Click-to-book prefill from the calendar (date/time, and stylist for a
        // manager). Conveniences only — CreateBooking re-validates everything,
        // so a stale or hand-edited value can never bypass the slot engine.
        $request = request();
        if ($request->filled('date')) {
            $this->date = $request->date('date')?->format('Y-m-d') ?? $this->date;
        }
        if ($request->filled('time') && preg_match('/^\d{2}:\d{2}$/', (string) $request->query('time'))) {
            $this->startTime = (string) $request->query('time');
        }
        if (! $isStylist && $request->filled('stylist')) {
            $this->items[0]['stylist_id'] = (string) (int) $request->query('stylist');
        }
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

    public function stylistsFor(string $serviceId)
    {
        if ($serviceId === '') {
            return collect();
        }

        $service = $this->salon->services()->whereKey($serviceId)->first();

        return $service?->stylists()->orderBy('name')->get(['users.id', 'name']) ?? collect();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    /**
     * Slot suggestions for the first item's stylist + service on the chosen date.
     * For a specific stylist these are that stylist's slots (using their resolved
     * duration). For "any available", every qualified active stylist is evaluated
     * with their OWN duration and each slot is tagged with the stylist + their
     * client-facing minutes, so picking one binds that stylist.
     *
     * @return list<array{time: string, stylistId: int, minutes: int, name: string, anyAvailable: bool}>
     */
    #[Computed]
    public function suggestions(): array
    {
        $first = $this->items[0] ?? null;
        if ($first === null || $first['service_id'] === '' || $this->date === '') {
            return [];
        }

        $service = $this->salon->services()->where('active', true)->whereKey($first['service_id'])->first();
        if ($service === null) {
            return [];
        }

        $engine = app(SlotEngine::class);
        $resolver = app(DurationResolver::class);
        $tz = $this->salon->timezone;

        $anyAvailable = $first['stylist_id'] === '';
        if ($anyAvailable) {
            $activeIds = $this->salon->stylistUsers()->pluck('users.id')->map(fn ($i) => (int) $i)->all();
            $candidateIds = $service->stylists()->pluck('users.id')->map(fn ($i) => (int) $i)
                ->filter(fn ($id) => in_array($id, $activeIds, true))->values()->all();
        } else {
            $candidateIds = [(int) $first['stylist_id']];
        }

        $names = $this->salon->stylistUsers()->pluck('name', 'users.id');

        $out = [];
        foreach ($candidateIds as $sid) {
            $resolved = $resolver->resolve($this->salon, $service, $sid);
            foreach ($engine->slotsFor($this->salon, $sid, $resolved->blockedMinutes(), $this->date) as $slot) {
                $out[] = [
                    'time' => $slot->setTimezone($tz)->format('H:i'),
                    'stylistId' => $sid,
                    'minutes' => $resolved->clientFacingMinutes(),
                    'name' => (string) ($names[$sid] ?? ''),
                    'anyAvailable' => $anyAvailable,
                ];
            }
        }

        usort($out, fn ($a, $b) => [$a['time'], $a['stylistId']] <=> [$b['time'], $b['stylistId']]);

        return $out;
    }

    public function addItem(): void
    {
        $this->items[] = ['service_id' => '', 'stylist_id' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        if ($this->items === []) {
            $this->items = [['service_id' => '', 'stylist_id' => '']];
        }
    }

    public function pickSlot(string $time, ?int $stylistId = null): void
    {
        $this->startTime = $time;

        // "Any available" → bind the chosen candidate so it validates against
        // that stylist (manager-only path; stylists are locked to themselves).
        if ($stylistId !== null && $this->canManage && isset($this->items[0]) && $this->items[0]['stylist_id'] === '') {
            $this->items[0]['stylist_id'] = (string) $stylistId;
        }
    }

    public function save(CreateBooking $action): void
    {
        $this->authorize('accessBookings', $this->salon);

        $rules = [
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required'],
        ];
        if (! $this->isWalkin) {
            $rules['date'] = ['required', 'date'];
            $rules['startTime'] = ['required', 'date_format:H:i'];
        }
        if ($this->clientMode === 'existing') {
            $rules['clientId'] = ['required'];
        } else {
            $rules['newName'] = ['required', 'string', 'max:255'];
        }
        $this->validate($rules);

        $client = $this->clientMode === 'existing'
            ? ['id' => $this->clientId]
            : ['name' => $this->newName, 'phone' => $this->newPhone ?: null, 'email' => $this->newEmail ?: null];

        $items = collect($this->items)
            ->filter(fn ($i) => $i['service_id'] !== '')
            ->map(fn ($i) => [
                'service_id' => (int) $i['service_id'],
                'stylist_id' => $i['stylist_id'] === '' ? null : (int) $i['stylist_id'],
            ])
            ->values()
            ->all();

        $booking = $action->handle(Auth::user(), $this->salon, [
            'client' => $client,
            'items' => $items,
            'start' => $this->isWalkin ? null : "{$this->date} {$this->startTime}",
            'is_walkin' => $this->isWalkin,
            'notes' => $this->notes ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Booking created.'));
        $this->redirectRoute('salon.appointments', $this->salon, navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('New booking')" :title="__('Create a booking')" />

        @error('start') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
        @error('items') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
        @error('client') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror

        <form wire:submit="save" class="flex flex-col gap-6">
            {{-- Client --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Client') }}</h2>
                <flux:radio.group wire:model.live="clientMode" variant="segmented">
                    <flux:radio value="existing" label="{{ __('Existing') }}" />
                    <flux:radio value="new" label="{{ __('New') }}" />
                </flux:radio.group>

                @if ($clientMode === 'existing')
                    <flux:input wire:model.live.debounce.300ms="clientSearch" icon="magnifying-glass" :placeholder="__('Search clients')" />
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
                        <flux:input wire:model="newEmail" type="email" :label="__('Email')" />
                    </div>
                @endif
            </x-ui.card>

            {{-- Services --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Services') }}</h2>
                @foreach ($items as $i => $item)
                    <div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                        <flux:select wire:model.live="items.{{ $i }}.service_id" :label="__('Service')">
                            <flux:select.option value="">{{ __('— choose —') }}</flux:select.option>
                            @foreach ($this->services as $service)
                                <flux:select.option value="{{ $service->id }}">{{ $service->name }} ({{ $service->duration_min }}m)</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="items.{{ $i }}.stylist_id" :label="__('Stylist')" :disabled="! $this->canManage">
                            <flux:select.option value="">{{ __('Any available') }}</flux:select.option>
                            @foreach ($this->stylistsFor($item['service_id']) as $stylist)
                                <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="button" variant="subtle" size="sm" wire:click="removeItem({{ $i }})" icon="trash" :aria-label="__('Remove service')" />
                    </div>
                @endforeach
                <div>
                    <button type="button" wire:click="addItem"
                            class="inline-flex items-center gap-1.5 rounded-[9px] px-2.5 py-1.5 text-[14px] font-semibold text-accent transition hover:bg-accent-tint">
                        <flux:icon.plus variant="micro" />{{ __('Add service') }}
                    </button>
                </div>
            </x-ui.card>

            {{-- When --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('When') }}</h2>
                @if ($salon->allow_walkins)
                    <flux:checkbox wire:model.live="isWalkin" :label="__('Walk-in (start now and check in)')" />
                @endif

                @unless ($isWalkin)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input type="date" wire:model.live="date" :label="__('Date')" />
                        <flux:input type="time" wire:model="startTime" :label="__('Start time')" />
                    </div>

                    @if ($this->suggestions !== [])
                        <div>
                            <div class="bts-field-label mb-2">{{ __('Available start times') }}</div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->suggestions as $slot)
                                    @php($selected = $startTime === $slot['time'] && (! $slot['anyAvailable'] || (string) ($items[0]['stylist_id'] ?? '') === (string) $slot['stylistId']))
                                    <button type="button" wire:click="pickSlot('{{ $slot['time'] }}', {{ $slot['stylistId'] }})"
                                            class="flex flex-col items-start rounded-[9px] border px-3 py-1.5 text-left transition {{ $selected ? 'border-accent bg-accent-tint text-accent-ink' : 'border-input-border bg-field text-body hover:border-faint' }}">
                                        <span class="text-[14px] font-medium leading-tight">{{ $slot['time'] }}</span>
                                        @if ($slot['anyAvailable'])
                                            <span class="text-[12px] leading-tight opacity-80">{{ $slot['name'] }} · {{ $slot['minutes'] }} {{ __('min') }}</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endunless

                <flux:textarea wire:model="notes" :label="__('Notes (optional)')" rows="2" />
            </x-ui.card>

            <div class="flex items-center gap-3">
                <x-ui.button type="submit">{{ __('Create booking') }}</x-ui.button>
                <x-ui.button variant="secondary" :href="route('salon.show', $salon)" wire:navigate>{{ __('Cancel') }}</x-ui.button>
            </div>
        </form>
    </div>
</div>
