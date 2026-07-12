<?php

use App\Actions\Clients\CreateClient;
use App\Actions\Clients\UpdateClient;
use App\Models\Client;
use App\Models\Salon;
use App\Services\Clients\ClientDirectory;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * The Clients directory: every client the salon has ever had, with
 * at-a-glance stats aggregated in ONE paginated query (ClientDirectory —
 * correlated subselects, no per-client PHP work). Viewing matches the
 * client-profile rule (accessBookings — stylists included); adding/editing
 * stays front-desk level (manageBookings). Rows open the existing profile.
 */
new #[Title('Clients')] class extends Component {
    use WithPagination;

    public Salon $salon;

    public string $search = '';

    public string $sort = 'name';

    public string $stylistFilter = '';

    public string $serviceFilter = '';

    public bool $upcomingOnly = false;

    public bool $newOnly = false;

    public string $name = '';
    public string $phone = '';
    public string $email = '';

    public ?int $editingId = null;
    public string $editName = '';
    public string $editPhone = '';
    public string $editEmail = '';
    public bool $showEdit = false;

    public function mount(Salon $salon): void
    {
        // Same rule as the client profile: any booking-area staff may look.
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;
    }

    /** Whether the viewer may add/edit clients (front-desk level). */
    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    #[Computed]
    public function clients()
    {
        return app(ClientDirectory::class)->paginate($this->salon, [
            'search' => $this->search,
            'sort' => in_array($this->sort, ClientDirectory::SORTS, true) ? $this->sort : 'name',
            'stylist_id' => $this->stylistFilter !== '' ? (int) $this->stylistFilter : null,
            'service_id' => $this->serviceFilter !== '' ? (int) $this->serviceFilter : null,
            'upcoming_only' => $this->upcomingOnly,
            'new_only' => $this->newOnly,
        ]);
    }

    #[Computed]
    public function summary(): array
    {
        return app(ClientDirectory::class)->summary($this->salon);
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedStylistFilter(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedUpcomingOnly(): void
    {
        $this->resetPage();
    }

    public function updatedNewOnly(): void
    {
        $this->resetPage();
    }

    /** Format a raw UTC subquery timestamp as a salon-local short date. */
    public function localDate(?string $utc): ?string
    {
        return $utc !== null
            ? CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->salon->timezone)->format('M j, Y')
            : null;
    }

    public function spentLabel(int|string|null $cents): string
    {
        $cents = (int) ($cents ?? 0);

        return $cents > 0 ? (Money::format($cents, $this->salon->currency) ?? '—') : '—';
    }

    public function isNew(Client $client): bool
    {
        return $client->created_at !== null
            && $client->created_at->gte(now()->subDays(ClientDirectory::NEW_CLIENT_DAYS));
    }

    public function create(CreateClient $action): void
    {
        $this->authorize('manageBookings', $this->salon);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $action->handle($this->salon, $data);

        unset($this->clients, $this->summary);
        $this->reset(['name', 'phone', 'email']);

        Flux::toast(variant: 'success', text: __('Client added.'));
    }

    public function startEdit(int $clientId): void
    {
        abort_unless($this->canManage, 403);

        $client = $this->client($clientId);
        $this->editingId = $client->id;
        $this->editName = $client->name;
        $this->editPhone = (string) $client->phone;
        $this->editEmail = (string) $client->email;
        $this->showEdit = true;
    }

    public function saveEdit(UpdateClient $action): void
    {
        $this->authorize('manageBookings', $this->salon);
        $client = $this->client((int) $this->editingId);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editPhone' => ['nullable', 'string', 'max:50'],
            'editEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $action->handle($this->salon, $client, [
            'name' => $data['editName'],
            'phone' => $data['editPhone'] ?: null,
            'email' => $data['editEmail'] ?: null,
        ]);

        $this->showEdit = false;
        $this->editingId = null;
        unset($this->clients);

        Flux::toast(variant: 'success', text: __('Client updated.'));
    }

    /**
     * Scoped lookup — out-of-salon ids 404 (no IDOR).
     */
    private function client(int $id): Client
    {
        return $this->salon->clients()->whereKey($id)->firstOrFail();
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Directory')" :title="__('Clients')">
            <x-slot:actions>
                <span class="text-[14px] text-secondary">
                    {{ trans_choice(':count client|:count clients', $this->summary['total'], ['count' => $this->summary['total']]) }}
                    @if ($this->summary['new_this_month'] > 0)
                        · {{ __(':count new in the last 30 days', ['count' => $this->summary['new_this_month']]) }}
                    @endif
                </span>
            </x-slot:actions>
        </x-ui.page-header>

        @if ($this->canManage)
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Add a client') }}</h2>
                <form wire:submit="create" class="flex flex-col gap-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model="name" :label="__('Name')" required />
                        <flux:input wire:model="phone" :label="__('Phone')" />
                        <flux:input wire:model="email" type="email" :label="__('Email')" />
                    </div>
                    <div>
                        <x-ui.button type="submit" loading="create"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add client') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Search + sort + filters. --}}
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search')" :placeholder="__('Search by name, phone, or email')" class="min-w-64 flex-1" />
            <flux:select wire:model.live="sort" :label="__('Sort')" class="max-w-44">
                <flux:select.option value="name">{{ __('Name (A–Z)') }}</flux:select.option>
                <flux:select.option value="visits">{{ __('Most visits') }}</flux:select.option>
                <flux:select.option value="recent">{{ __('Most recent visit') }}</flux:select.option>
                <flux:select.option value="spent">{{ __('Total spent') }}</flux:select.option>
                <flux:select.option value="newest">{{ __('Newest client') }}</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="stylistFilter" :label="__('Stylist')" class="max-w-44">
                <flux:select.option value="">{{ __('Any stylist') }}</flux:select.option>
                @foreach ($this->stylists as $stylist)
                    <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="serviceFilter" :label="__('Service')" class="max-w-44">
                <flux:select.option value="">{{ __('Any service') }}</flux:select.option>
                @foreach ($this->services as $service)
                    <flux:select.option value="{{ $service->id }}">{{ $service->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex items-center gap-4 pb-2">
                <flux:checkbox wire:model.live="upcomingOnly" :label="__('Has upcoming')" />
                <flux:checkbox wire:model.live="newOnly" :label="__('New clients')" />
            </div>
        </div>

        @if ($this->clients->isEmpty())
            <x-ui.card class="py-14 text-center text-[15px] text-faint">
                {{ $search !== '' || $stylistFilter !== '' || $serviceFilter !== '' || $upcomingOnly || $newOnly
                    ? __('No clients match. Adjust the search or filters.')
                    : __('No clients yet. They appear here with their first booking.') }}
            </x-ui.card>
        @else
            {{-- Desktop table. --}}
            <x-ui.card padding="p-0" class="hidden overflow-hidden transition-opacity md:block"
                wire:loading.class="pointer-events-none opacity-60" wire:target="search, sort, stylistFilter, serviceFilter, upcomingOnly, newOnly">
                <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bts-overline border-b border-divider">
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Client') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Contact') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Visits') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Last visit') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Upcoming') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Spent (est.)') }}</th>
                            <th scope="col" class="px-5 py-3.5 font-semibold">{{ __('Stylist') }}</th>
                            <th scope="col" class="px-5 py-3.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-row">
                        @foreach ($this->clients as $client)
                            <tr wire:key="client-{{ $client->id }}">
                                <td class="px-5 py-3.5">
                                    <a href="{{ route('salon.client', ['salon' => $salon, 'clientId' => $client->id]) }}" wire:navigate class="flex items-center gap-3">
                                        <x-ui.avatar :name="$client->name" :seed="$client->id" size="sm" />
                                        <span class="flex items-center gap-2">
                                            <span class="text-[15px] font-medium text-ink transition hover:text-accent">{{ $client->name }}</span>
                                            @if ($client->allergies)
                                                <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;" title="{{ __('Has allergies / sensitivities') }}">{{ __('Allergy') }}</span>
                                            @endif
                                            @if ($this->isNew($client))
                                                <span class="bts-pill" style="background-color:#ECEAFB;color:#4B3FA0;">{{ __('New') }}</span>
                                            @endif
                                            @if ($client->notes_count > 0)
                                                <flux:icon.document-text variant="micro" class="text-faint" title="{{ __('Has notes') }}" />
                                            @endif
                                        </span>
                                    </a>
                                </td>
                                <td class="px-5 py-3.5 text-[13.5px] text-secondary">
                                    <div class="flex flex-col">
                                        <span>{{ $client->phone ?: '—' }}</span>
                                        @if ($client->email)<span class="text-faint">{{ $client->email }}</span>@endif
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 text-[14px] text-body">
                                    {{ (int) $client->total_visits }}
                                    <span class="text-[12.5px] text-faint">· {{ trans_choice(':count service|:count services', (int) $client->total_services, ['count' => (int) $client->total_services]) }}@if ((int) $client->no_show_count > 0) · {{ __(':count no-show', ['count' => (int) $client->no_show_count]) }}@endif</span>
                                </td>
                                <td class="px-5 py-3.5 text-[14px] text-secondary">{{ $this->localDate($client->last_visit_at) ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-[14px]">
                                    @if ($client->upcoming_at !== null)
                                        <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ $this->localDate($client->upcoming_at) }}</span>
                                    @else
                                        <span class="text-faint">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3.5 text-[14px] text-secondary">{{ $this->spentLabel($client->spent_cents) }}</td>
                                <td class="px-5 py-3.5 text-[14px] text-secondary">{{ $client->preferredStylist?->name ?? '—' }}</td>
                                <td class="px-5 py-3.5 text-right">
                                    @if ($this->canManage)
                                        <button type="button" wire:click="startEdit({{ $client->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-ui.card>

            {{-- Mobile: stacked cards. --}}
            <div class="flex flex-col gap-3 transition-opacity md:hidden"
                 wire:loading.class="pointer-events-none opacity-60" wire:target="search, sort, stylistFilter, serviceFilter, upcomingOnly, newOnly">
                @foreach ($this->clients as $client)
                    <a href="{{ route('salon.client', ['salon' => $salon, 'clientId' => $client->id]) }}" wire:navigate wire:key="client-m-{{ $client->id }}">
                        <x-ui.card padding="p-4" class="flex flex-col gap-2">
                            <div class="flex items-center gap-3">
                                <x-ui.avatar :name="$client->name" :seed="$client->id" size="sm" />
                                <span class="text-[15px] font-medium text-ink">{{ $client->name }}</span>
                                @if ($client->allergies)
                                    <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;">{{ __('Allergy') }}</span>
                                @endif
                                @if ($this->isNew($client))
                                    <span class="bts-pill" style="background-color:#ECEAFB;color:#4B3FA0;">{{ __('New') }}</span>
                                @endif
                            </div>
                            <div class="text-[13px] text-secondary">{{ $client->phone ?: ($client->email ?: '—') }}</div>
                            <div class="text-[13px] text-faint">
                                {{ trans_choice(':count visit|:count visits', (int) $client->total_visits, ['count' => (int) $client->total_visits]) }}
                                @if ($client->last_visit_at) · {{ __('last :date', ['date' => $this->localDate($client->last_visit_at)]) }} @endif
                                @if ($client->upcoming_at) · {{ __('next :date', ['date' => $this->localDate($client->upcoming_at)]) }} @endif
                                · {{ $this->spentLabel($client->spent_cents) }}
                            </div>
                        </x-ui.card>
                    </a>
                @endforeach
            </div>

            <div>{{ $this->clients->links() }}</div>
        @endif
    </div>

    <x-ui.modal wire:model="showEdit" class="max-w-md" :heading="__('Edit client')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:input wire:model="editName" :label="__('Name')" required />
            <flux:input wire:model="editPhone" :label="__('Phone')" />
            <flux:input wire:model="editEmail" type="email" :label="__('Email')" />
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
