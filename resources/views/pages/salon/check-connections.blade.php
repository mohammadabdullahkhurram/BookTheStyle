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
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Integrations')" :title="__('Health check')">
            <x-slot:subtitle>{{ __('Validate this salon\'s whole setup and the site\'s operational health — with disposable test records nothing a client can ever see.') }}</x-slot:subtitle>
        </x-ui.page-header>

        <x-ui.card class="flex flex-col gap-4">
            <div>
                <h2 class="bts-card-title">{{ __('How this works') }}</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-secondary">{{ __('Running the check creates three temporary records for this salon — “Bluejaypro Stylist”, “Bluejaypro Hair Cut”, and “Bluejaypro Test Client” — books one real test appointment through the same engine the Voice AI uses, and validates everything else read-only: integrations, notifications, the scheduler and queue, salon readiness, and the system itself. The test records are invisible to clients and are deleted when you finish — or automatically after 24 hours.') }}</p>
                <p class="mt-2 text-[13.5px] leading-relaxed text-secondary">{{ __('BookTheStyle can only test its own side automatically. The GHL side — the Voice AI’s Custom Actions — fires from inside GHL, so it is verified by the round-trip at the bottom: you run the generated test calls in GHL, and this page confirms when they arrive.') }}</p>
            </div>
            <form wire:submit="run" class="flex flex-col gap-3 sm:flex-row sm:items-end" novalidate>
                <div class="w-full sm:max-w-xs">
                    <flux:input wire:model="password" type="password" :label="__('Confirm your password to run')" autocomplete="current-password" required />
                </div>
                <x-ui.button type="submit" loading="run">{{ __('Run health check') }}</x-ui.button>
            </form>
        </x-ui.card>

        @if ($summary !== null)
            {{-- Top-line summary. --}}
            <x-ui.card class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <p class="text-[15px] font-semibold text-ink">
                    {{ trans_choice(':count check passed|:count checks passed', $summary['pass'], ['count' => $summary['pass']]) }}<!--
                -->@if ($summary['warn'] > 0), {{ trans_choice(':count warning|:count warnings', $summary['warn'], ['count' => $summary['warn']]) }}@endif<!--
                -->@if ($summary['fail'] > 0), {{ trans_choice(':count failed|:count failed', $summary['fail'], ['count' => $summary['fail']]) }}@endif
                </p>
                <p class="text-[13px] text-faint">{{ __('Ran :when.', ['when' => \Illuminate\Support\Carbon::parse($ranAt)->setTimezone($salon->timezone)->format('M j, Y g:i A')]) }}</p>
                @if ($summary['fail'] === 0 && $summary['warn'] === 0)
                    <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('All clear') }}</span>
                @endif
            </x-ui.card>

            {{-- Categorized report. --}}
            @foreach ($categories as $category)
                <x-ui.card class="flex flex-col gap-3" wire:key="category-{{ $category['key'] }}">
                    <h2 class="bts-card-title">{{ $category['label'] }}</h2>
                    <ul class="flex flex-col divide-y divide-row">
                        @foreach ($category['checks'] as $line)
                            <li class="flex gap-3 py-3" wire:key="check-{{ $line['key'] }}">
                                @if ($line['status'] === 'pass')
                                    <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0" style="color:#3E5C3A;" />
                                @elseif ($line['status'] === 'warn')
                                    <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0" style="color:#8A6D1F;" />
                                @else
                                    <flux:icon.x-circle variant="mini" class="mt-0.5 shrink-0" style="color:#8A4B2D;" />
                                @endif
                                <div class="min-w-0">
                                    <p class="text-[14px] font-semibold text-ink">{{ $line['label'] }}</p>
                                    <p class="text-[13.5px] leading-relaxed text-secondary">{{ $line['message'] }}</p>
                                    @if ($line['fix'] !== null)
                                        <p class="mt-0.5 text-[13px] leading-relaxed" style="color:#8A6D1F;">{{ __('Fix:') }} {{ $line['fix'] }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endforeach

            {{-- The honest GHL round-trip. --}}
            <x-ui.card class="flex flex-col gap-4" wire:poll.5s="$refresh">
                <div>
                    <h2 class="bts-card-title">{{ __('GHL side — verified by round-trip') }}</h2>
                    <p class="mt-1 text-[13.5px] leading-relaxed text-secondary">{{ __('BookTheStyle cannot fire GHL\'s Custom Actions itself — they run inside GHL. Paste each payload into the matching Custom Action test in GHL (using this salon\'s own token) and run it against the test stylist. The moment a correctly-authenticated call arrives here, the indicator turns green.') }}</p>
                </div>

                <div class="rounded-[10px] border px-4 py-3" style="{{ $this->roundTripConfirmed ? 'background-color:#E7EFE4;border-color:#C8DAC2;' : 'background-color:#F0EEEA;border-color:#DAD5CD;' }}">
                    <p class="text-[12px] font-semibold uppercase tracking-[0.08em]" style="color:{{ $this->roundTripConfirmed ? '#3E5C3A' : '#6B6862' }};">{{ __('Last received call') }}</p>
                    <p class="text-[13.5px]" style="color:{{ $this->roundTripConfirmed ? '#3E5C3A' : '#6B6862' }};">
                        @if ($this->roundTripConfirmed)
                            {{ __('GHL reached BookTheStyle: :path at :when — the wiring works.', ['path' => $this->lastCall['path'], 'when' => \Illuminate\Support\Carbon::parse($this->lastCall['at'])->setTimezone($salon->timezone)->format('g:i:s A')]) }}
                        @else
                            {{ __('No call received since this check started. Run the payloads in GHL — this updates by itself.') }}
                        @endif
                    </p>
                </div>

                <div>
                    <p class="mb-1 text-[13px] font-semibold text-ink">{{ __('1 — Check availability') }} · <code class="text-[12px]">POST {{ route('api.booking.availability') }}</code></p>
                    <pre class="overflow-x-auto rounded-[10px] border border-divider bg-muted px-4 py-3 text-[12.5px] leading-relaxed"><code>{{ json_encode($payloads['availability'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                </div>
                @if (($payloads['create'] ?? null) !== null)
                    <div>
                        <p class="mb-1 text-[13px] font-semibold text-ink">{{ __('2 — Create booking') }} · <code class="text-[12px]">POST {{ route('api.booking.create') }}</code></p>
                        <pre class="overflow-x-auto rounded-[10px] border border-divider bg-muted px-4 py-3 text-[12.5px] leading-relaxed"><code>{{ json_encode($payloads['create'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    </div>
                @endif
                <p class="text-[13px] text-faint">{{ __('Both calls need the salon\'s bearer token — the one pasted into the Custom Actions (Settings → Integrations → Voice-AI Booking API). A booking made by the test call lands on the test stylist and is removed at clean-up.') }}</p>

                <div class="flex justify-end border-t border-divider pt-4">
                    <x-ui.button wire:click="finish" loading="finish">{{ __('Finish & clean up') }}</x-ui.button>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
