{{-- Step 3: the Voice AI booking API token — included with the parent Settings component's scope. --}}
<x-ui.card class="flex flex-col gap-4">
    <h2 class="bts-card-title">{{ __('Voice AI booking API') }}</h2>
    <p class="text-[13.5px] leading-relaxed text-secondary">
        {{ __('The GoHighLevel voice assistant books through this salon\'s own engine using these endpoints, authenticated by a secret token. The token is shown once — store it in the GHL Custom Action. Regenerating invalidates the old token immediately.') }}
    </p>

    @if ($apiTokenPlain !== null)
        <div class="flex flex-col gap-2 rounded-[11px] border border-[#D8E4D5] bg-[#E7EFE4] px-4 py-3">
            <span class="text-[13px] font-semibold text-[#3E5C3A]">{{ __('Copy this token now — it will not be shown again.') }}</span>
            <code class="break-all font-mono text-[13.5px] text-ink" data-test="api-token">{{ $apiTokenPlain }}</code>
        </div>
    @elseif ($salon->api_token_generated_at !== null)
        <p class="text-[13.5px] text-body">
            {{ __('A token is active (generated :date). It cannot be viewed again — regenerate to replace it.', ['date' => $salon->api_token_generated_at->setTimezone($salon->timezone)->format('M j, Y g:i A')]) }}
        </p>
    @else
        <p class="text-[13.5px] text-faint">{{ __('No token yet — generate one to enable the booking API for this salon.') }}</p>
    @endif

    <div>
        @if ($salon->api_token_generated_at !== null)
            {{-- Themed confirm (replaces wire:confirm); first generation commits without one, as before. --}}
            <x-ui.button type="button" variant="secondary" x-on:click="$store.confirm.ask({ title: {{ Js::from(__('Regenerate API token')) }}, message: {{ Js::from(__('Regenerate the API token? The current token stops working immediately.')) }}, confirmLabel: {{ Js::from(__('Regenerate')) }}, danger: false }, () => $wire.generateApiToken())">
                {{ __('Regenerate token') }}
            </x-ui.button>
        @else
            <x-ui.button type="button" variant="secondary" wire:click="generateApiToken">
                {{ __('Generate token') }}
            </x-ui.button>
        @endif
    </div>

    <p class="text-[12.5px] text-faint">
        POST {{ route('api.booking.availability') }} · POST {{ route('api.booking.create') }} · POST {{ route('api.booking.cancel') }} · POST {{ route('api.booking.reschedule') }} — Authorization: Bearer &lt;token&gt;
    </p>

    {{-- End-to-end test: call this salon's own availability endpoint
         over the public URL — exactly what the GHL custom action does.
         Run it while a freshly generated token is on screen for the
         full 200-with-slots proof. --}}
    @can('manageGhlConnection', $salon)
        <div class="border-t border-row pt-4">
            @include('partials.integration-check', [
                'check' => 'voice',
                'label' => __('Test booking API'),
                'blocked' => ! \App\Support\PublicUrl::isPublic((string) config('app.url')),
                'blockedNote' => __('The booking API can only be tested over the app\'s live public URL — the same way the GHL custom action calls it. The button works automatically once the app is deployed.'),
            ])
        </div>
    @endcan
</x-ui.card>
