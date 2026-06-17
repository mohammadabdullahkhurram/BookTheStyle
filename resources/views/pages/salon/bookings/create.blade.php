<?php

use App\Actions\Bookings\CreateBooking;
use App\Models\Salon;
use App\Models\Service;
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
        if (! Auth::user()->can('manageBookings', $salon) && Auth::user()->stylistMembershipFor($salon)) {
            $this->items = [['service_id' => '', 'stylist_id' => (string) Auth::id()]];
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
     *
     * @return list<string>
     */
    #[Computed]
    public function suggestions(): array
    {
        $first = $this->items[0] ?? null;
        if ($first === null || $first['service_id'] === '' || $first['stylist_id'] === '' || $this->date === '') {
            return [];
        }

        $service = $this->salon->services()->whereKey($first['service_id'])->first();
        if ($service === null) {
            return [];
        }

        $slots = app(SlotEngine::class)->slotsFor($this->salon, (int) $first['stylist_id'], $service->duration_min, $this->date);

        return array_map(fn (CarbonImmutable $s) => $s->setTimezone($this->salon->timezone)->format('H:i'), $slots);
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

    public function pickSlot(string $time): void
    {
        $this->startTime = $time;
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
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('New booking') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        @error('start') <flux:text class="text-sm text-danger">{{ $message }}</flux:text> @enderror
        @error('items') <flux:text class="text-sm text-danger">{{ $message }}</flux:text> @enderror
        @error('client') <flux:text class="text-sm text-danger">{{ $message }}</flux:text> @enderror

        <form wire:submit="save" class="flex flex-col gap-6">
            {{-- Client --}}
            <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Client') }}</flux:heading>
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
            </div>

            {{-- Services --}}
            <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Services') }}</flux:heading>
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
                        <flux:button type="button" variant="ghost" size="sm" wire:click="removeItem({{ $i }})" icon="trash" />
                    </div>
                @endforeach
                <div><flux:button type="button" variant="ghost" size="sm" icon="plus" wire:click="addItem">{{ __('Add service') }}</flux:button></div>
            </div>

            {{-- When --}}
            <div class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('When') }}</flux:heading>
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
                            <flux:text class="mb-2 text-sm text-secondary">{{ __('Available start times (first stylist):') }}</flux:text>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->suggestions as $time)
                                    <flux:button type="button" size="xs" variant="{{ $startTime === $time ? 'primary' : 'ghost' }}" wire:click="pickSlot('{{ $time }}')">{{ $time }}</flux:button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endunless

                <flux:textarea wire:model="notes" :label="__('Notes (optional)')" rows="2" />
            </div>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Create booking') }}</flux:button>
                <flux:button :href="route('salon.show', $salon)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</div>
