<?php

use App\Actions\Clients\AddClientNote;
use App\Actions\Clients\UpdateClient;
use App\Actions\Clients\UpdateClientPreferences;
use App\Models\Client;
use App\Models\Salon;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The client profile: visit history (from existing bookings), timestamped
 * staff notes, and preferences — with allergies surfaced prominently.
 * Viewing + adding notes: any salon member who can reach bookings
 * (accessBookings, stylists included). Editing contact/preferences:
 * front-desk level (manageBookings).
 */
new #[Title('Client')] class extends Component {
    public Salon $salon;

    public Client $client;

    public string $noteBody = '';

    // Preferences edit form (front-desk level).
    public bool $editingPrefs = false;
    public string $allergies = '';
    public string $formulaNotes = '';
    public string $preferredStylistId = '';
    public string $preferredContactMethod = '';
    public string $birthday = '';

    // Contact edit modal (front-desk level; same fields as the list page).
    public bool $showContactEdit = false;
    public string $editName = '';
    public string $editPhone = '';
    public string $editEmail = '';

    public function mount(Salon $salon, int $clientId): void
    {
        $this->authorize('accessBookings', $salon);
        $this->salon = $salon;
        // Scoped lookup — out-of-salon ids 404 (no IDOR).
        $this->client = $salon->clients()->whereKey($clientId)->firstOrFail();
    }

    /** Whether the viewer may edit contact details and preferences. */
    #[Computed]
    public function canEdit(): bool
    {
        return Auth::user()->can('manageBookings', $this->salon);
    }

    /**
     * Visit history from existing bookings — most recent first (past and
     * upcoming), each with its services, stylists, status, and price.
     */
    #[Computed]
    public function visits()
    {
        return $this->client->bookings()
            ->with(['items.service', 'items.stylist'])
            ->withMin('items', 'starts_at')
            ->orderByDesc('items_min_starts_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function notes()
    {
        return $this->client->notes()->with('author:id,name')->limit(100)->get();
    }

    #[Computed]
    public function stylists()
    {
        return $this->salon->stylistUsers()->orderBy('name')->get(['users.id', 'name']);
    }

    /** Completed-visit count + the most recent past visit date, for the header. */
    #[Computed]
    public function stats(): array
    {
        $past = $this->visits->filter(fn ($b) => $b->status === \App\Enums\BookingStatus::Completed);

        return [
            'visits' => $past->count(),
            'last' => $past->first()?->items->min('starts_at')?->setTimezone($this->salon->timezone)->format('M j, Y'),
        ];
    }

    public function addNote(AddClientNote $action): void
    {
        $data = $this->validate(['noteBody' => ['required', 'string', 'max:2000']]);

        $action->handle(Auth::user(), $this->salon, $this->client, $data['noteBody']);

        $this->noteBody = '';
        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note added.'));
    }

    public function startEditingPrefs(): void
    {
        abort_unless($this->canEdit, 403);

        $this->allergies = (string) $this->client->allergies;
        $this->formulaNotes = (string) $this->client->formula_notes;
        $this->preferredStylistId = $this->client->preferred_stylist_id !== null ? (string) $this->client->preferred_stylist_id : '';
        $this->preferredContactMethod = (string) $this->client->preferred_contact_method;
        $this->birthday = $this->client->birthday?->format('Y-m-d') ?? '';
        $this->editingPrefs = true;
    }

    public function savePreferences(UpdateClientPreferences $action): void
    {
        $this->authorize('manageBookings', $this->salon);

        $data = $this->validate([
            'allergies' => ['nullable', 'string', 'max:2000'],
            'formulaNotes' => ['nullable', 'string', 'max:2000'],
            'preferredStylistId' => ['nullable', 'integer'],
            'preferredContactMethod' => ['nullable', Rule::in(Client::CONTACT_METHODS)],
            'birthday' => ['nullable', 'date'],
        ]);

        $action->handle($this->salon, $this->client, [
            'allergies' => trim((string) ($data['allergies'] ?? '')) ?: null,
            'formula_notes' => trim((string) ($data['formulaNotes'] ?? '')) ?: null,
            'preferred_stylist_id' => ($data['preferredStylistId'] ?? '') !== '' && $data['preferredStylistId'] !== null ? (int) $data['preferredStylistId'] : null,
            'preferred_contact_method' => ($data['preferredContactMethod'] ?? '') ?: null,
            'birthday' => ($data['birthday'] ?? '') ?: null,
        ]);

        $this->client->refresh();
        $this->editingPrefs = false;

        Flux::toast(variant: 'success', text: __('Preferences saved.'));
    }

    public function startContactEdit(): void
    {
        abort_unless($this->canEdit, 403);

        $this->editName = $this->client->name;
        $this->editPhone = (string) $this->client->phone;
        $this->editEmail = (string) $this->client->email;
        $this->showContactEdit = true;
    }

    public function saveContact(UpdateClient $action): void
    {
        $this->authorize('manageBookings', $this->salon);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editPhone' => ['nullable', 'string', 'max:50'],
            'editEmail' => ['nullable', 'email', 'max:255'],
        ]);

        $action->handle($this->salon, $this->client, [
            'name' => $data['editName'],
            'phone' => $data['editPhone'] ?: null,
            'email' => $data['editEmail'] ?: null,
        ]);

        $this->client->refresh();
        $this->showContactEdit = false;

        Flux::toast(variant: 'success', text: __('Client updated.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-8 py-7">
        {{-- Header: identity, contact, key flags. --}}
        <x-ui.card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-4">
                    <x-ui.avatar :name="$client->name" :seed="$client->id" size="lg" />
                    <div class="flex flex-col gap-1">
                        <h1 class="text-[22px] font-semibold text-ink">{{ $client->name }}</h1>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[14px] text-secondary">
                            <span>{{ $client->phone ?: __('No phone') }}</span>
                            <span>·</span>
                            <span>{{ $client->email ?: __('No email') }}</span>
                            @if ($client->preferred_contact_method)
                                <span class="bts-pill" style="background-color:#EFEDE8;color:#56534C;">{{ __('Prefers :method', ['method' => $client->preferred_contact_method]) }}</span>
                            @endif
                            @if ($client->ghl_contact_id)
                                <span class="bts-pill" style="background-color:#ECEAFB;color:#4B3FA0;">{{ __('Linked to GoHighLevel') }}</span>
                            @endif
                            @if ($this->canEdit && $client->ghl_sync_status === \App\Services\Ghl\GhlContactSync::STATUS_FAILED)
                                <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;" title="{{ $client->ghl_sync_error }}">{{ __('GoHighLevel sync failed') }}</span>
                            @endif
                        </div>
                        <div class="text-[13px] text-faint">
                            {{ trans_choice(':count completed visit|:count completed visits', $this->stats['visits'], ['count' => $this->stats['visits']]) }}
                            @if ($this->stats['last']) · {{ __('Last visit :date', ['date' => $this->stats['last']]) }} @endif
                        </div>
                    </div>
                </div>
                @if ($this->canEdit)
                    <x-ui.button variant="secondary" wire:click="startContactEdit">{{ __('Edit contact') }}</x-ui.button>
                @endif
            </div>

            {{-- Allergies are safety-relevant: always a prominent banner when present. --}}
            @if ($client->allergies)
                <div class="flex items-start gap-2.5 rounded-[11px] border border-[#E8C9C9] bg-[#F8E3E3] px-4 py-3">
                    <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0 text-[#A23A3A]" />
                    <div class="text-[14px] leading-relaxed text-[#A23A3A]">
                        <span class="font-semibold">{{ __('Allergies / sensitivities:') }}</span>
                        {{ $client->allergies }}
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Visit history. --}}
        <x-ui.card padding="p-0" class="overflow-hidden">
            <h2 class="bts-card-title border-b border-divider px-6 py-4">{{ __('Visit history') }}</h2>
            <div class="flex flex-col divide-y divide-row">
                @forelse ($this->visits as $booking)
                    <div class="flex flex-wrap items-center justify-between gap-2 px-6 py-3.5 text-[14px]">
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <span class="font-medium text-ink">
                                {{ $booking->items->first()?->starts_at?->setTimezone($salon->timezone)->format('D, M j, Y · g:i A') }}
                            </span>
                            <span class="text-secondary">
                                @foreach ($booking->items as $item)
                                    {{ $item->service->name }} {{ __('with') }} {{ $item->stylist->name }}@if ($item->service->price_cents !== null) <span class="text-faint">· {{ $item->service->priceLabel($salon->currency) }}</span>@endif@if (! $loop->last), @endif
                                @endforeach
                            </span>
                        </div>
                        <x-ui.status-pill :status="$booking->status" />
                    </div>
                @empty
                    <div class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No visits yet.') }}</div>
                @endforelse
            </div>
        </x-ui.card>

        <div class="grid items-start gap-6 lg:grid-cols-2">
            {{-- Notes: timestamped, attributed; any booking-area staff can add. --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Notes') }}</h2>
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:textarea wire:model="noteBody" rows="2" :placeholder="__('e.g. Prefers cooler tones; usually runs 10 minutes late')" />
                    <div><x-ui.button type="submit" size="sm">{{ __('Add note') }}</x-ui.button></div>
                </form>
                <div class="flex flex-col divide-y divide-row">
                    @forelse ($this->notes as $note)
                        <div class="flex flex-col gap-1 py-3">
                            <p class="text-[14px] leading-relaxed text-body">{{ $note->body }}</p>
                            <span class="text-[12.5px] text-faint">
                                {{ $note->author?->name ?? __('Former staff') }} · {{ $note->created_at->setTimezone($salon->timezone)->format('M j, Y g:i A') }}
                            </span>
                        </div>
                    @empty
                        <p class="py-3 text-[14px] text-faint">{{ __('No notes yet.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            {{-- Preferences / key info. --}}
            <x-ui.card class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h2 class="bts-card-title">{{ __('Preferences') }}</h2>
                    @if ($this->canEdit && ! $editingPrefs)
                        <button type="button" wire:click="startEditingPrefs" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                    @endif
                </div>

                @if ($editingPrefs)
                    <form wire:submit="savePreferences" class="flex flex-col gap-4">
                        <flux:textarea wire:model="allergies" rows="2" :label="__('Allergies / sensitivities')" :placeholder="__('e.g. PPD allergy — no permanent color')" />
                        <flux:textarea wire:model="formulaNotes" rows="2" :label="__('Formula / color notes')" :placeholder="__('e.g. 7N + 8A 1:1, 20 vol')" />
                        <flux:select wire:model="preferredStylistId" :label="__('Preferred stylist')">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach ($this->stylists as $stylist)
                                <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:select wire:model="preferredContactMethod" :label="__('Preferred contact')">
                                <flux:select.option value="">{{ __('No preference') }}</flux:select.option>
                                @foreach (\App\Models\Client::CONTACT_METHODS as $method)
                                    <flux:select.option value="{{ $method }}">{{ ucfirst($method) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input type="date" wire:model="birthday" :label="__('Birthday (optional)')" />
                        </div>
                        <div class="flex justify-end gap-3">
                            <x-ui.button type="button" variant="secondary" wire:click="$set('editingPrefs', false)">{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
                        </div>
                    </form>
                @else
                    <dl class="flex flex-col gap-3 text-[14px]">
                        <div>
                            <dt class="bts-overline">{{ __('Allergies / sensitivities') }}</dt>
                            <dd class="mt-1 {{ $client->allergies ? 'font-medium text-[#A23A3A]' : 'text-faint' }}">{{ $client->allergies ?: __('None recorded') }}</dd>
                        </div>
                        <div>
                            <dt class="bts-overline">{{ __('Formula / color notes') }}</dt>
                            <dd class="mt-1 {{ $client->formula_notes ? 'text-body' : 'text-faint' }}">{{ $client->formula_notes ?: __('None recorded') }}</dd>
                        </div>
                        <div>
                            <dt class="bts-overline">{{ __('Preferred stylist') }}</dt>
                            <dd class="mt-1 {{ $client->preferred_stylist_id ? 'text-body' : 'text-faint' }}">{{ $client->preferredStylist?->name ?? __('No preference') }}</dd>
                        </div>
                        <div>
                            <dt class="bts-overline">{{ __('Birthday') }}</dt>
                            <dd class="mt-1 {{ $client->birthday ? 'text-body' : 'text-faint' }}">{{ $client->birthday?->format('F j') ?? __('Not recorded') }}</dd>
                        </div>
                    </dl>
                @endif
            </x-ui.card>
        </div>
    </div>

    <x-ui.modal wire:model="showContactEdit" class="max-w-md" :heading="__('Edit client')">
        <form wire:submit="saveContact" class="flex flex-col gap-5">
            <flux:input wire:model="editName" :label="__('Name')" required />
            <flux:input wire:model="editPhone" :label="__('Phone')" />
            <flux:input wire:model="editEmail" type="email" :label="__('Email')" />
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showContactEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
