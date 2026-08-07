<?php

use App\Models\Salon;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Services\Health\HealthCheckRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Health check" — the full setup + operational validation for THIS salon,
 * agency owner/admin ONLY (SalonPolicy::runDiagnostics via before(); salon
 * roles, delegated agency_users, guests all refused; demo salons 404). A
 * sudo-style password confirmation gates the run.
 *
 * The checks come from the extensible HealthCheckRegistry (one small class
 * per check, grouped by category) — read-only except the ONE sanctioned
 * mutation: the test booking on the disposable is_test records.
 *
 * THE HONEST SPLIT, stated on the page too: BTS tests its own side
 * automatically; it cannot fire a GHL Custom Action itself, so the Voice AI
 * wiring is verified by the manual round-trip below.
 */
new #[Title('Health check')] class extends Component {
    public Salon $salon;

    public string $password = '';

    /** @var list<array{key: string, label: string, checks: list<array{key: string, label: string, status: string, message: string, fix: string|null}>}> */
    public array $categories = [];

    /** @var array{pass: int, warn: int, fail: int}|null */
    public ?array $summary = null;

    public ?string $ranAt = null;

    /** @var array{availability: array<string, mixed>, create: array<string, mixed>|null}|null */
    public ?array $payloads = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('runDiagnostics', $salon);
        abort_if($salon->is_demo, 404); // never in the demo, not even for operators

        $this->salon = $salon;
    }

    public function run(HealthCheckRegistry $registry, ConnectionDiagnostics $diagnostics): void
    {
        $this->authorize('runDiagnostics', $this->salon);
        abort_if($this->salon->is_demo, 404);

        // Sudo-style confirmation: the operator re-enters THEIR OWN login
        // password to authorise creating test records on a live salon.
        $this->validate(
            ['password' => ['required', 'current_password']],
            ['password.current_password' => __('That is not your password — re-enter your own login password to run the check.')],
        );

        $report = $registry->run($this->salon);
        $this->categories = $report['categories'];
        $this->summary = $report['summary'];
        $this->payloads = $diagnostics->roundTripPayloads($this->salon);
        $this->ranAt = now()->toIso8601String();
        $this->reset('password');
    }

    public function finish(ConnectionDiagnostics $diagnostics): void
    {
        $this->authorize('runDiagnostics', $this->salon);
        abort_if($this->salon->is_demo, 404);

        $diagnostics->teardown($this->salon);
        $this->reset(['categories', 'summary', 'ranAt', 'payloads']);

        Flux::toast(variant: 'success', text: __('Test records and test appointments removed.'));
    }

    /** @return array{at: string, path: string}|null */
    #[Computed]
    public function lastCall(): ?array
    {
        return ConnectionDiagnostics::lastReceivedCall($this->salon);
    }

    /** The round-trip indicator greens only for calls AFTER this run started. */
    #[Computed]
    public function roundTripConfirmed(): bool
    {
        return $this->ranAt !== null
            && $this->lastCall !== null
            && $this->lastCall['at'] >= $this->ranAt;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Integrations')" :title="__('Health check')">
            <x-slot:subtitle>{{ __('Validate this salon\'s whole setup and the site\'s operational health — with disposable test records nothing a client can ever see.') }}</x-slot:subtitle>
        </x-ui.page-header>

        {{-- ── Run area ─────────────────────────────────────────────── --}}
        <x-ui.card padding="p-0" class="overflow-hidden">
            <div class="flex flex-col gap-2 p-6">
                <h2 class="bts-card-title">{{ __('How this works') }}</h2>
                <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Running the check creates three temporary records for this salon — “Bluejaypro Stylist”, “Bluejaypro Hair Cut”, and “Bluejaypro Test Client” — books one real test appointment through the same engine the Voice AI uses, and validates everything else read-only: integrations, notifications, the scheduler and queue, salon readiness, and the system itself. The test records are invisible to clients and are deleted when you finish — or automatically after 24 hours.') }}</p>
                <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('BookTheStyle can only test its own side automatically. The GHL side — the Voice AI’s Custom Actions — fires from inside GHL, so it is verified by the round-trip at the bottom: you run the generated test calls in GHL, and this page confirms when they arrive.') }}</p>
            </div>
            <form wire:submit="run" class="flex flex-col gap-3 border-t border-divider bg-muted/40 p-6 sm:flex-row sm:items-end sm:justify-between" novalidate>
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="w-full sm:max-w-xs">
                        <flux:input wire:model="password" type="password" :label="__('Confirm your password to run')" autocomplete="current-password" required />
                    </div>
                    <x-ui.button type="submit" loading="run" class="shrink-0 whitespace-nowrap">{{ __('Run health check') }}</x-ui.button>
                </div>
                <div class="shrink-0 text-[12.5px] text-faint sm:pb-2.5">
                    <span wire:loading.remove wire:target="run">
                        @if ($ranAt !== null)
                            {{ __('Last run :when', ['when' => \Illuminate\Support\Carbon::parse($ranAt)->setTimezone($salon->timezone)->format('M j, g:i A')]) }}
                        @endif
                    </span>
                    <span wire:loading wire:target="run" class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-1.5 w-1.5 animate-pulse rounded-full" style="background-color:var(--accent);"></span>
                        {{ __('Running all checks — this takes a few seconds…') }}
                    </span>
                </div>
            </form>
        </x-ui.card>

        @if ($summary !== null)
            @php
                $overall = $summary['fail'] > 0 ? 'fail' : ($summary['warn'] > 0 ? 'warn' : 'pass');
                [$bannerBg, $bannerBorder, $bannerInk, $bannerTitle] = match ($overall) {
                    'fail' => ['#F6E8E1', '#E4C4B3', '#8A4B2D', __('Problems found — fix the ✗ lines below.')],
                    'warn' => ['#F6EFE2', '#E2CFA4', '#8A6D1F', __('Working, with warnings worth a look.')],
                    default => ['#E7EFE4', '#C8DAC2', '#3E5C3A', __('All clear — everything looks healthy.')],
                };
            @endphp

            {{-- ── Summary banner ───────────────────────────────────── --}}
            <div class="flex flex-col gap-3 rounded-[14px] border p-5 sm:flex-row sm:items-center sm:justify-between" style="background-color:{{ $bannerBg }};border-color:{{ $bannerBorder }};">
                <div>
                    <p class="font-display text-[19px] font-semibold" style="color:{{ $bannerInk }};">{{ $bannerTitle }}</p>
                    <p class="mt-0.5 text-[12.5px]" style="color:{{ $bannerInk }};opacity:.8;">{{ __('Ran :when.', ['when' => \Illuminate\Support\Carbon::parse($ranAt)->setTimezone($salon->timezone)->format('M j, Y g:i A')]) }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-1 text-[13px] font-semibold" style="color:#3E5C3A;">
                        <flux:icon.check-circle variant="micro" />{{ trans_choice(':count check passed|:count checks passed', $summary['pass'], ['count' => $summary['pass']]) }}
                    </span>
                    <span @class(['inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-1 text-[13px] font-semibold', 'opacity-50' => $summary['warn'] === 0]) style="color:#8A6D1F;">
                        <flux:icon.exclamation-triangle variant="micro" />{{ trans_choice(':count warning|:count warnings', $summary['warn'], ['count' => $summary['warn']]) }}
                    </span>
                    <span @class(['inline-flex items-center gap-1.5 rounded-full bg-white/70 px-3 py-1 text-[13px] font-semibold', 'opacity-50' => $summary['fail'] === 0]) style="color:#8A4B2D;">
                        <flux:icon.x-circle variant="micro" />{{ trans_choice(':count failed|:count failed', $summary['fail'], ['count' => $summary['fail']]) }}
                    </span>
                </div>
            </div>

            {{-- ── Categorized report ───────────────────────────────── --}}
            @foreach ($categories as $category)
                @php
                    $issueCount = collect($category['checks'])->reject(fn ($line) => $line['status'] === 'pass')->count();
                    $total = count($category['checks']);
                @endphp
                <x-ui.card padding="p-0" class="overflow-hidden" wire:key="category-{{ $category['key'] }}">
                    <div class="flex items-center justify-between gap-3 border-b border-divider px-6 py-4">
                        <h2 class="bts-card-title">{{ $category['label'] }}</h2>
                        @if ($issueCount === 0)
                            <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('all :count passed', ['count' => $total]) }}</span>
                        @else
                            <span class="bts-pill" style="background-color:#F6E8E1;color:#8A4B2D;">{{ trans_choice(':count issue|:count issues', $issueCount, ['count' => $issueCount]) }}</span>
                        @endif
                    </div>
                    <ul class="flex flex-col gap-1.5 p-3">
                        @foreach ($category['checks'] as $line)
                            <li wire:key="check-{{ $line['key'] }}"
                                @class(['flex gap-3 rounded-[10px] px-3 py-2.5', 'border' => $line['status'] !== 'pass'])
                                @if ($line['status'] === 'warn') style="background-color:#F6EFE2;border-color:#E2CFA4;" @endif
                                @if ($line['status'] === 'fail') style="background-color:#F6E8E1;border-color:#E4C4B3;" @endif>
                                @if ($line['status'] === 'pass')
                                    <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0" style="color:#7FA379;" />
                                @elseif ($line['status'] === 'warn')
                                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0" style="color:#8A6D1F;" />
                                @else
                                    <flux:icon.x-circle variant="mini" class="mt-0.5 shrink-0" style="color:#8A4B2D;" />
                                @endif
                                <div class="min-w-0">
                                    {{-- Passes stay quiet: one muted line. Issues speak up. --}}
                                    @if ($line['status'] === 'pass')
                                        <p class="text-[13.5px] leading-relaxed text-secondary"><span class="font-semibold text-body">{{ $line['label'] }}</span> — {{ $line['message'] }}</p>
                                    @else
                                        <p class="text-[14px] font-semibold" style="color:{{ $line['status'] === 'warn' ? '#8A6D1F' : '#8A4B2D' }};">{{ $line['label'] }}</p>
                                        <p class="text-[13.5px] leading-relaxed" style="color:{{ $line['status'] === 'warn' ? '#8A6D1F' : '#8A4B2D' }};">{{ $line['message'] }}</p>
                                        @if ($line['fix'] !== null)
                                            <p class="mt-1 text-[13px] font-medium leading-relaxed" style="color:{{ $line['status'] === 'warn' ? '#8A6D1F' : '#8A4B2D' }};"><span class="font-semibold uppercase tracking-[0.06em] text-[11px]">{{ __('Fix:') }}</span> {{ $line['fix'] }}</p>
                                        @endif
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach

            {{-- ── GHL round-trip ───────────────────────────────────── --}}
            <x-ui.card padding="p-0" class="overflow-hidden" wire:poll.5s="$refresh">
                <div class="flex flex-col gap-1 border-b border-divider px-6 py-4">
                    <h2 class="bts-card-title">{{ __('GHL side — verified by round-trip') }}</h2>
                    <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('BookTheStyle cannot fire GHL\'s Custom Actions itself — they run inside GHL. Paste each payload into the matching Custom Action test in GHL (using this salon\'s own token) and run it against the test stylist. The moment a correctly-authenticated call arrives here, the indicator turns green.') }}</p>
                </div>

                <div class="flex flex-col gap-4 p-6">
                    {{-- Waiting → success indicator. --}}
                    <div class="flex items-start gap-3 rounded-[10px] border px-4 py-3" style="{{ $this->roundTripConfirmed ? 'background-color:#E7EFE4;border-color:#C8DAC2;' : 'background-color:#F0EEEA;border-color:#DAD5CD;' }}">
                        @if ($this->roundTripConfirmed)
                            <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0" style="color:#3E5C3A;" />
                        @else
                            <span class="mt-1.5 inline-block h-2 w-2 shrink-0 animate-pulse rounded-full" style="background-color:#9C968D;"></span>
                        @endif
                        <div>
                            <p class="text-[12px] font-semibold uppercase tracking-[0.08em]" style="color:{{ $this->roundTripConfirmed ? '#3E5C3A' : '#6B6862' }};">{{ $this->roundTripConfirmed ? __('Call received') : __('Waiting for GHL…') }}</p>
                            <p class="text-[13.5px]" style="color:{{ $this->roundTripConfirmed ? '#3E5C3A' : '#6B6862' }};">
                                @if ($this->roundTripConfirmed)
                                    {{ __('GHL reached BookTheStyle: :path at :when — the wiring works.', ['path' => $this->lastCall['path'], 'when' => \Illuminate\Support\Carbon::parse($this->lastCall['at'])->setTimezone($salon->timezone)->format('g:i:s A')]) }}
                                @else
                                    {{ __('No call received since this check started. Run the payloads in GHL — this updates by itself.') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @foreach ([['n' => 1, 'label' => __('Check availability'), 'url' => route('api.booking.availability'), 'payload' => $payloads['availability'] ?? null], ['n' => 2, 'label' => __('Create booking'), 'url' => route('api.booking.create'), 'payload' => $payloads['create'] ?? null]] as $step)
                        @if ($step['payload'] !== null)
                            @php($json = json_encode($step['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            <div class="overflow-hidden rounded-[10px] border border-divider" wire:key="payload-{{ $step['n'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-divider bg-muted/40 px-4 py-2.5">
                                    <p class="min-w-0 text-[13px] font-semibold text-ink">{{ $step['n'] }} — {{ $step['label'] }} · <code class="break-all text-[12px] font-normal text-secondary">POST {{ $step['url'] }}</code></p>
                                    <div x-data="{ copied: false }" class="shrink-0">
                                        <button type="button" class="rounded-[8px] border border-input-border px-2.5 py-1 text-[12.5px] font-semibold text-secondary transition hover:text-ink"
                                                @click="navigator.clipboard?.writeText(@js($json)).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                                            <span x-show="!copied">{{ __('Copy') }}</span>
                                            <span x-show="copied" x-cloak style="color:#3E5C3A;">{{ __('Copied') }}</span>
                                        </button>
                                    </div>
                                </div>
                                <pre class="overflow-x-auto bg-muted px-4 py-3 text-[12.5px] leading-relaxed"><code>{{ $json }}</code></pre>
                            </div>
                        @endif
                    @endforeach

                    <p class="text-[13px] text-faint">{{ __('Both calls need the salon\'s bearer token — the one pasted into the Custom Actions (Settings → Integrations → Voice-AI Booking API). A booking made by the test call lands on the test stylist and is removed at clean-up.') }}</p>
                </div>

                <div class="flex justify-end border-t border-divider px-6 py-4">
                    <x-ui.button wire:click="finish" loading="finish">{{ __('Finish & clean up') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
