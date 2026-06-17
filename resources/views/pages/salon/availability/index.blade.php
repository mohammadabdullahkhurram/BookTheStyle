<?php

use App\Actions\Availability\AddAvailabilityWindow;
use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveAvailabilityWindow;
use App\Actions\Availability\RemoveTimeOff;
use App\Actions\Stylists\UpdateStylistProfile;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Models\TimeOff;
use App\Support\Permissions\AvailabilityAccess;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Availability')] class extends Component {
    public Salon $salon;
    public int $selectedStylistId = 0;
    public string $bio = '';

    // Add-window form
    public int $addWeekday = 0;
    public string $addKind = 'work';
    public string $addStart = '09:00';
    public string $addEnd = '17:00';

    // Add-time-off form
    public string $toType = 'vacation';
    public string $toNote = '';
    public string $toStart = '';
    public string $toEnd = '';

    /** @var array<int, string> */
    public array $weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function mount(Salon $salon): void
    {
        abort_unless($this->access()->canManageAny(Auth::user(), $salon), 403);
        $this->salon = $salon;

        if (Auth::user()->can('manage', $salon)) {
            $this->selectedStylistId = (int) ($salon->stylistUsers()->orderBy('name')->value('users.id') ?? 0);
        } else {
            $this->selectedStylistId = (int) Auth::id();
        }

        $this->loadBio();
    }

    private function access(): AvailabilityAccess
    {
        return new AvailabilityAccess;
    }

    public function updatedSelectedStylistId(): void
    {
        // Managers may switch stylists; a stylist is locked to themselves.
        if (! Auth::user()->can('manage', $this->salon)) {
            $this->selectedStylistId = (int) Auth::id();
        } elseif (! $this->salon->stylistUsers()->whereKey($this->selectedStylistId)->exists()) {
            $this->selectedStylistId = 0;
        }

        $this->loadBio();
    }

    private function loadBio(): void
    {
        $this->bio = (string) StylistProfile::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $this->selectedStylistId)
            ->value('bio');
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manage', $this->salon);
    }

    #[Computed]
    public function stylists()
    {
        return $this->salon->stylistUsers()->orderBy('name')->get(['users.id', 'name']);
    }

    #[Computed]
    public function windows()
    {
        return Availability::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $this->selectedStylistId)
            ->orderBy('weekday')
            ->orderBy('start_minute')
            ->get();
    }

    #[Computed]
    public function timeOff()
    {
        return TimeOff::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $this->selectedStylistId)
            ->orderBy('starts_at')
            ->get();
    }

    public function formatMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    public function saveBio(UpdateStylistProfile $action): void
    {
        $this->validate(['bio' => ['nullable', 'string', 'max:2000']]);

        $action->handle(Auth::user(), $this->salon, $this->selectedStylistId, $this->bio ?: null);

        Flux::toast(variant: 'success', text: __('Profile saved.'));
    }

    public function addWindow(AddAvailabilityWindow $action): void
    {
        $this->validate([
            'addWeekday' => ['required', 'integer', 'between:0,6'],
            'addKind' => ['required', 'in:work,break'],
            'addStart' => ['required', 'date_format:H:i'],
            'addEnd' => ['required', 'date_format:H:i'],
        ]);

        $action->handle(Auth::user(), $this->salon, $this->selectedStylistId, [
            'weekday' => $this->addWeekday,
            'kind' => $this->addKind,
            'start_minute' => $this->toMinutes($this->addStart),
            'end_minute' => $this->toMinutes($this->addEnd),
        ]);

        unset($this->windows);
        Flux::toast(variant: 'success', text: __('Window added.'));
    }

    public function removeWindow(int $id, RemoveAvailabilityWindow $action): void
    {
        $window = Availability::query()->where('salon_id', $this->salon->id)->whereKey($id)->firstOrFail();
        $action->handle(Auth::user(), $this->salon, $window);
        unset($this->windows);

        Flux::toast(variant: 'success', text: __('Window removed.'));
    }

    public function addTimeOff(AddTimeOff $action): void
    {
        $this->validate([
            'toType' => ['required', 'in:vacation,sick,blocked'],
            'toNote' => ['nullable', 'string', 'max:255'],
            'toStart' => ['required', 'date'],
            'toEnd' => ['required', 'date'],
        ]);

        $action->handle(Auth::user(), $this->salon, $this->selectedStylistId, [
            'type' => $this->toType,
            'note' => $this->toNote ?: null,
            'starts_at' => $this->toStart,
            'ends_at' => $this->toEnd,
        ]);

        unset($this->timeOff);
        $this->reset(['toNote', 'toStart', 'toEnd']);
        $this->toType = 'vacation';

        Flux::toast(variant: 'success', text: __('Time off added.'));
    }

    public function removeTimeOff(int $id, RemoveTimeOff $action): void
    {
        $model = TimeOff::query()->where('salon_id', $this->salon->id)->whereKey($id)->firstOrFail();
        $action->handle(Auth::user(), $this->salon, $model);
        unset($this->timeOff);

        Flux::toast(variant: 'success', text: __('Time off removed.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-secondary">{{ $salon->name }}</flux:text>
                <flux:heading size="xl" class="font-serif">{{ __('Availability') }}</flux:heading>
            </div>
            <x-salon-nav :salon="$salon" />
        </div>

        @if ($this->isManager)
            <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                <flux:select wire:model.live="selectedStylistId" :label="__('Stylist')">
                    <flux:select.option value="0">{{ __('— choose a stylist —') }}</flux:select.option>
                    @foreach ($this->stylists as $stylist)
                        <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        @if ($selectedStylistId === 0)
            <div class="rounded-xl border border-border bg-card p-8 text-center text-secondary shadow-sm">
                {{ __('Choose a stylist to manage their availability. Add stylists on the Staff page.') }}
            </div>
        @else
            <form wire:submit="saveBio" class="flex flex-col gap-3 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Stylist bio') }}</flux:heading>
                <flux:textarea wire:model="bio" rows="3" :placeholder="__('A short bio for this stylist.')" />
                <div><flux:button type="submit" variant="primary" size="sm">{{ __('Save bio') }}</flux:button></div>
            </form>

            <form wire:submit="addWindow" class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Add weekly window') }}</flux:heading>
                <div class="grid items-end gap-4 sm:grid-cols-4">
                    <flux:select wire:model="addWeekday" :label="__('Day')">
                        @foreach ($weekdays as $i => $label)
                            <flux:select.option value="{{ $i }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="addKind" :label="__('Kind')">
                        <flux:select.option value="work">{{ __('Working hours') }}</flux:select.option>
                        <flux:select.option value="break">{{ __('Break') }}</flux:select.option>
                    </flux:select>
                    <flux:input type="time" wire:model="addStart" :label="__('Start')" />
                    <flux:input type="time" wire:model="addEnd" :label="__('End')" />
                </div>
                <div><flux:button type="submit" variant="primary" icon="plus">{{ __('Add window') }}</flux:button></div>
                @error('end_minute') <flux:text class="text-sm text-danger">{{ $message }}</flux:text> @enderror
            </form>

            <div class="rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Weekly schedule') }}</flux:heading>
                <div class="mt-4 grid gap-3">
                    @foreach ($weekdays as $i => $label)
                        @php($dayWindows = $this->windows->where('weekday', $i))
                        <div class="flex items-start gap-4 border-b border-border pb-3 last:border-0 last:pb-0">
                            <div class="w-28 shrink-0 pt-1 font-medium text-ink">{{ $label }}</div>
                            <div class="flex flex-1 flex-wrap gap-2">
                                @forelse ($dayWindows as $w)
                                    <span class="inline-flex items-center gap-2 rounded-md border border-border px-2.5 py-1 text-sm
                                        {{ $w->kind->value === 'work' ? 'bg-accent-soft text-accent' : 'bg-muted text-secondary' }}">
                                        {{ $w->kind->label() }}: {{ $this->formatMinutes($w->start_minute) }}–{{ $this->formatMinutes($w->end_minute) }}
                                        <button type="button" wire:click="removeWindow({{ $w->id }})" class="text-secondary hover:text-danger" aria-label="{{ __('Remove') }}">&times;</button>
                                    </span>
                                @empty
                                    <span class="pt-1 text-sm text-secondary">{{ __('Off') }}</span>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <form wire:submit="addTimeOff" class="flex flex-col gap-4 rounded-xl border border-border bg-card p-6 shadow-sm">
                <flux:heading size="sm" class="font-serif">{{ __('Add time off') }}</flux:heading>
                <div class="grid items-end gap-4 sm:grid-cols-4">
                    <flux:select wire:model="toType" :label="__('Type')">
                        <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                        <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                        <flux:select.option value="blocked">{{ __('Blocked') }}</flux:select.option>
                    </flux:select>
                    <flux:input type="datetime-local" wire:model="toStart" :label="__('Starts')" />
                    <flux:input type="datetime-local" wire:model="toEnd" :label="__('Ends')" />
                    <flux:input wire:model="toNote" :label="__('Note (optional)')" />
                </div>
                <div><flux:button type="submit" variant="primary" icon="plus">{{ __('Add time off') }}</flux:button></div>
                @error('ends_at') <flux:text class="text-sm text-danger">{{ $message }}</flux:text> @enderror
            </form>

            <div class="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-xs uppercase tracking-wide text-secondary">
                        <tr>
                            <th class="px-4 py-3 font-medium">{{ __('Type') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('From') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('To') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('Note') }}</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($this->timeOff as $off)
                            <tr>
                                <td class="px-4 py-3 text-ink">{{ $off->type->label() }}</td>
                                <td class="px-4 py-3 text-secondary">{{ $off->starts_at->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3 text-secondary">{{ $off->ends_at->format('M j, Y g:i A') }}</td>
                                <td class="px-4 py-3 text-secondary">{{ $off->note ?: '—' }}</td>
                                <td class="px-4 py-3 text-right">
                                    <flux:button size="xs" variant="ghost" wire:click="removeTimeOff({{ $off->id }})">{{ __('Remove') }}</flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-secondary">{{ __('No time off scheduled.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
