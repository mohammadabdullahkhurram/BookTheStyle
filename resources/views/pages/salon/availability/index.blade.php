<?php

use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveTimeOff;
use App\Actions\Availability\SaveWeeklyHours;
use App\Models\Availability;
use App\Models\Salon;
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

    /**
     * The seven-day editing grid. Keyed by weekday (0 = Monday … 6 = Sunday):
     * each day has an on/off flag and zero or more inline work windows (a second
     * window = a split shift). Persisted as the same work-kind Availability rows
     * the slot engine already reads.
     *
     * @var array<int, array{on: bool, windows: list<array{start: string, end: string}>}>
     */
    public array $days = [];

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

        $this->loadWeek();
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

        $this->loadWeek();
    }

    /** Build the grid from the stylist's stored work windows. */
    private function loadWeek(): void
    {
        $byDay = array_fill(0, 7, []);

        if ($this->selectedStylistId !== 0) {
            $rows = Availability::query()
                ->where('salon_id', $this->salon->id)
                ->where('user_id', $this->selectedStylistId)
                ->where('kind', 'work')
                ->orderBy('weekday')
                ->orderBy('start_minute')
                ->get(['weekday', 'start_minute', 'end_minute']);

            foreach ($rows as $row) {
                $byDay[$row->weekday][] = [
                    'start' => $this->minutesToTime($row->start_minute),
                    'end' => $this->minutesToTime($row->end_minute),
                ];
            }
        }

        $days = [];
        foreach (range(0, 6) as $weekday) {
            $days[$weekday] = ['on' => $byDay[$weekday] !== [], 'windows' => $byDay[$weekday]];
        }

        $this->days = $days;
    }

    public function toggleDay(int $weekday): void
    {
        if (! isset($this->days[$weekday])) {
            return;
        }

        $on = ! $this->days[$weekday]['on'];
        $this->days[$weekday]['on'] = $on;

        // Turning a day on with nothing set prefills a sensible default shift.
        if ($on && $this->days[$weekday]['windows'] === []) {
            $this->days[$weekday]['windows'] = [['start' => '09:00', 'end' => '17:00']];
        }
    }

    public function addWindow(int $weekday): void
    {
        if (! isset($this->days[$weekday])) {
            return;
        }

        $this->days[$weekday]['on'] = true;
        $this->days[$weekday]['windows'][] = ['start' => '13:00', 'end' => '17:00'];
    }

    public function removeWindow(int $weekday, int $index): void
    {
        if (! isset($this->days[$weekday]['windows'][$index])) {
            return;
        }

        array_splice($this->days[$weekday]['windows'], $index, 1);

        if ($this->days[$weekday]['windows'] === []) {
            $this->days[$weekday]['on'] = false;
        }
    }

    public function copyToWeekdays(): void
    {
        $this->copyMondayTo(range(0, 4));
    }

    public function copyToAll(): void
    {
        $this->copyMondayTo(range(0, 6));
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function copyMondayTo(array $weekdays): void
    {
        $template = $this->days[0];

        foreach ($weekdays as $weekday) {
            $this->days[$weekday] = [
                'on' => $template['on'],
                'windows' => array_map(
                    fn (array $w): array => ['start' => $w['start'], 'end' => $w['end']],
                    $template['windows'],
                ),
            ];
        }
    }

    public function saveHours(SaveWeeklyHours $action): void
    {
        $week = [];

        foreach ($this->days as $weekday => $day) {
            $windows = [];

            if ($day['on']) {
                foreach ($day['windows'] as $window) {
                    $windows[] = [
                        'start_minute' => $this->minutesFromTime($window['start']),
                        'end_minute' => $this->minutesFromTime($window['end']),
                    ];
                }
            }

            $week[(int) $weekday] = $windows;
        }

        $action->handle(Auth::user(), $this->salon, $this->selectedStylistId, $week);

        $this->loadWeek();
        Flux::toast(variant: 'success', text: __('Weekly hours saved.'));
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
    public function timeOff()
    {
        return TimeOff::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $this->selectedStylistId)
            ->orderBy('starts_at')
            ->get();
    }

    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function minutesFromTime(string $time): int
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');

        return ((int) $h) * 60 + (int) $m;
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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('Schedule')" :title="__('Availability')" />

        @if ($this->isManager)
            <x-ui.card padding="p-5">
                <flux:select wire:model.live="selectedStylistId" :label="__('Stylist')">
                    <flux:select.option value="0">{{ __('— choose a stylist —') }}</flux:select.option>
                    @foreach ($this->stylists as $stylist)
                        <flux:select.option value="{{ $stylist->id }}">{{ $stylist->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </x-ui.card>
        @endif

        @if ($selectedStylistId === 0)
            <x-ui.card padding="p-10" class="text-center text-[15px] text-faint">
                {{ __('Choose a stylist to manage their availability. Add stylists on the Staff page.') }}
            </x-ui.card>
        @else
            <div x-data="{ tab: 'hours' }" class="flex flex-col gap-6">
                {{-- Two clear parts: the weekly grid, and one-off time off. --}}
                <div class="inline-flex w-fit rounded-[8px] bg-muted p-1">
                    <button type="button" @click="tab = 'hours'"
                            :class="tab === 'hours' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink'"
                            class="rounded-[6px] px-4 py-1.5 text-[14px] font-medium transition">{{ __('Weekly hours') }}</button>
                    <button type="button" @click="tab = 'off'"
                            :class="tab === 'off' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink'"
                            class="rounded-[6px] px-4 py-1.5 text-[14px] font-medium transition">{{ __('Time off') }}</button>
                </div>

                {{-- ───────────── Weekly hours grid ───────────── --}}
                <div x-show="tab === 'hours'" class="flex flex-col">
                    <x-ui.card class="flex flex-col gap-1">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 class="bts-card-title">{{ __('Weekly hours') }}</h2>
                                <p class="mt-1 text-[14px] text-secondary">{{ __('Toggle a day on to set its hours. Add a second window for a split shift; the gap is an unbookable break.') }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-[13px] text-secondary">{{ __('Copy Monday to') }}</span>
                                <x-ui.button size="sm" variant="secondary" wire:click="copyToWeekdays">{{ __('Weekdays') }}</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="copyToAll">{{ __('Every day') }}</x-ui.button>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-col divide-y divide-row">
                            @foreach ($weekdays as $wd => $label)
                                @php($day = $days[$wd])
                                <div class="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:gap-5">
                                    <div class="flex items-center gap-3 sm:w-40 sm:pt-2">
                                        <button type="button" role="switch" aria-checked="{{ $day['on'] ? 'true' : 'false' }}"
                                                wire:click="toggleDay({{ $wd }})" aria-label="{{ $label }}"
                                                class="relative h-[26px] w-[44px] shrink-0 rounded-full transition {{ $day['on'] ? 'bg-accent' : 'bg-muted' }}">
                                            <span class="absolute top-[3px] size-[20px] rounded-full bg-white shadow-sm transition-all {{ $day['on'] ? 'left-[21px]' : 'left-[3px]' }}"></span>
                                        </button>
                                        <span class="text-[15px] font-medium {{ $day['on'] ? 'text-ink' : 'text-faint' }}">{{ $label }}</span>
                                    </div>

                                    <div class="flex-1">
                                        @if ($day['on'])
                                            <div class="flex flex-col gap-2.5">
                                                @foreach ($day['windows'] as $i => $window)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <flux:input type="time" wire:model="days.{{ $wd }}.windows.{{ $i }}.start" class="w-[132px]" aria-label="{{ __('Start') }}" />
                                                        <span class="text-[14px] text-secondary">{{ __('to') }}</span>
                                                        <flux:input type="time" wire:model="days.{{ $wd }}.windows.{{ $i }}.end" class="w-[132px]" aria-label="{{ __('End') }}" />
                                                        @if (count($day['windows']) > 1)
                                                            <button type="button" wire:click="removeWindow({{ $wd }}, {{ $i }})"
                                                                    class="rounded-[9px] p-1.5 text-fainter transition hover:bg-muted hover:text-danger" aria-label="{{ __('Remove window') }}">
                                                                <flux:icon.x-mark variant="micro" />
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                <div>
                                                    <button type="button" wire:click="addWindow({{ $wd }})"
                                                            class="inline-flex items-center gap-1 text-[13px] font-semibold text-accent transition hover:text-accent-hover">
                                                        <flux:icon.plus variant="micro" class="shrink-0" />{{ __('add hours') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-[14px] text-faint sm:pt-2">{{ __('Day off') }}</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @error('weekly')
                            <div class="mt-1 text-[14px] text-danger">{{ $message }}</div>
                        @enderror

                        <div class="mt-3 flex justify-end border-t border-divider pt-4">
                            <x-ui.button wire:click="saveHours">{{ __('Save weekly hours') }}</x-ui.button>
                        </div>
                    </x-ui.card>
                </div>

                {{-- ───────────── Time off ───────────── --}}
                <div x-show="tab === 'off'" x-cloak class="flex flex-col gap-6">
                    <x-ui.card class="flex flex-col gap-4">
                        <div>
                            <h2 class="bts-card-title">{{ __('Add time off') }}</h2>
                            <p class="mt-1 text-[14px] text-secondary">{{ __('Block out vacation, sick days, or any one-off unavailable stretch.') }}</p>
                        </div>
                        <form wire:submit="addTimeOff" class="flex flex-col gap-4">
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
                            <div><x-ui.button type="submit"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add time off') }}</x-ui.button></div>
                            @error('ends_at') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
                        </form>
                    </x-ui.card>

                    <x-ui.card padding="p-0" class="overflow-hidden">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bts-overline border-b border-divider">
                                    <th class="px-6 py-3.5 font-semibold">{{ __('Type') }}</th>
                                    <th class="px-6 py-3.5 font-semibold">{{ __('From') }}</th>
                                    <th class="px-6 py-3.5 font-semibold">{{ __('To') }}</th>
                                    <th class="px-6 py-3.5 font-semibold">{{ __('Note') }}</th>
                                    <th class="px-6 py-3.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-row">
                                @forelse ($this->timeOff as $off)
                                    <tr>
                                        <td class="px-6 py-4 text-[15px] font-medium text-ink">{{ $off->type->label() }}</td>
                                        <td class="px-6 py-4 text-[15px] text-secondary">{{ $off->starts_at->format('M j, Y g:i A') }}</td>
                                        <td class="px-6 py-4 text-[15px] text-secondary">{{ $off->ends_at->format('M j, Y g:i A') }}</td>
                                        <td class="px-6 py-4 text-[15px] text-secondary">{{ $off->note ?: '—' }}</td>
                                        <td class="px-6 py-4 text-right">
                                            <button type="button" wire:click="removeTimeOff({{ $off->id }})" class="text-[13px] font-medium text-secondary transition hover:text-danger">{{ __('Remove') }}</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-6 py-10 text-center text-[15px] text-faint">{{ __('No time off scheduled.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </x-ui.card>
                </div>
            </div>
        @endif
    </div>
</div>
