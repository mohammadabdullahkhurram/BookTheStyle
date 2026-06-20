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

    /** @var array<int, string> stylist id => duration override (blank = service default) */
    public array $editDurations = [];

    /** @var array<int, string> stylist id => buffer override (blank = none) */
    public array $editBuffers = [];

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

    /** Per-stylist cleanup buffers are hidden until the salon enables the flag. */
    #[Computed]
    public function buffersEnabled(): bool
    {
        return $this->salon->hasFeature(\App\Services\Booking\DurationResolver::BUFFER_FLAG);
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

        // Assigned stylists + their per-stylist overrides.
        $this->editStylistIds = [];
        $this->editDurations = [];
        $this->editBuffers = [];
        foreach ($service->stylists()->get() as $stylist) {
            $this->editStylistIds[] = $stylist->id;
            $this->editDurations[$stylist->id] = $stylist->pivot->duration_override !== null
                ? (string) $stylist->pivot->duration_override : '';
            $this->editBuffers[$stylist->id] = $stylist->pivot->buffer_override !== null
                ? (string) $stylist->pivot->buffer_override : '';
        }

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

        // Assigned stylists → their (optional, clamped) overrides. Blank = use
        // the service default / no buffer; buffers ignored unless the flag is on.
        $stylists = [];
        foreach ($this->editStylistIds as $id) {
            $id = (int) $id;
            $dur = (string) ($this->editDurations[$id] ?? '');
            $buf = (string) ($this->editBuffers[$id] ?? '');
            $stylists[$id] = [
                'duration_override' => $dur === '' ? null : max(5, min(600, (int) $dur)),
                'buffer_override' => (! $this->buffersEnabled() || $buf === '') ? null : max(0, min(120, (int) $buf)),
            ];
        }
        $sync->handle($this->salon, $service, $stylists);

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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('Manage')" :title="__('Services')" />

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Add a service') }}</h2>
            <form wire:submit="create" class="flex flex-col gap-4">
                <div class="grid items-end gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2"><flux:input wire:model="name" :label="__('Name')" required /></div>
                    <flux:input type="number" wire:model="duration_min" :label="__('Duration (min)')" min="5" max="600" step="5" />
                    <flux:input type="color" wire:model="color" :label="__('Color')" />
                </div>
                <div>
                    <x-ui.button type="submit"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add service') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th class="px-6 py-3.5 font-semibold">{{ __('Service') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Duration') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Stylists') }}</th>
                        <th class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->services as $service)
                        <tr @class(['opacity-65' => ! $service->active])>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="size-3 rounded-full" style="background-color: {{ $service->color }}"></span>
                                    <span class="text-[15px] font-medium text-ink">{{ $service->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $service->duration_min }} {{ __('min') }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">
                                {{ $service->stylists->pluck('name')->join(', ') ?: __('None assigned') }}
                            </td>
                            <td class="px-6 py-4">
                                @if ($service->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#9C9890;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-4">
                                    <button type="button" wire:click="startEdit({{ $service->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                                    <button type="button" wire:click="toggleActive({{ $service->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">
                                        {{ $service->active ? __('Deactivate') : __('Reactivate') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No services yet. Add one above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showEdit" class="max-w-lg" :heading="__('Edit service')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:input wire:model="editName" :label="__('Name')" required />
            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" wire:model.live="editDuration" :label="__('Default duration (min)')" min="5" max="600" step="5" />
                <flux:input type="color" wire:model="editColor" :label="__('Color')" />
            </div>
            <flux:checkbox wire:model="editActive" :label="__('Active')" />

            <div>
                <flux:label>{{ __('Qualified stylists') }}</flux:label>
                <flux:text class="mb-2 text-sm text-secondary">
                    {{ $this->buffersEnabled
                        ? __('Who can perform this service, plus their own time and cleanup buffer. Leave time blank to use the service default.')
                        : __('Who can perform this service, and how long they take. Leave time blank to use the service default.') }}
                </flux:text>
                <div class="flex flex-col gap-2.5">
                    @forelse ($this->stylists as $stylist)
                        <div class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <flux:checkbox wire:model="editStylistIds" value="{{ $stylist->id }}" :label="$stylist->name" />
                            </div>
                            <div class="w-24 shrink-0">
                                <flux:input type="number" wire:model="editDurations.{{ $stylist->id }}" :placeholder="$editDuration . ' min'" min="5" max="600" step="5" />
                            </div>
                            @if ($this->buffersEnabled)
                                <div class="w-24 shrink-0">
                                    <flux:input type="number" wire:model="editBuffers.{{ $stylist->id }}" :placeholder="__('buffer')" min="0" max="120" step="5" />
                                </div>
                            @endif
                        </div>
                    @empty
                        <flux:text class="text-sm text-secondary">{{ __('No stylists in this salon yet. Add stylists on the Staff page.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
