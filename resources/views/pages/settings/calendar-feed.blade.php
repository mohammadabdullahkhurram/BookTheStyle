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

{{--
    The mental model this page must teach: there is NO universal click-to-add.
    You COPY the private link and PASTE it into your calendar app (Google and
    Outlook have no other way). The single honest shortcut is Apple Calendar's
    webcal:// handler. So the layout leads with the link + Copy, then gives
    one short recipe per app, then sets expectations (read-only, one-way,
    apps refresh on their own schedule).
--}}
<x-ui.card class="mt-10 flex flex-col gap-5">
    <div>
        <h2 class="bts-card-title">{{ __('Personal calendar') }}</h2>
        <p class="mt-1 text-[14px] text-secondary">
            {{ __('A private link that shows your bookings inside Google, Apple or Outlook calendar. You copy the link and paste it into your calendar app once — after that it updates by itself.') }}
        </p>
    </div>

    {{-- Watch-how popup: the video plays beside your link + the written steps, so
         you can follow along while copying the link without closing it. --}}
    <div>
        <x-ui.help-trigger doc="calendar-sync" :label="__('Watch: how to connect your calendar')">
            @if ($subscribeUrl)
                <div class="flex flex-col gap-3"
                     x-data="{
                         copied: false,
                         copy() {
                             const finish = () => { this.copied = true; setTimeout(() => this.copied = false, 2000); };
                             if (navigator.clipboard && window.isSecureContext) {
                                 navigator.clipboard.writeText(this.$refs.feedUrl.value).then(finish);
                                 return;
                             }
                             this.$refs.feedUrl.select();
                             document.execCommand('copy');
                             finish();
                         },
                     }">
                    <label class="flex flex-col gap-1.5">
                        <span class="bts-field-label">{{ __('Your calendar link') }}</span>
                        <input x-ref="feedUrl" type="text" readonly value="{{ $subscribeUrl }}" @focus="$el.select()"
                               class="w-full min-w-0 rounded-[11px] border border-input-border bg-field px-3 py-2 font-mono text-[12.5px] text-body" />
                    </label>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button type="button" variant="secondary" x-on:click="copy()">{{ __('Copy link') }}</x-ui.button>
                        <x-ui.button :href="$webcalUrl">{{ __('Open in Apple Calendar') }}</x-ui.button>
                        <span role="status" aria-live="polite" class="text-[13px] font-medium text-[#3E5C3A]"
                              x-text="copied ? {{ Js::from(__('Copied')) }} : ''"></span>
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
                        {{ __('Tap Open in Apple Calendar above, then confirm with Subscribe and Add. Or paste the copied link via File → New Calendar Subscription on a Mac.') }}
                    </p>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-body">{{ __('Google') }}</p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                        {{ __('Copy the link, go to calendar.google.com, then Other calendars +, then From URL, paste, and Add. It then syncs to your phone automatically.') }}
                    </p>
                    <p class="mt-1 text-[12.5px] font-medium text-warning">
                        {{ __('Heads up: Google only lets you add a subscription on a computer, not the phone app.') }}
                    </p>
                </div>

                <div>
                    <p class="text-[13px] font-semibold text-body">{{ __('Outlook') }}</p>
                    <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                        {{ __('Copy the link, then Add calendar, then Subscribe from web, paste, and Subscribe.') }}
                    </p>
                </div>
            </div>
        </x-ui.help-trigger>
    </div>

    @if ($subscribeUrl)
        <div class="flex flex-col gap-5">
            {{-- Step 1 — the link is the whole product; copying it is the action. --}}
            <div class="flex flex-col gap-2"
                 x-data="{
                     copied: false,
                     copy() {
                         const finish = () => { this.copied = true; setTimeout(() => this.copied = false, 2000); };
                         if (navigator.clipboard && window.isSecureContext) {
                             navigator.clipboard.writeText(this.$refs.feedUrl.value).then(finish);
                             return;
                         }
                         {{-- http dev fallback: the Clipboard API needs a secure context. --}}
                         this.$refs.feedUrl.select();
                         document.execCommand('copy');
                         finish();
                     },
                 }">
                <div class="bts-field-label">{{ __('Step 1 — copy your calendar link') }}</div>
                <p class="text-[13.5px] leading-relaxed text-secondary">
                    {{ __('This link is what you paste into your calendar app. For your security it is shown only this once — copy it now.') }}
                </p>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                    <input x-ref="feedUrl" type="text" readonly value="{{ $subscribeUrl }}" @focus="$el.select()"
                           aria-label="{{ __('Your calendar link') }}"
                           class="w-full min-w-0 flex-1 rounded-[11px] border border-input-border bg-field px-3 py-2 font-mono text-[12.5px] text-body" />
                    <div class="flex shrink-0 items-center gap-2">
                        <x-ui.button type="button" x-on:click="copy()">{{ __('Copy link') }}</x-ui.button>
                        <span role="status" aria-live="polite" class="text-[13px] font-medium text-[#3E5C3A]"
                              x-text="copied ? {{ Js::from(__('Copied')) }} : ''"></span>
                    </div>
                </div>
            </div>

            {{-- Step 2 — one short recipe per app. Google/Outlook have no
                 click-to-add; Apple's webcal shortcut is the only one. --}}
            <div class="flex flex-col gap-2">
                <div class="bts-field-label">{{ __('Step 2 — paste it into your calendar app') }}</div>
                <div class="flex flex-col divide-y divide-row rounded-[11px] border border-input-border">
                    <div class="px-4 py-3">
                        <p class="text-[13.5px] font-semibold text-ink">{{ __('Google Calendar') }}</p>
                        <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                            {{ __('On a computer: calendar.google.com → Other calendars + → From URL → paste the link → Add calendar. There is no click-to-add in Google — pasting is the only way. It then syncs to the phone app by itself.') }}
                        </p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[13.5px] font-semibold text-ink">{{ __('Apple Calendar') }}</p>
                        <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                            {{ __('Mac: File → New Calendar Subscription → paste the link. Or take the shortcut:') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                            <x-ui.button :href="$webcalUrl" variant="secondary" size="sm">{{ __('Open in Apple Calendar') }}</x-ui.button>
                            <span class="text-[12.5px] text-faint">{{ __('Works on a Mac or iPhone with Apple Calendar. If nothing opens, copy the link instead.') }}</span>
                        </div>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[13.5px] font-semibold text-ink">{{ __('Outlook') }}</p>
                        <p class="mt-0.5 text-[13px] leading-relaxed text-secondary">
                            {{ __('Add calendar → Subscribe from web → paste the link → Import.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Expectations: read-only, one-way, apps refresh on their own clock. --}}
            <div class="rounded-[11px] border border-input-border bg-field px-4 py-3">
                <p class="text-[13px] font-semibold text-body">{{ __('What to expect') }}</p>
                <ul class="mt-1 flex list-disc flex-col gap-1 ps-4 text-[13px] leading-relaxed text-secondary">
                    <li>{{ __('Read-only and one-way: your bookings appear in your calendar; nothing you add there ever flows back into the app.') }}</li>
                    <li>{{ __('It updates automatically, but each calendar app refreshes on its own schedule — Google can take several hours to show changes. That is normal, not a broken link.') }}</li>
                </ul>
            </div>

            @unless (\App\Support\PublicUrl::isPublic(config('app.url')))
                <p class="text-[13px] leading-relaxed text-secondary">
                    {{ __('You are on a local address right now — Google and Outlook can only reach this link once the app runs on its live URL. Everything above works as-is after deployment.') }}
                </p>
            @endunless

            <div class="flex flex-wrap items-center gap-3">
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

            <p class="text-[13px] text-faint">{{ __('Treat this link like a password — anyone with it can see your bookings. Regenerate to invalidate the old link and get a fresh one.') }}</p>
        </div>
    @elseif ($connected)
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2 text-[14px] font-medium text-body">
                <flux:icon.check-circle variant="micro" class="text-[#3E5C3A]" />
                <span>{{ __('Your calendar link is active.') }}</span>
            </div>
            <p class="text-[13px] leading-relaxed text-secondary">
                {{ __('For your security the link is shown only once. Regenerate to reveal a fresh link — the old one stops working immediately. The feed stays read-only and one-way, and calendar apps refresh it on their own schedule.') }}
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
            <p class="text-[14px] leading-relaxed text-secondary">
                {{ __('Generate a private link, then paste it into Google, Apple or Outlook calendar — the exact steps appear right here once the link exists.') }}
            </p>
            <div><x-ui.button wire:click="generate">{{ __('Generate calendar link') }}</x-ui.button></div>
        </div>
    @endif
</x-ui.card>
