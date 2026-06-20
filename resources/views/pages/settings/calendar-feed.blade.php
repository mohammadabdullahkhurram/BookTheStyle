<?php

use App\Services\Calendar\CalendarFeedService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    /** Whether the user currently has an active feed token. */
    public bool $connected = false;

    /** The plaintext token + URLs are held only right after (re)generating, so
     *  the secret link is shown once and never reconstructed from storage. */
    public ?string $subscribeUrl = null;

    public ?string $webcalUrl = null;

    public function mount(): void
    {
        $this->connected = (bool) Auth::user()->calendarConnection()->first()?->hasFeed();
    }

    /** Generate or rotate the feed token; show the fresh link once. */
    public function generate(CalendarFeedService $service): void
    {
        $token = $service->regenerate(Auth::user());

        $this->subscribeUrl = $service->subscribeUrl($token);
        $this->webcalUrl = $service->webcalUrl($token);
        $this->connected = true;

        Flux::toast(variant: 'success', text: __('Calendar link ready.'));
    }

    public function revoke(CalendarFeedService $service): void
    {
        $service->revoke(Auth::user());

        $this->subscribeUrl = null;
        $this->webcalUrl = null;
        $this->connected = false;

        Flux::toast(variant: 'success', text: __('Calendar link revoked.'));
    }
}; ?>

<x-ui.card class="mt-10 flex flex-col gap-5">
    <div>
        <h2 class="bts-card-title">{{ __('Personal calendar') }}</h2>
        <p class="mt-1 text-[14px] text-secondary">
            {{ __('Subscribe to a private, read-only feed of your bookings on Google, Apple, or Outlook calendar. It updates automatically; one-way only.') }}
        </p>
    </div>

    @if ($subscribeUrl)
        <div class="flex flex-col gap-4" x-data="{ copied: false }">
            <div class="flex items-end gap-2">
                <flux:input :label="__('Your subscribe link')" readonly value="{{ $subscribeUrl }}" class="flex-1" />
                <x-ui.button type="button" variant="secondary"
                    x-on:click="navigator.clipboard.writeText(@js($subscribeUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                </x-ui.button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button :href="$webcalUrl">{{ __('Subscribe on this device') }}</x-ui.button>
                <button type="button" wire:click="generate"
                    wire:confirm="{{ __('Regenerate the link? Your existing calendar subscription will stop updating until you re-add the new link.') }}"
                    class="bts-btn bts-btn-sm border border-input-border bg-card text-secondary hover:text-ink">
                    {{ __('Regenerate') }}
                </button>
            </div>

            <div class="rounded-[12px] bg-muted px-4 py-3 text-[13px] leading-relaxed text-secondary">
                <p class="font-medium text-body">{{ __('How to subscribe') }}</p>
                <p class="mt-1">{{ __('Google: Settings → Add calendar → From URL. Apple: File → New Calendar Subscription. Outlook: Add calendar → Subscribe from web.') }}</p>
            </div>

            <p class="text-[13px] text-faint">{{ __('Treat this link like a password — anyone with it can see your bookings. Regenerate to revoke a leaked link.') }}</p>
        </div>
    @elseif ($connected)
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2 text-[14px] font-medium text-body">
                <flux:icon.check-circle variant="micro" class="text-[#3E5C3A]" />
                <span>{{ __('Your calendar link is active.') }}</span>
            </div>
            <p class="text-[13px] text-secondary">
                {{ __('For your security the link is shown only once. Regenerate to reveal a fresh link — this breaks the old one.') }}
            </p>
            <div class="flex flex-wrap gap-3">
                <x-ui.button wire:click="generate"
                    wire:confirm="{{ __('Regenerate the link? The old link stops working immediately.') }}">
                    {{ __('Regenerate link') }}
                </x-ui.button>
                <button type="button" wire:click="revoke"
                    wire:confirm="{{ __('Revoke your calendar link? It will stop updating any calendar it was added to.') }}"
                    class="bts-btn bts-btn-sm border border-input-border bg-card text-danger hover:border-danger">
                    {{ __('Revoke') }}
                </button>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-3">
            <p class="text-[14px] text-secondary">
                {{ __('Generate a private link to add your bookings to your personal calendar.') }}
            </p>
            <div><x-ui.button wire:click="generate">{{ __('Generate calendar link') }}</x-ui.button></div>
        </div>
    @endif
</x-ui.card>
