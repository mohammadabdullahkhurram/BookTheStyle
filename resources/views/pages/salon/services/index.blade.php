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

    /** Display price in major units; blank = "price varies" (stored NULL). */
    public string $price = '';

    /** @var array<int, int> */
    public array $stylistIds = [];

    /** @var array<int, string> stylist id => duration override (blank = service default) */
    public array $durations = [];

    /** @var array<int, string> stylist id => buffer override (blank = none) */
    public array $buffers = [];

    // Edit modal
    public ?int $editingId = null;
    public string $editName = '';
    public int $editDuration = 30;
    public string $editPrice = '';
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

    public function create(CreateService $action, SyncServiceStylists $sync): void
    {
        $this->authorize('manageServices', $this->salon);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'duration_min' => ['required', 'integer', 'min:5', 'max:600'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'stylistIds' => ['array'],
            'stylistIds.*' => ['integer'],
        ]);

        // Single save: the service (colour auto-assigned in the action), then its
        // qualified stylists + per-stylist overrides — same path the edit uses.
        $service = $action->handle($this->salon, [
            'name' => $data['name'],
            'duration_min' => $data['duration_min'],
            'price_cents' => $this->toCents($data['price'] ?? null),
        ]);
        $sync->handle($this->salon, $service, $this->stylistOverrides($this->stylistIds, $this->durations, $this->buffers));

        unset($this->services);
        $this->reset(['name', 'duration_min', 'price', 'stylistIds', 'durations', 'buffers']);
        $this->duration_min = 30;

        Flux::toast(variant: 'success', text: __('Service created.'));
    }

    /**
     * Build the stylist => override map for SyncServiceStylists from the form
     * arrays. Blank duration = use the service default; blank/disabled buffer =
     * none. Shared by create and edit so both persist overrides identically.
     *
     * @param  array<int, int|string>  $ids
     * @param  array<int, string>  $durations
     * @param  array<int, string>  $buffers
     * @return array<int, array{duration_override: int|null, buffer_override: int|null}>
     */
    private function stylistOverrides(array $ids, array $durations, array $buffers): array
    {
        $overrides = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            $dur = (string) ($durations[$id] ?? '');
            $buf = (string) ($buffers[$id] ?? '');
            $overrides[$id] = [
                'duration_override' => $dur === '' ? null : max(5, min(600, (int) $dur)),
                'buffer_override' => $buf === '' ? null : max(0, min(120, (int) $buf)),
            ];
        }

        return $overrides;
    }

    public function startEdit(int $serviceId): void
    {
        $service = $this->service($serviceId);

        $this->editingId = $service->id;
        $this->editName = $service->name;
        $this->editDuration = $service->duration_min;
        $this->editPrice = $service->price_cents === null
            ? ''
            : ($service->price_cents % 100 === 0 ? (string) intdiv($service->price_cents, 100) : number_format($service->price_cents / 100, 2, '.', ''));
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
            'editPrice' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'editActive' => ['boolean'],
            'editStylistIds' => ['array'],
            'editStylistIds.*' => ['integer'],
        ]);

        $update->handle($this->salon, $service, [
            'name' => $data['editName'],
            'duration_min' => $data['editDuration'],
            'price_cents' => $this->toCents($data['editPrice'] ?? null),
            'active' => $data['editActive'],
        ]);

        // Assigned stylists → their (optional, clamped) overrides. Blank = use
        // the service default / no buffer; buffers ignored unless the flag is on.
        $sync->handle($this->salon, $service, $this->stylistOverrides($this->editStylistIds, $this->editDurations, $this->editBuffers));

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

    /** Blank form price = NULL ("price varies"); otherwise store integer cents. */
    private function toCents(int|float|string|null $price): ?int
    {
        return $price === null || $price === ''
            ? null
            : (int) round(((float) $price) * 100);
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Manage')" :title="__('Services')" />

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Add a service') }}</h2>
            <form wire:submit="create" class="flex flex-col gap-5">
                {{-- Default duration first, so the per-stylist override placeholder
                     below reflects it and "blank = service default" reads true. --}}
                <div class="grid items-end gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2"><flux:input wire:model="name" :label="__('Name')" required /></div>
                    <flux:input type="number" wire:model.live="duration_min" :label="__('Default duration (min)')" min="5" max="600" step="5" />
                    <flux:input type="number" wire:model="price" :label="__('Price (:symbol, optional)', ['symbol' => trim(\App\Support\Money::symbol($salon->currency))])" min="0" max="100000" step="0.01" :placeholder="__('Varies')" />
                </div>

                <x-ui.qualified-stylists
                    :stylists="$this->stylists"
                    ids-model="stylistIds"
                    durations-model="durations"
                    buffers-model="buffers"
                    :placeholder-duration="$duration_min"
                    />

                <div>
                    <x-ui.button type="submit" loading="create"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add service') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="overflow-x-auto" tabindex="0">
            <table class="w-full text-left">
                <thead>
                    <tr class="bts-overline border-b border-divider">
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Service') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Duration') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Price') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Stylists') }}</th>
                        <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Status') }}</th>
                        <th scope="col" class="px-6 py-3.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-row">
                    @forelse ($this->services as $service)
                        <tr @class(['bg-muted/40' => ! $service->active])>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2.5">
                                    <span class="size-3 rounded-full" style="background-color: {{ $service->palette()['dot'] }}"></span>
                                    <span class="text-[15px] font-medium text-ink">{{ $service->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $service->duration_min }} {{ __('min') }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">{{ $service->priceLabel($salon->currency) ?? __('Varies') }}</td>
                            <td class="px-6 py-4 text-[15px] text-secondary">
                                {{ $service->stylists->pluck('name')->join(', ') ?: __('None assigned') }}
                            </td>
                            <td class="px-6 py-4">
                                @if ($service->active)
                                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Active') }}</span>
                                @else
                                    <span class="bts-pill" style="background-color:#F0EEEA;color:#6B6862;">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-end gap-4">
                                    <button type="button" wire:click="startEdit({{ $service->id }})" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                                    <button type="button"
                                            @if ($service->active) wire:confirm="{{ __('Deactivate :service? Clients can no longer book it; existing bookings are unaffected.', ['service' => $service->name]) }}" @endif
                                            wire:click="toggleActive({{ $service->id }})" class="text-[13px] font-medium text-secondary transition hover:text-ink">
                                        {{ $service->active ? __('Deactivate') : __('Reactivate') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No services yet. Add one above.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </x-ui.card>
    </div>

    <x-ui.modal wire:model="showEdit" class="max-w-lg" :heading="__('Edit service')">
        <form wire:submit="saveEdit" class="flex flex-col gap-5">
            <flux:input wire:model="editName" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input type="number" wire:model.live="editDuration" :label="__('Default duration (min)')" min="5" max="600" step="5" />
                <flux:input type="number" wire:model="editPrice" :label="__('Price (:symbol, optional)', ['symbol' => trim(\App\Support\Money::symbol($salon->currency))])" min="0" max="100000" step="0.01" :placeholder="__('Varies')" />
            </div>
            <flux:checkbox wire:model="editActive" :label="__('Active')" />

            <x-ui.qualified-stylists
                :stylists="$this->stylists"
                ids-model="editStylistIds"
                durations-model="editDurations"
                buffers-model="editBuffers"
                :placeholder-duration="$editDuration"
                />

            <div class="flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="$set('showEdit', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
