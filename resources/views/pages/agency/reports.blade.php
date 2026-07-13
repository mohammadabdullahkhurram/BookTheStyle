<?php

use App\Enums\BookingSource;
use App\Models\Agency;
use App\Services\Reporting\AgencyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Agency-wide reporting: every metric aggregated across ALL the agency's
 * salons for a selectable range (AgencyReport — a bounded handful of grouped
 * queries, never per-salon loops). Agency console access only; salon staff
 * never reach agency routes. The per-salon table is sortable and the
 * most-active salons are highlighted; revenue is grouped per currency.
 */
new #[Title('Agency reporting')] class extends Component {
    /** week | month | days30 | custom */
    public string $preset = 'month';

    public string $from = '';

    public string $to = '';

    /** Per-salon table sort. */
    public string $sort = 'total';

    public string $dir = 'desc';

    public function mount(): void
    {
        $this->authorize('accessConsole', $this->agency());

        $today = CarbonImmutable::now(config('app.timezone'));
        $this->from = $today->startOfMonth()->format('Y-m-d');
        $this->to = $today->endOfMonth()->format('Y-m-d');
    }

    public function agency(): Agency
    {
        $agency = Auth::user()->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    public function updatedPreset(): void
    {
        $today = CarbonImmutable::now(config('app.timezone'));

        [$this->from, $this->to] = match ($this->preset) {
            'week' => [$today->startOfWeek()->format('Y-m-d'), $today->endOfWeek()->format('Y-m-d')],
            'days30' => [$today->subDays(29)->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [$this->from, $this->to],
            default => [$today->startOfMonth()->format('Y-m-d'), $today->endOfMonth()->format('Y-m-d')],
        };

        unset($this->report);
    }

    public function updatedFrom(): void
    {
        $this->preset = 'custom';
        unset($this->report);
    }

    public function updatedTo(): void
    {
        $this->preset = 'custom';
        unset($this->report);
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['name', 'total', 'completed', 'no_show_rate', 'revenue_cents'], true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->dir = $this->dir === 'desc' ? 'asc' : 'desc';
        } else {
            $this->sort = $column;
            $this->dir = $column === 'name' ? 'asc' : 'desc';
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(): array
    {
        $tz = config('app.timezone');
        $from = CarbonImmutable::parse($this->from ?: 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($this->to ?: 'today', $tz)->startOfDay()->addDay();

        return $to->gt($from) ? [$from, $to] : [$from, $from->addDay()];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        [$start, $end] = $this->range();

        return app(AgencyReport::class)->build($this->agency(), $start, $end);
    }

    /** The per-salon rows under the current sort (ranking = total desc). */
    #[Computed]
    public function sortedSalons(): array
    {
        $rows = $this->report['salons'];

        usort($rows, function (array $a, array $b): int {
            $result = $this->sort === 'name'
                ? strcasecmp($a['name'], $b['name'])
                : (($a[$this->sort] ?? 0) <=> ($b[$this->sort] ?? 0));

            return $this->dir === 'desc' ? -$result : $result;
        });

        return $rows;
    }

    /** The salon ids leading the activity ranking (highlighted rows). */
    #[Computed]
    public function topSalonIds(): array
    {
        return collect($this->report['salons'])
            ->filter(fn (array $row): bool => $row['total'] > 0)
            ->take(3)
            ->pluck('salon_id')
            ->all();
    }

    public function sourceLabel(string $source): string
    {
        return BookingSource::tryFrom($source)?->label() ?? $source;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('Reporting')">
            <x-slot:subtitle>{{ __('Every salon, one view — bookings, outcomes, revenue, and where bookings come from.') }}</x-slot:subtitle>
        </x-ui.page-header>

        {{-- Range selector. --}}
        <div class="flex flex-wrap items-end gap-4">
            <flux:radio.group wire:model.live="preset" variant="segmented">
                <flux:radio value="week" :label="__('This week')" />
                <flux:radio value="month" :label="__('This month')" />
                <flux:radio value="days30" :label="__('Last 30 days')" />
                <flux:radio value="custom" :label="__('Custom')" />
            </flux:radio.group>
            <div class="flex items-end gap-3">
                <flux:input type="date" wire:model.live="from" :label="__('From')" class="max-w-44" />
                <flux:input type="date" wire:model.live="to" :label="__('To')" class="max-w-44" />
            </div>
        </div>

        @php($r = $this->report)

        <div wire:loading.class="pointer-events-none opacity-60" wire:target="preset, from, to"
             class="flex flex-col gap-6 transition-opacity">

        {{-- Agency totals. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-ui.stat-card :label="__('Bookings')" :value="$r['totals']['total']" />
            <x-ui.stat-card :label="__('Completed')" :value="$r['totals']['completed']" tone="success" />
            <x-ui.stat-card :label="__('Cancelled')" :value="$r['totals']['cancelled']" />
            <x-ui.stat-card :label="__('No-shows')" :value="$r['totals']['no_shows']" tone="danger"
                :sublabel="$r['totals']['no_show_rate'] !== null ? __(':rate% of non-cancelled', ['rate' => $r['totals']['no_show_rate']]) : null" />
            <x-ui.stat-card :label="__('Estimated revenue')" tone="info"
                :value="$r['revenue'] !== [] ? collect($r['revenue'])->map(fn ($cents, $currency) => \App\Support\Money::format($cents, $currency))->join(' + ') : '—'"
                :sublabel="$r['totals']['unpriced_completed_items'] > 0 ? __('Priced services only — :n completed without a price', ['n' => $r['totals']['unpriced_completed_items']]) : __('Completed visits, per currency')" />
        </div>

        @if ($r['totals']['total'] === 0)
            <x-ui.card class="py-14 text-center text-[15px] text-faint">
                {{ __('No bookings across any salon in this range.') }}
            </x-ui.card>
        @else
            {{-- Agency-wide source mix: the voice-AI value, in one view. --}}
            <x-ui.card class="flex flex-col gap-4">
                <h2 class="bts-card-title">{{ __('Where bookings came from — all salons') }}</h2>
                <div class="flex flex-col gap-3">
                    @foreach ($r['source_mix'] as $row)
                        @php($isAi = in_array($row['source'], [\App\Enums\BookingSource::VoiceAi->value, \App\Enums\BookingSource::ChatWidget->value], true))
                        <div class="flex items-center gap-3 text-[14px]">
                            <span class="w-28 shrink-0 {{ $isAi ? 'font-semibold text-accent-ink' : 'text-secondary' }}">{{ $this->sourceLabel($row['source']) }}</span>
                            <div class="h-2.5 flex-1 overflow-hidden rounded-[99px] bg-muted">
                                <div class="h-full rounded-[99px] {{ $isAi ? 'bg-accent' : 'bg-secondary' }}" style="width: {{ max(2, $row['share']) }}%"></div>
                            </div>
                            <span class="w-24 shrink-0 text-right text-secondary">{{ $row['count'] }} · {{ $row['share'] }}%</span>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>

            {{-- Per-salon breakdown: sortable; the most active salons lead. --}}
            <x-ui.card padding="p-0" class="overflow-hidden">
                <h2 class="bts-card-title border-b border-divider px-6 py-4">{{ __('Per salon') }}</h2>
                <div class="overflow-x-auto" tabindex="0">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bts-overline border-b border-divider">
                            @foreach ([['name', __('Salon')], ['total', __('Bookings')], ['completed', __('Completed')], ['no_show_rate', __('No-show rate')], ['revenue_cents', __('Est. revenue')]] as [$column, $label])
                                <th scope="col" class="px-6 py-3.5 font-semibold">
                                    <button type="button" wire:click="sortBy('{{ $column }}')"
                                            class="inline-flex items-center gap-1 uppercase tracking-[inherit] transition hover:text-ink"
                                            aria-label="{{ __('Sort by :column', ['column' => $label]) }}">
                                        {{ $label }}
                                        @if ($sort === $column)
                                            <span aria-hidden="true">{{ $dir === 'desc' ? '↓' : '↑' }}</span>
                                        @endif
                                    </button>
                                </th>
                            @endforeach
                            <th scope="col" class="px-6 py-3.5 font-semibold">{{ __('Sources') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-row">
                        @foreach ($this->sortedSalons as $row)
                            <tr @class(['bg-accent-tint/40' => in_array($row['salon_id'], $this->topSalonIds, true)])>
                                <td class="px-6 py-4">
                                    <span class="text-[15px] font-medium text-ink">{{ $row['name'] }}</span>
                                    @if (in_array($row['salon_id'], $this->topSalonIds, true))
                                        <span class="ms-2 bts-pill" style="background-color: var(--accent-tint); color: var(--accent-ink);">{{ __('Most active') }}</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $row['total'] }}</td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $row['completed'] }}</td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $row['no_show_rate'] !== null ? $row['no_show_rate'].'%' : '—' }}</td>
                                <td class="px-6 py-4 text-[15px] text-secondary">{{ $row['revenue_cents'] > 0 ? \App\Support\Money::format($row['revenue_cents'], $row['currency']) : '—' }}</td>
                                <td class="px-6 py-4 text-[13.5px] text-secondary">
                                    @forelse ($row['sources'] as $source => $count)
                                        <span class="whitespace-nowrap">{{ $this->sourceLabel($source) }} {{ $count }}</span>@if (! $loop->last) · @endif
                                    @empty
                                        —
                                    @endforelse
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </x-ui.card>
        @endif
        </div>
    </div>
</div>
