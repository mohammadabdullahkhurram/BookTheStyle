<?php

use App\Models\Salon;
use App\Services\Diagnostics\ConnectionDiagnostics;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Check connections" — one-click validation of the whole GHL × BTS setup
 * for THIS salon, agency owner/admin ONLY (SalonPolicy::runDiagnostics via
 * before(); salon roles, delegated agency_users, guests all refused; demo
 * salons 404 outright). A sudo-style password confirmation gates the run.
 *
 * THE HONEST SPLIT, stated on the page too: the checks below exercise the
 * BookTheStyle side automatically (real engine, real endpoints, a real
 * disposable booking). BTS cannot fire a GHL Custom Action itself — the GHL
 * wiring is verified by the manual round-trip: paste the generated payloads
 * into GHL, run them, and the indicator greens when the call arrives.
 */
new #[Title('Check connections')] class extends Component {
    public Salon $salon;

    public string $password = '';

    /** @var list<array{key: string, label: string, passed: bool, message: string}> */
    public array $report = [];

    public ?string $ranAt = null;

    /** @var array{availability: array<string, mixed>, create: array<string, mixed>|null}|null */
    public ?array $payloads = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('runDiagnostics', $salon);
        abort_if($salon->is_demo, 404); // never in the demo, not even for operators

        $this->salon = $salon;
    }

    public function run(ConnectionDiagnostics $diagnostics): void
    {
        $this->authorize('runDiagnostics', $this->salon);
        abort_if($this->salon->is_demo, 404);

        // Sudo-style confirmation: the operator re-enters THEIR OWN login
        // password to authorise creating test records on a live salon.
        $this->validate(
            ['password' => ['required', 'current_password']],
            ['password.current_password' => __('That is not your password — re-enter your own login password to run the check.')],
        );

        $this->report = $diagnostics->run($this->salon);
        $this->payloads = $diagnostics->roundTripPayloads($this->salon);
        $this->ranAt = now()->toIso8601String();
        $this->reset('password');
    }

    public function finish(ConnectionDiagnostics $diagnostics): void
    {
        $this->authorize('runDiagnostics', $this->salon);
        abort_if($this->salon->is_demo, 404);

        $diagnostics->teardown($this->salon);
        $this->reset(['report', 'ranAt', 'payloads']);

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
        <x-ui.page-header :overline="__('Integrations')" :title="__('Check connections')">
            <x-slot:subtitle>{{ __('Validate this salon\'s whole booking setup with disposable test records — nothing a client can ever see.') }}</x-slot:subtitle>
        </x-ui.page-header>

        <x-ui.card class="flex flex-col gap-4">
            <div>
                <h2 class="bts-card-title">{{ __('How this works') }}</h2>
                <p class="mt-1 text-[13.5px] leading-relaxed text-secondary">{{ __('Running the check creates three temporary records for this salon — “Bluejaypro Stylist”, “Bluejaypro Hair Cut”, and “Bluejaypro Test Client” — books a real test appointment through the same engine the Voice AI uses, and verifies every BookTheStyle-side piece. They are invisible to clients (widget, booking, reports) and are deleted when you finish — or automatically after 24 hours.') }}</p>
                <p class="mt-2 text-[13.5px] leading-relaxed text-secondary">{{ __('BookTheStyle can only test its own side automatically. The GHL side — the Voice AI’s Custom Actions — fires from inside GHL, so it is verified by the round-trip below: you run the generated test calls in GHL, and this page confirms when they arrive.') }}</p>
            </div>
            <form wire:submit="run" class="flex flex-col gap-3 sm:flex-row sm:items-end" novalidate>
                <div class="w-full sm:max-w-xs">
                    <flux:input wire:model="password" type="password" :label="__('Confirm your password to run')" autocomplete="current-password" required />
                </div>
                <x-ui.button type="submit" loading="run">{{ __('Test') }}</x-ui.button>
            </form>
        </x-ui.card>

        @if ($report !== [])
            <x-ui.card class="flex flex-col gap-4">
                <div>
                    <h2 class="bts-card-title">{{ __('BookTheStyle side — tested automatically') }}</h2>
                    <p class="mt-1 text-[13px] text-faint">{{ __('Ran :when.', ['when' => \Illuminate\Support\Carbon::parse($ranAt)->setTimezone($salon->timezone)->format('M j, Y g:i A')]) }}</p>
                </div>
                <ul class="flex flex-col divide-y divide-row">
                    @foreach ($report as $line)
                        <li class="flex gap-3 py-3" wire:key="check-{{ $line['key'] }}">
                            @if ($line['passed'])
                                <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0" style="color:#3E5C3A;" />
                            @else
                                <flux:icon.x-circle variant="mini" class="mt-0.5 shrink-0" style="color:#8A4B2D;" />
                            @endif
                            <div class="min-w-0">
                                <p class="text-[14px] font-semibold text-ink">{{ $line['label'] }}</p>
                                <p class="text-[13.5px] leading-relaxed text-secondary">{{ $line['message'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>

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
