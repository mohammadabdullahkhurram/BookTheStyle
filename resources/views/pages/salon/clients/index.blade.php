<?php

use App\Actions\Clients\CreateClient;
use App\Actions\Clients\UpdateClient;
use App\Models\Client;
use App\Models\Salon;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Clients')] class extends Component {
    public Salon $salon;

    public string $search = '';

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
        $this->authorize('manageBookings', $salon);
        $this->salon = $salon;
    }

    #[Computed]
    public function clients()
    {
        $term = trim($this->search);

        return $this->salon->clients()
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(100)
            ->get();
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

        unset($this->clients);
        $this->reset(['name', 'phone', 'email']);

        Flux::toast(variant: 'success', text: __('Client added.'));
    }

    public function startEdit(int $clientId): void
    {
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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Clients') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <form wire:submit="create" class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
            <flux:heading size="sm" class="font-serif">{{ __('Add a client') }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="phone" :label="__('Phone')" />
                <flux:input wire:model="email" type="email" :label="__('Email')" />
            </div>
            <div><flux:button type="submit" variant="primary" icon="plus">{{ __('Add client') }}</flux:button></div>
        </form>

        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name, phone, or email')" />

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Phone') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Email') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->clients as $client)
                        <tr>
                            <td class="px-4 py-3 font-medium text-ink">{{ $client->name }}</td>
                            <td class="px-4 py-3 text-secondary">{{ $client->phone ?: '—' }}</td>
                            <td class="px-4 py-3 text-secondary">{{ $client->email ?: '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $client->id }})">{{ __('Edit') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-secondary">{{ __('No clients found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal wire:model="showEdit" class="max-w-md">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:heading size="lg" class="font-serif">{{ __('Edit client') }}</flux:heading>
            <flux:input wire:model="editName" :label="__('Name')" required />
            <flux:input wire:model="editPhone" :label="__('Phone')" />
            <flux:input wire:model="editEmail" type="email" :label="__('Email')" />
            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
