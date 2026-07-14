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

    {{-- Watch-how popup: the video plays beside your link + the written steps, so
         you can follow along while copying the link without closing it. --}}
    <div>
        <x-ui.help-trigger doc="calendar-sync" :label="__('Watch: how to connect your calendar')">
            @if ($subscribeUrl)
                <div class="flex flex-col gap-3" x-data="{ copiedInModal: false }">
                    <flux:input :label="__('Your subscribe link')" readonly value="{{ $subscribeUrl }}" />
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="button" variant="secondary"
                            x-on:click="navigator.clipboard.writeText(@js($subscribeUrl)); copiedInModal = true; setTimeout(() => copiedInModal = false, 1500)">
                            <span x-text="copiedInModal ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </x-ui.button>
                        <x-ui.button :href="$webcalUrl">{{ __('Subscribe on this device') }}</x-ui.button>
                    </div>
                </div>
            @else
                <p class="text-[14px] text-secondary">
                    {{ __('Generate your calendar link below first — it will then appear here to copy alongside the steps.') }}
                </p>
            @endif

            <div class="mt-5 flex flex-col gap-4">
                <h3 class="text-[14px] font-semibold text-ink">{{ __('Step by step') }}</h3>

                <div>
                    <p class="text-[13px] font-semibold text-body">{{ __('Apple / iPhone') }}</p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                        {{ __('Tap the subscribe button above, then tap Subscribe and Add.') }}
                    </p>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-body">{{ __('Google') }}</p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                        {{ __('Go to calendar.google.com, then Other calendars +, then From URL, paste the link, and Add. It then syncs to your phone automatically.') }}
                    </p>
                    <p class="mt-1 text-[12.5px] font-medium text-warning">
                        {{ __('Heads up: Google only lets you add a subscription on a computer, not the phone app.') }}
                    </p>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-body">{{ __('Outlook') }}</p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                        {{ __('Add calendar, then Subscribe from web, paste the link, and Subscribe.') }}
                    </p>
                </div>
            </div>
        </x-ui.help-trigger>
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
                {{-- Themed confirm (replaces wire:confirm): open the global dialog, run the wire action only on confirm. --}}
                <button type="button"
                    x-on:click="$store.confirm.ask({
                        title: {{ Js::from(__('Regenerate link')) }},
                        message: {{ Js::from(__('Regenerate the link? Your existing calendar subscription will stop updating until you re-add the new link.')) }},
                        confirmLabel: {{ Js::from(__('Regenerate')) }},
                        danger: false,
                    }, () => $wire.generate())"
                    class="bts-btn bts-btn-sm border border-input-border bg-card text-secondary hover:text-ink">
                    {{ __('Regenerate') }}
                </button>
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
                <x-ui.button
                    x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Regenerate link')) }}, message: {{ Js::from(__('Regenerate the link? The old link stops working immediately.')) }}, confirmLabel: {{ Js::from(__('Regenerate')) }}, danger: false }, () => $wire.generate())">
                    {{ __('Regenerate link') }}
                </x-ui.button>
                <button type="button"
                    x-on:click="$store.confirm.ask({
                        title: {{ Js::from(__('Revoke link')) }},
                        message: {{ Js::from(__('Revoke your calendar link? It will stop updating any calendar it was added to.')) }},
                        confirmLabel: {{ Js::from(__('Revoke')) }},
                        danger: true,
                    }, () => $wire.revoke())"
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
