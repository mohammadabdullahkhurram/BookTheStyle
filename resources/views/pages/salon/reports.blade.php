<?php

use App\Enums\BookingSource;
use App\Models\Salon;
use App\Services\Reporting\SalonReport;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Reports (owner/admin): read-only analytics over existing bookings for a
 * selectable range. Everything is computed on demand by SalonReport's
 * aggregate queries — nothing here runs on other pages. The source mix is
 * front and centre: it is where the voice-AI / chat-widget booking value
 * shows up in numbers.
 */
new #[Title('Reports')] class extends Component {
    public Salon $salon;

    /** week | month | days30 | custom */
    public string $preset = 'month';

    public string $from = '';

    public string $to = '';

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;

        $today = CarbonImmutable::now($salon->timezone);
        $this->from = $today->startOfMonth()->format('Y-m-d');
        $this->to = $today->endOfMonth()->format('Y-m-d');
    }

    public function updatedPreset(): void
    {
        $today = CarbonImmutable::now($this->salon->timezone);

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

    /**
     * The salon-local [from, to] dates as a UTC instant range [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(): array
    {
        $tz = $this->salon->timezone;
        $from = CarbonImmutable::parse($this->from ?: 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($this->to ?: 'today', $tz)->startOfDay()->addDay();

        return $to->gt($from) ? [$from, $to] : [$from, $from->addDay()];
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        [$start, $end] = $this->range();

        return app(SalonReport::class)->build($this->salon, $start, $end);
    }

    /** Share of bookings that came in through GHL's AI channels. */
    #[Computed]
    public function aiShare(): float
    {
        return collect($this->report['source_mix'])
            ->whereIn('source', [BookingSource::VoiceAi->value, BookingSource::ChatWidget->value])
            ->sum('share');
    }

    public function sourceLabel(string $source): string
    {
        return BookingSource::tryFrom($source)?->label() ?? $source;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Insights')" :title="__('Reports')" />

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

        {{-- Everything below recomputes with the range: dim it while the new
             numbers load so the page never looks frozen on slow queries. --}}
        <div wire:loading.class="pointer-events-none opacity-60" wire:target="preset, from, to"
             class="flex flex-col gap-6 transition-opacity">

        {{-- Bookings summary. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <x-ui.stat-card :label="__('Bookings')" :value="$r['total']" />
            <x-ui.stat-card :label="__('Completed')" :value="$r['completed']" tone="success" />
            <x-ui.stat-card :label="__('Cancelled')" :value="$r['cancelled']" />
            <x-ui.stat-card :label="__('No-shows')" :value="$r['no_shows']" tone="danger"
                :sublabel="$r['no_show_rate'] !== null ? __(':rate% of non-cancelled', ['rate' => $r['no_show_rate']]) : null" />
            <x-ui.stat-card :label="__('Estimated revenue')" tone="info"
                :value="$r['completed'] > 0 ? (\App\Support\Money::format($r['revenue_cents'], $salon->currency) ?? '—') : '—'"
                :sublabel="$r['unpriced_completed_items'] > 0 ? __('Priced services only — :n completed without a price', ['n' => $r['unpriced_completed_items']]) : __('Completed visits, priced services')" />
        </div>

        @if ($r['total'] === 0)
            <x-ui.card class="py-14 text-center text-[15px] text-faint">
                {{ __('No bookings in this range.') }}
            </x-ui.card>
        @else
            {{-- Source mix — the differentiator, so it gets the hero card. --}}
            <x-ui.card class="flex flex-col gap-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="bts-card-title">{{ __('Where bookings came from') }}</h2>
                    @if ($this->aiShare > 0)
                        <span class="text-[14px] font-semibold text-accent">{{ __(':share% booked by AI (voice + chat)', ['share' => round($this->aiShare, 1)]) }}</span>
                    @endif
                </div>
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

            <div class="grid items-start gap-6 lg:grid-cols-2">
                {{-- Staff activity. --}}
                <x-ui.card padding="p-0" class="overflow-hidden">
                    <h2 class="bts-card-title border-b border-divider px-6 py-4">{{ __('Staff activity') }}</h2>
                    <div class="flex flex-col divide-y divide-row">
                        @forelse ($r['stylists'] as $row)
                            <div class="flex items-center justify-between px-6 py-3 text-[14px]">
                                <div class="flex items-center gap-3">
                                    <x-ui.avatar :name="$row['name']" :seed="$row['stylist_id']" size="sm" />
                                    <span class="font-medium text-ink">{{ $row['name'] }}</span>
                                </div>
                                <span class="text-secondary">
                                    {{ trans_choice(':count booking|:count bookings', $row['total'], ['count' => $row['total']]) }}
                                    · {{ __(':count completed', ['count' => $row['completed']]) }}
                                </span>
                            </div>
                        @empty
                            <p class="px-6 py-8 text-center text-[14px] text-faint">{{ __('No stylist activity in this range.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>

                {{-- Top services. --}}
                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Top services') }}</h2>
                    @php($maxCount = collect($r['top_services'])->max('count') ?: 1)
                    <div class="flex flex-col gap-3">
                        @forelse ($r['top_services'] as $row)
                            <div class="flex items-center gap-3 text-[14px]">
                                <span class="w-32 shrink-0 truncate text-secondary" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                <div class="h-2.5 flex-1 overflow-hidden rounded-[99px] bg-muted">
                                    <div class="h-full rounded-[99px] bg-accent" style="width: {{ max(2, round($row['count'] / $maxCount * 100)) }}%"></div>
                                </div>
                                <span class="w-32 shrink-0 text-right text-secondary">
                                    {{ $row['count'] }}@if ($row['revenue_cents'] !== null) · {{ \App\Support\Money::format($row['revenue_cents'], $salon->currency) }}@endif
                                </span>
                            </div>
                        @empty
                            <p class="py-6 text-center text-[14px] text-faint">{{ __('No services booked in this range.') }}</p>
                        @endforelse
                    </div>
                    <p class="text-[12.5px] text-faint">{{ __('Counts exclude cancelled bookings; revenue is estimated from completed visits with priced services.') }}</p>
                </x-ui.card>
            </div>
        @endif
        </div>
    </div>
</div>
