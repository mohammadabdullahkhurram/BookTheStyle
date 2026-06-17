<?php

use App\Actions\Services\CreateService;
use App\Actions\Services\SetServiceActive;
use App\Actions\Services\SyncServiceStylists;
use App\Actions\Services\UpdateService;
use App\Models\Salon;
use App\Models\Service;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Services')] class extends Component {
    public Salon $salon;

    // Create form
    public string $name = '';
    public int $duration_min = 30;
    public string $color = '#1F6F6B';

    // Edit modal
    public ?int $editingId = null;
    public string $editName = '';
    public int $editDuration = 30;
    public string $editColor = '#1F6F6B';
    public bool $editActive = true;

    /** @var array<int, int> */
    public array $editStylistIds = [];
    public bool $showEdit = false;

    public function mount(Salon $salon): void
    {
        $this->authorize('manageServices', $salon);
        $this->salon = $salon;
    }

    #[Computed]
    public function services()
    {
        return $this->salon->services()->with('stylists:id,name')->orderBy('name')->get();
    }

    #[Computed]
    public function stylists()
    {
        return $this->salon->stylistUsers()->orderBy('name')->get(['users.id', 'name']);
    }

    public function create(CreateService $action): void
    {
        $this->authorize('manageServices', $this->salon);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $action->handle($this->salon, $data);

        unset($this->services);
        $this->reset(['name', 'duration_min', 'color']);
        $this->duration_min = 30;
        $this->color = '#1F6F6B';

        Flux::toast(variant: 'success', text: __('Service created.'));
    }

    public function startEdit(int $serviceId): void
    {
        $service = $this->service($serviceId);

        $this->editingId = $service->id;
        $this->editName = $service->name;
        $this->editDuration = $service->duration_min;
        $this->editColor = $service->color;
        $this->editActive = $service->active;
        $this->editStylistIds = $service->stylists()->pluck('users.id')->all();
        $this->showEdit = true;
    }

    public function saveEdit(UpdateService $update, SyncServiceStylists $sync): void
    {
        $this->authorize('manageServices', $this->salon);

        $service = $this->service((int) $this->editingId);

        $data = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editDuration' => ['required', 'integer', 'min:5', 'max:600'],
            'editColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'editActive' => ['boolean'],
            'editStylistIds' => ['array'],
            'editStylistIds.*' => ['integer'],
        ]);

        $update->handle($this->salon, $service, [
            'name' => $data['editName'],
            'duration_min' => $data['editDuration'],
            'color' => $data['editColor'],
            'active' => $data['editActive'],
        ]);
        $sync->handle($this->salon, $service, $this->editStylistIds);

        $this->showEdit = false;
        $this->editingId = null;
        unset($this->services);

        Flux::toast(variant: 'success', text: __('Service updated.'));
    }

    public function toggleActive(int $serviceId, SetServiceActive $action): void
    {
        $this->authorize('manageServices', $this->salon);
        $service = $this->service($serviceId);
        $action->handle($this->salon, $service, ! $service->active);
        unset($this->services);

        Flux::toast(variant: 'success', text: $service->active ? __('Service reactivated.') : __('Service deactivated.'));
    }

    /**
     * Fetch a service scoped to this salon — out-of-salon ids 404 (no IDOR).
     */
    private function service(int $id): Service
    {
        return $this->salon->services()->whereKey($id)->firstOrFail();
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Services') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        <form wire:submit="create" class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
            <flux:heading size="sm" class="font-serif">{{ __('Add a service') }}</flux:heading>
            <div class="grid items-end gap-4 sm:grid-cols-4">
                <div class="sm:col-span-2"><flux:input wire:model="name" :label="__('Name')" required /></div>
                <flux:input type="number" wire:model="duration_min" :label="__('Duration (min)')" min="5" max="600" step="5" />
                <flux:input type="color" wire:model="color" :label="__('Color')" />
            </div>
            <div><flux:button type="submit" variant="primary" icon="plus">{{ __('Add service') }}</flux:button></div>
        </form>

        <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                    <tr>
                        <th class="px-4 py-3 font-medium">{{ __('Service') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Duration') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Stylists') }}</th>
                        <th class="px-4 py-3 font-medium">{{ __('Status') }}</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->services as $service)
                        <tr>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="size-3 rounded-full" style="background-color: {{ $service->color }}"></span>
                                    <span class="font-medium text-ink">{{ $service->name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-secondary">{{ $service->duration_min }} {{ __('min') }}</td>
                            <td class="px-4 py-3 text-secondary">
                                {{ $service->stylists->pluck('name')->join(', ') ?: __('None assigned') }}
                            </td>
                            <td class="px-4 py-3">
                                @if ($service->active)
                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <flux:button size="xs" variant="ghost" wire:click="startEdit({{ $service->id }})">{{ __('Edit') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="toggleActive({{ $service->id }})">
                                        {{ $service->active ? __('Deactivate') : __('Reactivate') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-secondary">{{ __('No services yet. Add one above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <flux:modal wire:model="showEdit" class="max-w-md">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:heading size="lg" class="font-serif">{{ __('Edit service') }}</flux:heading>
            <flux:input wire:model="editName" :label="__('Name')" required />
            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" wire:model="editDuration" :label="__('Duration (min)')" min="5" max="600" step="5" />
                <flux:input type="color" wire:model="editColor" :label="__('Color')" />
            </div>
            <flux:checkbox wire:model="editActive" :label="__('Active')" />

            <div>
                <flux:label>{{ __('Qualified stylists') }}</flux:label>
                <flux:text class="mb-2 text-sm text-secondary">{{ __('Who can perform this service.') }}</flux:text>
                <div class="flex flex-col gap-2">
                    @forelse ($this->stylists as $stylist)
                        <flux:checkbox wire:model="editStylistIds" value="{{ $stylist->id }}" :label="$stylist->name" />
                    @empty
                        <flux:text class="text-sm text-secondary">{{ __('No stylists in this salon yet. Add stylists on the Staff page.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button type="button" variant="ghost" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
