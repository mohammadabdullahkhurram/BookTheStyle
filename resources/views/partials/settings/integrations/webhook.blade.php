{{-- Step 4: the inbound webhook (URL, secret, delivery test) — included with the parent Settings component's scope. --}}
<x-ui.card class="flex flex-col gap-4">
    <h2 class="bts-card-title">{{ __('Inbound webhook') }}</h2>
    <p class="text-[14px] text-secondary">
        {{ __('Lets GoHighLevel push appointment changes back into the app. In your GHL workflow, add a custom webhook action pointing at this URL with the secret as an X-Webhook-Secret header.') }}
    </p>
    <div class="flex flex-col gap-1">
        <div class="bts-field-label">{{ __('Webhook URL (POST)') }}</div>
        <p class="font-mono text-[13px] text-body">{{ route('webhooks.ghl') }}</p>
    </div>
    <div class="flex flex-col gap-1">
        <div class="bts-field-label">{{ __('Secret — sent as the X-Webhook-Secret header') }}</div>
        @if ($ghlWebhookSecret)
            <p class="break-all font-mono text-[13px] text-body">{{ $ghlWebhookSecret }}</p>
        @else
            <p class="text-[13.5px] text-faint">{{ __('No secret yet — inbound calls are rejected until one exists.') }}</p>
        @endif
    </div>
    <div>
        @if ($ghlWebhookSecret)
            {{-- Themed confirm (replaces wire:confirm) — single-line Js::from, per the x-ui.confirm-modal recipe. --}}
            <x-ui.button type="button" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Rotate webhook secret')) }}, message: {{ Js::from(__('Rotate the webhook secret? The current one stops working immediately.')) }}, confirmLabel: {{ Js::from(__('Rotate')) }}, danger: false }, () => $wire.generateGhlWebhookSecret())">
                {{ __('Rotate secret') }}
            </x-ui.button>
        @else
            <x-ui.button type="button" variant="secondary" wire:click="generateGhlWebhookSecret">
                {{ __('Generate secret') }}
            </x-ui.button>
        @endif
    </div>

    {{-- Reachability + secret test: the app pings its own
         public webhook URL with a signed test payload. --}}
    <div class="border-t border-row pt-4">
        @include('partials.integration-check', [
            'check' => 'webhook',
            'label' => __('Test delivery'),
            'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
            'blockedNote' => __('Delivery can only be tested over the app\'s live public URL — GoHighLevel (and this check) cannot reach a local address. The button works automatically once the app is deployed.'),
        ])
    </div>
</x-ui.card>
