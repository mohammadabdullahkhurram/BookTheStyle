<?php

use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveTimeOff;
use App\Actions\Availability\SaveWeeklyHours;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Support\AvailabilitySummary;
use App\Support\Permissions\AvailabilityAccess;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Availability')] class extends Component {
    public Salon $salon;

    // The stylist whose schedule the panel shows; 0 = nobody yet.
    public int $selectedStylistId = 0;

    // The right-side detail panel (cards → panel → edit).
    public bool $panelOpen = false;
    public bool $editing = false;
    public string $panelTab = 'hours'; // 'hours' | 'dates'

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
        // Every member of the salon may VIEW the staff schedules; editing is
        // gated per stylist by AvailabilityAccess (and again in the actions).
        abort_unless(Auth::user()->can('view', $salon), 403);
        $this->salon = $salon;

        if (Auth::user()->stylistMembershipFor($salon) !== null) {
            $this->selectedStylistId = (int) Auth::id();
        } elseif (Auth::user()->can('manage', $salon)) {
            $this->selectedStylistId = (int) ($salon->stylistUsers()->orderBy('name')->value('users.id') ?? 0);
        }

        $this->loadWeek();
    }

    private function access(): AvailabilityAccess
    {
        return new AvailabilityAccess;
    }

    /**
     * The staff cards: every stylist, the current user's own card first, the
     * rest alphabetical — each with a one-line weekly summary.
     *
     * @return list<array{id: int, name: string, summary: string, is_self: bool}>
     */
    #[Computed]
    public function cards(): array
    {
        $windows = Availability::query()
            ->where('salon_id', $this->salon->id)
            ->where('kind', 'work')
            ->get(['user_id', 'weekday', 'start_minute', 'end_minute'])
            ->groupBy('user_id');

        return $this->salon->stylistUsers()
            ->orderBy('name')
            ->get(['users.id', 'name'])
            ->map(fn ($stylist): array => [
                'id' => (int) $stylist->id,
                'name' => $stylist->name,
                'is_self' => (int) $stylist->id === (int) Auth::id(),
                'summary' => AvailabilitySummary::line(
                    ($windows[$stylist->id] ?? collect())
                        ->groupBy('weekday')
                        ->map(fn ($rows) => $rows->map(fn ($r): array => [(int) $r->start_minute, (int) $r->end_minute])->all())
                        ->all(),
                ),
            ])
            ->sortByDesc('is_self')
            ->values()
            ->all();
    }

    #[Computed]
    public function selectedStylist()
    {
        return $this->selectedStylistId === 0
            ? null
            : $this->salon->stylistUsers()->whereKey($this->selectedStylistId)->first(['users.id', 'name']);
    }

    /** Whether the current user may edit the SELECTED stylist's schedule. */
    #[Computed]
    public function canEditSelected(): bool
    {
        return $this->selectedStylistId !== 0
            && $this->access()->canManage(Auth::user(), $this->salon, $this->selectedStylistId);
    }

    #[Computed]
    public function isManager(): bool
    {
        return Auth::user()->can('manage', $this->salon);
    }

    public function openPanel(int $stylistId): void
    {
        abort_unless($this->salon->stylistUsers()->whereKey($stylistId)->exists(), 404);

        $this->selectedStylistId = $stylistId;
        $this->editing = false;
        $this->panelTab = 'hours';
        $this->panelOpen = true;
        $this->resetValidation();
        $this->loadWeek();
        unset($this->selectedStylist, $this->canEditSelected, $this->timeOff);
    }

    public function closePanel(): void
    {
        $this->panelOpen = false;
        $this->editing = false;

        // Send focus back to the card that opened the drawer (a11y).
        $this->dispatch('availability-panel-closed', stylistId: $this->selectedStylistId);
    }

    /** Switch the panel into edit mode — server-gated, never trust the button. */
    public function startEditing(): void
    {
        abort_unless($this->canEditSelected, 403);

        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->editing = false;
        $this->resetValidation();
        $this->loadWeek(); // discard unsaved grid edits
    }

    /** Anyone may LOOK at any stylist of the salon; editing stays gated. */
    public function updatedSelectedStylistId(): void
    {
        if (! $this->salon->stylistUsers()->whereKey($this->selectedStylistId)->exists()) {
            $this->selectedStylistId = 0;
        }

        $this->editing = false;
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
        $this->copyDay(0, range(0, 4));
    }

    public function copyToAll(): void
    {
        $this->copyDay(0, range(0, 6));
    }

    /** The per-row duplicate action: copy THIS day's blocks to every day. */
    public function copyDayToAll(int $weekday): void
    {
        if (isset($this->days[$weekday])) {
            $this->copyDay($weekday, range(0, 6));
        }
    }

    /**
     * @param  list<int>  $weekdays
     */
    private function copyDay(int $from, array $weekdays): void
    {
        $template = $this->days[$from];

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
        $this->editing = false;
        unset($this->cards); // the card summary reflects the new hours
        Flux::toast(variant: 'success', text: __('Weekly hours saved.'));
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

    /** "09:00"–"17:00" → "9:00 AM – 5:00 PM" for the read views. */
    public function formatWindow(array $window): string
    {
        return AvailabilitySummary::minutes($this->minutesFromTime($window['start']))
            .' – '.AvailabilitySummary::minutes($this->minutesFromTime($window['end']));
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

{{-- The root listens for the drawer-closed event (the drawer itself is gone
     from the DOM by the time it fires) and returns focus to the card. --}}
<div x-data
     x-on:availability-panel-closed.window="document.getElementById('availability-card-' + $event.detail.stylistId)?.focus()">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="__('Schedule')" :title="__('Availability')">
            <x-slot:subtitle>{{ __('Weekly hours and date-specific time off for every stylist. Select a card to view a schedule.') }}</x-slot:subtitle>
        </x-ui.page-header>

        @php($cards = collect($this->cards))
        @php($own = $cards->firstWhere('is_self', true))
        @php($others = $cards->where('is_self', false)->values())

        @if ($cards->isEmpty())
            <x-ui.card padding="p-10" class="text-center text-[15px] text-faint">
                {{ __('No stylists yet. Add stylists on the Staff page to manage their availability.') }}
            </x-ui.card>
        @else
            {{-- ─────────── Staff cards: own schedule first ─────────── --}}
            @if ($own)
                <div class="grid gap-4 md:grid-cols-2">
                    <x-availability.staff-card :card="$own" :selected="$panelOpen && $selectedStylistId === $own['id']" :badge="__('You')" />
                </div>
            @endif

            @if ($others->isNotEmpty())
                @if ($own)
                    <h2 class="bts-overline -mb-3">{{ __('Other staff members') }}</h2>
                @endif
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($others as $card)
                        <x-availability.staff-card :card="$card" :selected="$panelOpen && $selectedStylistId === $card['id']" />
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    {{-- ─────────── Right-docked schedule drawer ───────────
         Teleported to <body> so no layout ancestor (stacking context,
         transform, overflow) can pull it into the page flow; the flex
         justify-end wrapper keeps it pinned to the RIGHT edge with the
         cards still visible beside it. Full-width only on narrow screens. --}}
    @if ($panelOpen && $this->selectedStylist)
        @php($stylist = $this->selectedStylist)
        <template x-teleport="body">
            <div class="fixed inset-0 z-50 flex justify-end" x-data
                 @keydown.escape.window="$wire.closePanel()">
                <div class="bts-scrim absolute inset-0 bg-ink/25" wire:click="closePanel" aria-hidden="true"></div>

                <div class="bts-drawer relative flex h-full w-full flex-col overflow-y-auto border-l border-border bg-card shadow-xl sm:w-[460px]"
                     role="dialog" aria-modal="true" aria-label="{{ __(':name — availability', ['name' => $stylist->name]) }}"
                     tabindex="-1" x-init="$el.focus()">

            {{-- Header: person + edit/close --}}
            <div class="flex items-start justify-between gap-4 border-b border-divider px-7 py-6">
                <div class="flex items-center gap-3.5">
                    <x-ui.avatar :name="$stylist->name" :seed="$stylist->id" size="lg" />
                    <div class="flex flex-col">
                        <h2 class="font-display text-[18px] font-bold text-ink">{{ $stylist->name }}</h2>
                        <p class="text-[13.5px] text-secondary">{{ $cards->firstWhere('id', (int) $stylist->id)['summary'] ?? '' }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @if ($this->canEditSelected && ! $editing)
                        <x-ui.button size="sm" variant="secondary" wire:click="startEditing">
                            <flux:icon.pencil-square variant="micro" class="shrink-0" />{{ __('Edit') }}
                        </x-ui.button>
                    @endif
                    <button type="button" wire:click="closePanel" aria-label="{{ __('Close panel') }}"
                            class="rounded-[9px] p-2 text-fainter transition hover:bg-muted hover:text-ink">
                        <flux:icon.x-mark variant="mini" />
                    </button>
                </div>
            </div>

            <div class="flex flex-1 flex-col gap-6 px-7 py-6">
                {{-- Tabs --}}
                <div class="inline-flex w-fit rounded-[8px] bg-muted p-1" role="tablist">
                    <button type="button" role="tab" aria-selected="{{ $panelTab === 'hours' ? 'true' : 'false' }}"
                            wire:click="$set('panelTab', 'hours')"
                            class="rounded-[6px] px-4 py-1.5 text-[14px] font-medium transition {{ $panelTab === 'hours' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink' }}">
                        {{ __('Weekly hours') }}
                    </button>
                    <button type="button" role="tab" aria-selected="{{ $panelTab === 'dates' ? 'true' : 'false' }}"
                            wire:click="$set('panelTab', 'dates')"
                            class="rounded-[6px] px-4 py-1.5 text-[14px] font-medium transition {{ $panelTab === 'dates' ? 'bg-card text-ink shadow-xs' : 'text-secondary hover:text-ink' }}">
                        {{ __('Date-specific hours') }}
                    </button>
                </div>

                @if (! $this->canEditSelected)
                    <p class="-mt-2 text-[13px] text-faint">{{ __('Read-only — you can only edit your own availability.') }}</p>
                @endif

                {{-- ───── Weekly hours ───── --}}
                @if ($panelTab === 'hours')
                    @if (! $editing)
                        {{-- Read view --}}
                        <div class="flex flex-col divide-y divide-row rounded-[18px] border border-border">
                            @foreach ($weekdays as $wd => $label)
                                @php($day = $days[$wd])
                                <div class="flex items-center justify-between gap-4 px-5 py-3.5">
                                    <span class="text-[15px] font-medium {{ $day['on'] ? 'text-ink' : 'text-faint' }}">{{ $label }}</span>
                                    <span class="text-[14.5px] {{ $day['on'] ? 'text-body' : 'text-faint' }}">
                                        @if ($day['on'])
                                            {{ collect($day['windows'])->map(fn ($w) => $this->formatWindow($w))->implode(', ') }}
                                        @else
                                            {{ __('Day off') }}
                                        @endif
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        {{-- Edit view: one compact horizontal row per weekday —
                             [checkbox + day] [Start time] [End time] [actions].
                             A split shift adds an aligned second row of fields. --}}
                        <div class="flex flex-col gap-1">
                            <div class="flex flex-col gap-1">
                                <p class="text-[14px] text-secondary">{{ __('Check a day to set its hours. A second time block makes a split shift; the gap is unbookable.') }}</p>
                                <p class="text-[12.5px] text-faint">{{ __('Times are in :timezone.', ['timezone' => $salon->timezone]) }}</p>
                                <div class="mt-1 flex items-center gap-2">
                                    <span class="text-[13px] text-secondary">{{ __('Copy Monday to') }}</span>
                                    <x-ui.button size="sm" variant="secondary" wire:click="copyToWeekdays">{{ __('Weekdays') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" wire:click="copyToAll">{{ __('Every day') }}</x-ui.button>
                                </div>
                            </div>

                            <div class="mt-2 flex flex-col divide-y divide-row">
                                @foreach ($weekdays as $wd => $label)
                                    @php($day = $days[$wd])
                                    <div class="flex items-start gap-3 py-3">
                                        {{-- Column 1: checkbox + short day name --}}
                                        <label class="flex w-[72px] shrink-0 cursor-pointer items-center gap-2.5 {{ $day['on'] ? 'pt-[30px]' : 'pt-1.5' }}">
                                            <input type="checkbox" @checked($day['on']) wire:click="toggleDay({{ $wd }})"
                                                   aria-label="{{ $label }}"
                                                   class="size-[17px] shrink-0 cursor-pointer rounded-[4px] border-input accent-accent" />
                                            <span class="text-[14.5px] font-medium {{ $day['on'] ? 'text-ink' : 'text-faint' }}">{{ mb_substr($label, 0, 3) }}</span>
                                        </label>

                                        {{-- Columns 2–4: time blocks + action icons, or "Unavailable" --}}
                                        <div class="min-w-0 flex-1">
                                            @if ($day['on'])
                                                <div class="flex flex-col gap-2">
                                                    @foreach ($day['windows'] as $i => $window)
                                                        <div class="flex items-end gap-2">
                                                            <div class="min-w-0 flex-1">
                                                                @if ($i === 0)
                                                                    <div class="mb-1 text-[12.5px] font-medium text-faint">{{ __('Start time') }}</div>
                                                                @endif
                                                                <flux:input type="time" wire:model="days.{{ $wd }}.windows.{{ $i }}.start" aria-label="{{ __(':day start time', ['day' => $label]) }}" />
                                                            </div>
                                                            <div class="min-w-0 flex-1">
                                                                @if ($i === 0)
                                                                    <div class="mb-1 text-[12.5px] font-medium text-faint">{{ __('End time') }}</div>
                                                                @endif
                                                                <flux:input type="time" wire:model="days.{{ $wd }}.windows.{{ $i }}.end" aria-label="{{ __(':day end time', ['day' => $label]) }}" />
                                                            </div>
                                                            {{-- Fixed-width action column so a split shift's
                                                                 fields align exactly under the first block's. --}}
                                                            <div class="flex w-[96px] shrink-0 items-center justify-end gap-0.5 pb-1.5">
                                                                @if ($i === 0)
                                                                    <button type="button" wire:click="addWindow({{ $wd }})" title="{{ __('Add a time block') }}"
                                                                            class="rounded-[9px] p-1.5 text-fainter transition hover:bg-muted hover:text-accent" aria-label="{{ __('Add a time block to :day', ['day' => $label]) }}">
                                                                        <flux:icon.plus variant="micro" />
                                                                    </button>
                                                                    <button type="button" wire:click="copyDayToAll({{ $wd }})" title="{{ __('Copy to every day') }}"
                                                                            class="rounded-[9px] p-1.5 text-fainter transition hover:bg-muted hover:text-accent" aria-label="{{ __('Copy :day to every day', ['day' => $label]) }}">
                                                                        <flux:icon.document-duplicate variant="micro" />
                                                                    </button>
                                                                @endif
                                                                <button type="button" wire:click="removeWindow({{ $wd }}, {{ $i }})" title="{{ __('Remove') }}"
                                                                        class="rounded-[9px] p-1.5 text-fainter transition hover:bg-muted hover:text-danger" aria-label="{{ __('Remove this time block') }}">
                                                                    <flux:icon.trash variant="micro" />
                                                                </button>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @else
                                                <div class="pt-1.5 text-[14px] text-faint">{{ __('Unavailable') }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            @error('weekly')
                                <div class="mt-1 text-[14px] text-danger">{{ $message }}</div>
                            @enderror

                            <div class="mt-3 flex justify-end gap-2 border-t border-divider pt-4">
                                <x-ui.button variant="secondary" wire:click="cancelEditing">{{ __('Cancel') }}</x-ui.button>
                                <x-ui.button wire:click="saveHours">{{ __('Save weekly hours') }}</x-ui.button>
                            </div>
                        </div>
                    @endif
                @endif

                {{-- ───── Date-specific hours (time off) ───── --}}
                @if ($panelTab === 'dates')
                    @if ($editing)
                        <div class="flex flex-col gap-4 rounded-[18px] border border-border bg-paper p-5">
                            <div>
                                <h3 class="text-[16px] font-semibold text-ink">{{ __('Add time off') }}</h3>
                                <p class="mt-1 text-[14px] text-secondary">{{ __('Block out vacation, sick days, or any one-off unavailable stretch.') }}</p>
                            </div>
                            <form wire:submit="addTimeOff" class="flex flex-col gap-4">
                                <div class="grid items-end gap-4 sm:grid-cols-2">
                                    <flux:select wire:model="toType" :label="__('Type')">
                                        <flux:select.option value="vacation">{{ __('Vacation') }}</flux:select.option>
                                        <flux:select.option value="sick">{{ __('Sick') }}</flux:select.option>
                                        <flux:select.option value="blocked">{{ __('Blocked') }}</flux:select.option>
                                    </flux:select>
                                    <flux:input wire:model="toNote" :label="__('Note (optional)')" />
                                    <flux:input type="datetime-local" wire:model="toStart" :label="__('Starts')" />
                                    <flux:input type="datetime-local" wire:model="toEnd" :label="__('Ends')" />
                                </div>
                                <div><x-ui.button type="submit"><flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add time off') }}</x-ui.button></div>
                                @error('ends_at') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
                            </form>
                        </div>
                    @endif

                    <div class="flex flex-col divide-y divide-row rounded-[18px] border border-border">
                        @forelse ($this->timeOff as $off)
                            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                <div class="flex flex-col">
                                    <span class="text-[15px] font-medium text-ink">{{ $off->type->label() }}</span>
                                    <span class="text-[14px] text-secondary">
                                        {{ $off->starts_at->setTimezone($salon->timezone)->format('M j, Y g:i A') }}
                                        – {{ $off->ends_at->setTimezone($salon->timezone)->format('M j, Y g:i A') }}
                                    </span>
                                    @if ($off->note)
                                        <span class="text-[13px] text-faint">{{ $off->note }}</span>
                                    @endif
                                </div>
                                @if ($editing)
                                    <button type="button" wire:click="removeTimeOff({{ $off->id }})"
                                            class="text-[13px] font-medium text-secondary transition hover:text-danger">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-[15px] text-faint">{{ __('No date-specific hours — the weekly schedule applies.') }}</div>
                        @endforelse
                    </div>
                @endif
                </div>
            </div>
            </div>
        </template>
    @endif
</div>
