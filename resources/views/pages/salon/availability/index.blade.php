<?php

use App\Actions\Availability\AddTimeOff;
use App\Actions\Availability\RemoveTimeOff;
use App\Actions\Availability\SaveWeeklyHours;
use App\Models\Availability;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Support\AvailabilitySummary;
use App\Jobs\SyncAvailabilityToGhl;
use App\Support\Permissions\AvailabilityAccess;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    // ── Date-specific hours: the add/edit calendar modal ─────────────────
    // A "date-specific entry" is a TimeOff row: dates + times the stylist is
    // UNAVAILABLE, overriding the weekly schedule (the engine subtracts them;
    // 6e pushes them to GHL as date-specific overrides). The modal selects
    // one or more dates, then all-day or one/more time blocks.
    public bool $dsModalOpen = false;
    public ?int $dsEditId = null;      // editing this TimeOff row (null = adding)
    public string $dsMonth = '';       // calendar month shown, 'Y-m'
    /** @var list<string> selected dates, 'Y-m-d' in the salon timezone */
    public array $dsDates = [];
    /** @var list<array{start: string, end: string}> AVAILABLE blocks (HH:MM); empty = unavailable that date */
    public array $dsBlocks = [['start' => '09:00', 'end' => '17:00']];

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

    public function openPanel(int $stylistId): void
    {
        abort_unless($this->salon->stylistUsers()->whereKey($stylistId)->exists(), 404);

        $this->selectedStylistId = $stylistId;
        $this->editing = false;
        $this->copySource = null;
        $this->dsModalOpen = false;
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
        $this->copySource = null;
        $this->dsModalOpen = false;

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
        $this->copySource = null;
        $this->dsModalOpen = false;
        $this->resetValidation();
        $this->loadWeek(); // discard unsaved grid edits
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

    // ── "Copy times to…" popover (per-row duplicate action) ──────────────
    // copySource is the weekday whose times are being copied (null = closed);
    // copyTargets maps weekday → checked. Applying copies ALL of the source
    // day's blocks (split shifts included) onto every checked day.

    public ?int $copySource = null;

    public bool $copyAll = false;

    /** @var array<int, bool> */
    public array $copyTargets = [];

    public function openCopyPopover(int $weekday): void
    {
        if (! isset($this->days[$weekday])) {
            return;
        }

        $this->copySource = $weekday;
        $this->copyAll = false;
        $this->copyTargets = array_fill(0, 7, false);
        $this->copyTargets[$weekday] = true; // the source is itself, shown checked + disabled
    }

    public function closeCopyPopover(): void
    {
        $this->copySource = null;
    }

    public function updatedCopyAll(): void
    {
        foreach (range(0, 6) as $weekday) {
            $this->copyTargets[$weekday] = $this->copyAll || $weekday === $this->copySource;
        }
    }

    public function updatedCopyTargets(): void
    {
        $this->copyAll = ! in_array(false, $this->copyTargets, true);
    }

    public function applyCopy(): void
    {
        if ($this->copySource === null || ! isset($this->days[$this->copySource])) {
            return;
        }

        $targets = array_keys(array_filter($this->copyTargets, fn (bool $checked): bool => $checked));

        $this->copyDay($this->copySource, array_values(array_diff($targets, [$this->copySource])));
        $this->closeCopyPopover();
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
        $this->copySource = null;
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

    /** Open the add-dates calendar modal (server-gated like every edit). */
    public function openDateSpecific(): void
    {
        abort_unless($this->canEditSelected, 403);

        $this->resetValidation();
        $this->dsEditId = null;
        $this->dsDates = [];
        $this->dsBlocks = [['start' => '09:00', 'end' => '17:00']];
        $this->dsMonth = CarbonImmutable::now($this->salon->timezone)->format('Y-m');
        $this->dsModalOpen = true;
    }

    /** Re-open the modal prefilled from an existing entry (pencil icon). */
    public function editDateSpecific(int $timeOffId): void
    {
        abort_unless($this->canEditSelected, 403);

        $off = TimeOff::query()
            ->where('salon_id', $this->salon->id)
            ->where('user_id', $this->selectedStylistId)
            ->findOrFail($timeOffId);

        $tz = $this->salon->timezone;
        $start = $off->starts_at->setTimezone($tz);
        $end = $off->ends_at->setTimezone($tz);

        $this->resetValidation();
        $this->dsEditId = $off->id;
        $this->dsDates = [$start->toDateString()];
        // HOURS entries prefill their available range; OFF entries prefill
        // with no blocks (= unavailable that date).
        $this->dsBlocks = $off->kind === TimeOff::KIND_HOURS
            ? [[
                'start' => $start->format('H:i'),
                'end' => $start->isSameDay($end) ? $end->format('H:i') : '23:59',
            ]]
            : [];
        $this->dsMonth = $start->format('Y-m');
        $this->dsModalOpen = true;
    }

    public function closeDateSpecific(): void
    {
        $this->dsModalOpen = false;
    }

    public function dsPrevMonth(): void
    {
        $current = CarbonImmutable::now($this->salon->timezone)->format('Y-m');
        $previous = CarbonImmutable::parse($this->dsMonth.'-01')->subMonth()->format('Y-m');

        // Never navigate before the current month — past dates aren't selectable.
        $this->dsMonth = max($previous, $current);
    }

    public function dsNextMonth(): void
    {
        $this->dsMonth = CarbonImmutable::parse($this->dsMonth.'-01')->addMonth()->format('Y-m');
    }

    /** Select/deselect a calendar day; past dates are refused server-side too. */
    public function dsToggleDate(string $date): void
    {
        $tz = $this->salon->timezone;

        try {
            $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        } catch (\Throwable) {
            return;
        }

        if ($day->lt(CarbonImmutable::now($tz)->startOfDay())) {
            return; // past dates stay grey
        }

        $key = $day->toDateString();

        $this->dsDates = in_array($key, $this->dsDates, true)
            ? array_values(array_diff($this->dsDates, [$key]))
            : [...$this->dsDates, $key];

        sort($this->dsDates);
    }

    public function dsAddBlock(): void
    {
        $this->dsBlocks[] = ['start' => '13:00', 'end' => '17:00'];
    }

    public function dsRemoveBlock(int $index): void
    {
        if (isset($this->dsBlocks[$index])) {
            array_splice($this->dsBlocks, $index, 1); // no blocks left = unavailable that date
        }
    }

    /**
     * Create one entry per selected date (× block). Editing replaces the
     * original entry. Persistence goes through the SAME AddTimeOff/
     * RemoveTimeOff actions as always — validation and permissions included.
     *
     * The whole edit is ONE transaction, and the replacement entries are
     * created (and therefore validated) BEFORE the original is deleted — an
     * invalid replacement rolls everything back with the original untouched.
     * The GHL availability sync queues exactly once, after a successful
     * commit, instead of once per action.
     */
    public function dsSubmit(AddTimeOff $add, RemoveTimeOff $remove): void
    {
        $this->validate([
            'dsDates' => ['required', 'array', 'min:1'],
        ], [], ['dsDates' => __('dates')]);

        DB::transaction(function () use ($add, $remove): void {
            $old = $this->dsEditId === null ? null : TimeOff::query()
                ->where('salon_id', $this->salon->id)
                ->where('user_id', $this->selectedStylistId)
                ->findOrFail($this->dsEditId);

            foreach ($this->dsDates as $date) {
                // No blocks = unavailable that whole date; otherwise each block
                // is part of that date's AVAILABLE hours (a schedule override).
                $blocks = $this->dsBlocks === []
                    ? [['start' => '00:00', 'end' => '24:00', 'kind' => TimeOff::KIND_OFF]]
                    : array_map(fn (array $b): array => [...$b, 'kind' => TimeOff::KIND_HOURS], $this->dsBlocks);

                foreach ($blocks as $block) {
                    $end = $block['end'] === '24:00'
                        ? CarbonImmutable::parse($date, $this->salon->timezone)->addDay()->startOfDay()->format('Y-m-d H:i')
                        : $date.' '.$block['end'];

                    $add->handle(Auth::user(), $this->salon, $this->selectedStylistId, [
                        'kind' => $block['kind'],
                        'starts_at' => $date.' '.$block['start'],
                        'ends_at' => $end,
                    ], queueSync: false);
                }
            }

            if ($old !== null) {
                $remove->handle(Auth::user(), $this->salon, $old, queueSync: false);
            }
        });

        SyncAvailabilityToGhl::queueForStylist($this->salon, $this->selectedStylistId);

        unset($this->timeOff);
        $this->dsModalOpen = false;

        Flux::toast(variant: 'success', text: $this->dsEditId !== null
            ? __('Date-specific hours updated.')
            : __('Date-specific hours added.'));

        $this->dsEditId = null;
    }

    /** The calendar grid for the modal: Sunday-first weeks of the shown month. */
    #[Computed]
    public function dsCalendar(): array
    {
        $tz = $this->salon->timezone;
        $month = CarbonImmutable::parse(($this->dsMonth ?: CarbonImmutable::now($tz)->format('Y-m')).'-01', $tz);
        $today = CarbonImmutable::now($tz)->startOfDay();

        $cells = [];
        $cursor = $month->startOfWeek(CarbonImmutable::SUNDAY);
        $gridEnd = $month->endOfMonth()->endOfWeek(CarbonImmutable::SATURDAY);

        while ($cursor->lte($gridEnd)) {
            $cells[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'in_month' => $cursor->month === $month->month,
                'past' => $cursor->lt($today),
            ];
            $cursor = $cursor->addDay();
        }

        return ['label' => $month->format('F Y'), 'cells' => $cells, 'at_current_month' => $month->format('Y-m') === $today->format('Y-m')];
    }

    /** "GMT-04:00 America/New_York (EDT)" — the read view's timezone line. */
    public function dsTimezoneLabel(): string
    {
        $now = CarbonImmutable::now($this->salon->timezone);

        return 'GMT'.$now->format('P').' '.$this->salon->timezone.' ('.$now->format('T').')';
    }

    private function isAllDay(TimeOff $off): bool
    {
        $tz = $this->salon->timezone;
        $start = $off->starts_at->setTimezone($tz);
        $end = $off->ends_at->setTimezone($tz);

        return $start->format('H:i') === '00:00' && $end->format('H:i') === '00:00' && $end->gt($start);
    }

    /** Row label: "8:00 AM – 5:00 PM" (hours), "Unavailable …" (time off). */
    public function dsRangeLabel(TimeOff $off): string
    {
        $tz = $this->salon->timezone;
        $start = $off->starts_at->setTimezone($tz);
        $end = $off->ends_at->setTimezone($tz);
        $range = $start->format('g:i A').' – '.($start->isSameDay($end) ? $end->format('g:i A') : $end->format('M j, g:i A'));

        if ($off->kind === TimeOff::KIND_HOURS) {
            return $range; // the date's available hours
        }

        return $this->isAllDay($off) ? __('Unavailable all day') : __('Unavailable :range', ['range' => $range]);
    }

    /** Drawer-level Save for the dates tab — commits + re-asserts the GHL sync. */
    public function saveDateSpecific(): void
    {
        abort_unless($this->canEditSelected, 403);

        SyncAvailabilityToGhl::queueForStylist($this->salon, $this->selectedStylistId);
        $this->editing = false;
        unset($this->timeOff);

        Flux::toast(variant: 'success', text: __('Date-specific hours saved.'));
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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
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
                            class="rounded-[9px] p-2 text-faint transition hover:bg-muted hover:text-ink">
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
                                <p class="text-[14px] text-secondary">{{ __('Check a day to set its hours. A second time block makes a split shift; the gap is unbookable. Use a row\'s copy action to apply its times to other days.') }}</p>
                                <p class="text-[12.5px] text-faint">{{ __('Times are in :timezone.', ['timezone' => $salon->timezone]) }}</p>
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
                                                                            class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-accent" aria-label="{{ __('Add a time block to :day', ['day' => $label]) }}">
                                                                        <flux:icon.plus variant="micro" />
                                                                    </button>
                                                                    <div class="relative">
                                                                        <button type="button" wire:click="openCopyPopover({{ $wd }})" title="{{ __('Copy times to other days') }}"
                                                                                aria-haspopup="dialog" aria-expanded="{{ $copySource === $wd ? 'true' : 'false' }}"
                                                                                class="rounded-[9px] p-1.5 transition hover:bg-muted hover:text-accent {{ $copySource === $wd ? 'bg-muted text-accent' : 'text-faint' }}"
                                                                                aria-label="{{ __('Copy :day times to other days', ['day' => $label]) }}">
                                                                            <flux:icon.document-duplicate variant="micro" />
                                                                        </button>

                                                                        {{-- "Copy times to…" popover: anchored to the icon,
                                                                             opening left/below so it stays inside the drawer. --}}
                                                                        @if ($copySource === $wd)
                                                                            <div class="absolute right-0 top-full z-10 mt-1.5 w-60 rounded-[12px] border border-border bg-card p-4 shadow-lg"
                                                                                 role="dialog" aria-label="{{ __('Copy times to…') }}"
                                                                                 x-data x-trap="true"
                                                                                 @keydown.escape.stop="$wire.closeCopyPopover()"
                                                                                 @click.outside="$wire.closeCopyPopover()">
                                                                                <div class="mb-3 text-[14px] font-semibold text-ink">{{ __('Copy times to…') }}</div>

                                                                                <label class="flex cursor-pointer items-center gap-2.5 pb-2.5">
                                                                                    <input type="checkbox" wire:model.live="copyAll"
                                                                                           class="size-[17px] shrink-0 cursor-pointer rounded-[4px] border-input accent-accent" />
                                                                                    <span class="text-[14px] font-medium text-ink">{{ __('Copy to all') }}</span>
                                                                                </label>

                                                                                <div class="flex flex-col gap-2 border-t border-divider pt-2.5">
                                                                                    @foreach ([6, 0, 1, 2, 3, 4, 5] as $target) {{-- Sunday…Saturday, like the reference --}}
                                                                                        <label class="flex items-center gap-2.5 {{ $target === $wd ? 'opacity-50' : 'cursor-pointer' }}">
                                                                                            <input type="checkbox" wire:model.live="copyTargets.{{ $target }}"
                                                                                                   @disabled($target === $wd)
                                                                                                   class="size-[17px] shrink-0 rounded-[4px] border-input accent-accent {{ $target === $wd ? '' : 'cursor-pointer' }}" />
                                                                                            <span class="text-[14px] text-body">{{ $weekdays[$target] }}</span>
                                                                                        </label>
                                                                                    @endforeach
                                                                                </div>

                                                                                <x-ui.button size="sm" class="mt-3.5 w-full justify-center" wire:click="applyCopy">
                                                                                    {{ __('Apply') }}
                                                                                </x-ui.button>
                                                                            </div>
                                                                        @endif
                                                                    </div>
                                                                @endif
                                                                <button type="button" wire:click="removeWindow({{ $wd }}, {{ $i }})" title="{{ __('Remove') }}"
                                                                        class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-danger" aria-label="{{ __('Remove this time block') }}">
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

                {{-- ───── Date-specific hours ───── --}}
                @if ($panelTab === 'dates')
                    @if (! $editing)
                        {{-- READ view: timezone header + clean divided rows, no controls. --}}
                        <div class="flex flex-col rounded-[18px] border border-border">
                            <div class="border-b border-divider px-5 py-3 text-[12.5px] font-medium text-faint">{{ $this->dsTimezoneLabel() }}</div>
                            <div class="flex flex-col divide-y divide-row">
                                @forelse ($this->timeOff as $off)
                                    <div class="flex flex-col px-5 py-3.5">
                                        <span class="text-[15px] font-medium text-ink">{{ $off->starts_at->setTimezone($salon->timezone)->format('F j, Y') }}</span>
                                        <span class="text-[14px] text-secondary">{{ $this->dsRangeLabel($off) }}</span>
                                        @if ($off->kind !== \App\Models\TimeOff::KIND_HOURS && $off->note)
                                            <span class="text-[12.5px] text-faint">{{ $off->note }}</span>
                                        @endif
                                    </div>
                                @empty
                                    <div class="px-5 py-10 text-center text-[15px] text-faint">{{ __('No date-specific hours — the weekly schedule applies.') }}</div>
                                @endforelse
                            </div>
                        </div>
                    @else
                        {{-- EDIT view: helper + add button, entry rows with pencil/trash. --}}
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="text-[16px] font-semibold text-ink">{{ __('Date-specific hours') }}</h3>
                                    <p class="mt-0.5 text-[14px] text-secondary">{{ __('Set custom unavailability for specific dates that differ from the weekly schedule.') }}</p>
                                </div>
                                <x-ui.button size="sm" variant="secondary" wire:click="openDateSpecific">
                                    <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add date-specific hours') }}
                                </x-ui.button>
                            </div>

                            @if ($this->timeOff->isEmpty())
                                <div class="flex flex-col items-center gap-2 rounded-[18px] border border-dashed border-input px-5 py-12 text-center">
                                    <flux:icon.calendar-days class="size-8 text-faint" />
                                    <p class="text-[15px] text-faint">{{ __('No date-specific time added.') }}</p>
                                </div>
                            @else
                                <div class="flex flex-col divide-y divide-row rounded-[18px] border border-border">
                                    @foreach ($this->timeOff as $off)
                                        <div class="flex items-center justify-between gap-3 px-5 py-3.5">
                                            <div class="flex min-w-0 flex-col">
                                                <span class="text-[15px] font-medium text-ink">{{ $off->starts_at->setTimezone($salon->timezone)->format('F j, Y') }}</span>
                                                <span class="text-[14px] text-secondary">{{ $this->dsRangeLabel($off) }}</span>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-0.5">
                                                <button type="button" wire:click="editDateSpecific({{ $off->id }})" title="{{ __('Edit') }}"
                                                        class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-accent" aria-label="{{ __('Edit this date') }}">
                                                    <flux:icon.pencil-square variant="micro" />
                                                </button>
                                                <button type="button" wire:click="removeTimeOff({{ $off->id }})" title="{{ __('Remove') }}"
                                                        wire:confirm="{{ __('Remove this date-specific entry? The weekly schedule applies to that date again and GoHighLevel availability is updated.') }}"
                                                        class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-danger" aria-label="{{ __('Remove this date') }}">
                                                    <flux:icon.trash variant="micro" />
                                                </button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="flex justify-end gap-2 border-t border-divider pt-4">
                            <x-ui.button variant="secondary" wire:click="cancelEditing">{{ __('Cancel') }}</x-ui.button>
                            <x-ui.button wire:click="saveDateSpecific">{{ __('Save date-specific hours') }}</x-ui.button>
                        </div>

                        {{-- Add/edit modal: month calendar + that date's available hours. --}}
                        <x-ui.modal wire:model="dsModalOpen" class="max-w-md"
                            :heading="$dsEditId ? __('Edit date-specific hours') : __('Choose the date to set specific hours')">
                            <div class="flex flex-col gap-5">
                                @php($calendar = $this->dsCalendar)
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center justify-between">
                                        <button type="button" wire:click="dsPrevMonth" @disabled($calendar['at_current_month'])
                                                class="rounded-[9px] p-1.5 transition {{ $calendar['at_current_month'] ? 'text-fainter/50' : 'text-faint hover:bg-muted hover:text-ink' }}"
                                                aria-label="{{ __('Previous month') }}">
                                            <flux:icon.chevron-left variant="mini" />
                                        </button>
                                        <div class="text-[15px] font-semibold text-ink">{{ $calendar['label'] }}</div>
                                        <button type="button" wire:click="dsNextMonth"
                                                class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-ink" aria-label="{{ __('Next month') }}">
                                            <flux:icon.chevron-right variant="mini" />
                                        </button>
                                    </div>

                                    <div class="grid grid-cols-7 gap-1 text-center">
                                        @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName)
                                            <div class="py-1 text-[12px] font-semibold uppercase tracking-[0.04em] text-faint">{{ $dayName }}</div>
                                        @endforeach
                                        @foreach ($calendar['cells'] as $cell)
                                            @php($isSelected = in_array($cell['date'], $dsDates, true))
                                            <button type="button" wire:click="dsToggleDate('{{ $cell['date'] }}')"
                                                    @disabled($cell['past'])
                                                    aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                                                    aria-label="{{ $cell['date'] }}"
                                                    class="mx-auto flex size-9 items-center justify-center rounded-full text-[14px] transition
                                                        {{ $isSelected ? 'bg-accent font-semibold text-white' : ($cell['past'] ? 'cursor-default text-fainter/60' : ($cell['in_month'] ? 'text-ink hover:bg-accent-soft' : 'text-faint hover:bg-muted')) }}">
                                                {{ $cell['day'] }}
                                            </button>
                                        @endforeach
                                    </div>
                                    @error('dsDates') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
                                </div>

                                @if ($dsDates !== [])
                                    <div class="flex flex-col gap-3 border-t border-divider pt-4">
                                        <div class="text-[14.5px] font-semibold text-ink">{{ __('When are you available?') }}</div>

                                        @if ($dsBlocks === [])
                                            <p class="text-[14px] text-faint">{{ __('No hours — unavailable on the selected date(s).') }}</p>
                                            <button type="button" wire:click="dsAddBlock"
                                                    class="inline-flex w-fit items-center gap-1 text-[13px] font-semibold text-accent transition hover:text-accent-hover">
                                                <flux:icon.plus variant="micro" class="shrink-0" />{{ __('Add hours') }}
                                            </button>
                                        @else
                                            <div class="flex flex-col gap-2">
                                                @foreach ($dsBlocks as $i => $block)
                                                    <div class="flex items-center gap-2">
                                                        <flux:input type="time" wire:model="dsBlocks.{{ $i }}.start" class="flex-1" aria-label="{{ __('Available from') }}" />
                                                        <span class="text-[14px] text-secondary">{{ __('to') }}</span>
                                                        <flux:input type="time" wire:model="dsBlocks.{{ $i }}.end" class="flex-1" aria-label="{{ __('Available until') }}" />
                                                        <div class="flex w-[64px] shrink-0 items-center justify-end gap-0.5">
                                                            @if ($i === 0)
                                                                <button type="button" wire:click="dsAddBlock" title="{{ __('Add a block') }}"
                                                                        class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-accent" aria-label="{{ __('Add another block') }}">
                                                                    <flux:icon.plus variant="micro" />
                                                                </button>
                                                            @endif
                                                            <button type="button" wire:click="dsRemoveBlock({{ $i }})" title="{{ __('Remove') }}"
                                                                    class="rounded-[9px] p-1.5 text-faint transition hover:bg-muted hover:text-danger" aria-label="{{ __('Remove this block') }}">
                                                                <flux:icon.trash variant="micro" />
                                                            </button>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                        @error('ends_at') <div class="text-[14px] text-danger">{{ $message }}</div> @enderror
                                    </div>
                                @endif

                                <div class="flex justify-end gap-2 border-t border-divider pt-4">
                                    <x-ui.button variant="secondary" wire:click="closeDateSpecific">{{ __('Cancel') }}</x-ui.button>
                                    <x-ui.button wire:click="dsSubmit">{{ __('Submit') }}</x-ui.button>
                                </div>
                            </div>
                        </x-ui.modal>
                    @endif
                @endif
                </div>
            </div>
            </div>
        </template>
    @endif
</div>
