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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('Clients')" :title="__('Clients')" />

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Add a client') }}</h2>
            <form wire:submit="create" class="flex flex-col gap-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:input wire:model="name" :label="__('Name')" required />
                    <flux:input wire:model="phone" :label="__('Phone')" />
                    <flux:input wire:model="email" type="email" :label="__('Email')" />
                </div>
                <div>
                    <x-ui.button type="submit"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add client') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name, phone, or email')" />

        <x-ui.card padding="p-0" class="overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th class="px-6 py-3.5 font-semibold">{{ __('Name') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Phone') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Email') }}</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->clients as $client)
                        <tr>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$client->name" :seed="$client->id" size="sm" />
                                    <span class="text-[15px] font-medium text-ink">{{ $client->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $client->phone ?: '—' }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $client->email ?: '—' }}</td>
                            <td class="px-6 py-4 text-right">
                                <button type="button" wire:click="startEdit({{ $client->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No clients found.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <flux:modal wire:model="showEdit" class="max-w-md">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Edit client') }}</h2>
            <flux:input wire:model="editName" :label="__('Name')" required />
            <flux:input wire:model="editPhone" :label="__('Phone')" />
            <flux:input wire:model="editEmail" type="email" :label="__('Email')" />
            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </flux:modal>
</div>
