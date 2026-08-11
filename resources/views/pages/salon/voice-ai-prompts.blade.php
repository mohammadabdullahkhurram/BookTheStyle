<?php

use App\Models\Salon;
use App\Services\VoiceAi\VoiceAiDefaults;
use App\Services\VoiceAi\VoiceAiPromptGenerator;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * The "Voice AI Prompts" settings tab — rendered NESTED inside the salon
 * Settings page (its own component so the 1,400-line Settings component
 * does not grow). Guarded exactly like the Integrations tab: the
 * manageGhlConnection gate (salon owner/manager + agency operators; salon
 * staff denied; demo salons denied outright by the policy, so the tab is
 * unreachable in demo context).
 *
 * Fill-once form → four generated knowledge-base articles (copy-paste into
 * the salon's GHL KB; a future push-to-GHL consumes the same
 * VoiceAiPromptGenerator output). Empty fields prefill as editable DRAFTS
 * from real salon records (VoiceAiDefaults); saved values always win.
 */
new class extends Component {
    public Salon $salon;

    /** @var list<array{name: string, specialties: string, days: string}> */
    public array $stylists = [];

    public string $pair_need = '';
    public string $pair_stylist = '';

    public string $cancel_notice = '24 hours';
    public string $cancel_fee = '50% of the service price';
    public string $late_grace = '15 minutes';

    public string $deposits = 'none';
    public string $deposit_detail = '';

    public bool $pay_card = true;
    public bool $pay_cash = true;
    public bool $pay_mobile = true;
    public string $payments_extra = '';

    public string $walkins = 'yes';
    public string $kids = 'cuts';

    public string $address_spoken = '';
    public string $parking = '';
    public string $transit = '';
    public string $hours_spoken = '';
    public string $holiday_note = '';

    public string $dont_offer = '';
    public string $referral = '';

    /** @var list<string> fields whose current value is an unsaved prefilled draft */
    public array $drafts = [];

    public function mount(Salon $salon): void
    {
        $this->authorize('manageGhlConnection', $salon);
        // Belt-and-suspenders: the policy denies demo salons for salon
        // members, but agency operators pass via before() — never here.
        abort_if($salon->is_demo, 403);
        $this->salon = $salon;

        $saved = (array) ($salon->voice_ai_settings ?? []);
        $defaults = app(VoiceAiDefaults::class);

        foreach (['pair_need', 'pair_stylist', 'cancel_notice', 'cancel_fee', 'late_grace', 'deposits', 'deposit_detail', 'walkins', 'kids', 'address_spoken', 'parking', 'transit', 'hours_spoken', 'holiday_note', 'dont_offer', 'referral', 'payments_extra'] as $field) {
            if (array_key_exists($field, $saved)) {
                $this->{$field} = (string) $saved[$field];
            }
        }
        foreach (['pay_card', 'pay_cash', 'pay_mobile'] as $field) {
            if (array_key_exists($field, $saved)) {
                $this->{$field} = (bool) $saved[$field];
            }
        }
        $this->stylists = array_values((array) ($saved['stylists'] ?? []));

        // Smart defaults: any field still empty prefills from what the salon
        // already knows — an ordinary editable value, badged as a draft.
        if ($this->stylists === []) {
            $this->stylists = $defaults->stylists($salon);
            if ($this->stylists !== []) {
                $this->drafts[] = 'stylists';
            }
        }
        if ($this->address_spoken === '' && ($draft = $defaults->addressSpoken($salon)) !== '') {
            $this->address_spoken = $draft;
            $this->drafts[] = 'address_spoken';
        }
        if ($this->hours_spoken === '' && ($draft = $defaults->hoursSpoken($salon)) !== '') {
            $this->hours_spoken = $draft;
            $this->drafts[] = 'hours_spoken';
        }

        if ($this->stylists === []) {
            $this->stylists = [['name' => '', 'specialties' => '', 'days' => '']];
        }
    }

    public function addStylistRow(): void
    {
        $this->stylists[] = ['name' => '', 'specialties' => '', 'days' => ''];
    }

    public function removeStylistRow(int $index): void
    {
        unset($this->stylists[$index]);
        $this->stylists = array_values($this->stylists);
    }

    /** Append active bookable staff not already listed — never removes a row. */
    public function addMissingStylists(): void
    {
        $existing = array_map(fn (array $row): string => mb_strtolower(trim((string) ($row['name'] ?? ''))), $this->stylists);

        foreach (app(VoiceAiDefaults::class)->stylists($this->salon) as $row) {
            if (! in_array(mb_strtolower($row['name']), $existing, true)) {
                $this->stylists[] = $row;
            }
        }
    }

    /** Regenerate ONE spoken draft from salon data (the ↻ links). */
    public function refill(string $field): void
    {
        $defaults = app(VoiceAiDefaults::class);

        match ($field) {
            'address_spoken' => $this->address_spoken = $defaults->addressSpoken($this->salon),
            'hours_spoken' => $this->hours_spoken = $defaults->hoursSpoken($this->salon),
            default => null,
        };

        if (! in_array($field, $this->drafts, true)) {
            $this->drafts[] = $field;
        }
    }

    public function save(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        if (\App\Support\DemoMode::blocksWrite($this->salon)) {
            return;
        }

        $this->validate([
            'stylists' => ['array', 'max:50'],
            'stylists.*.name' => ['nullable', 'string', 'max:120'],
            'stylists.*.specialties' => ['nullable', 'string', 'max:255'],
            'stylists.*.days' => ['nullable', 'string', 'max:120'],
            'pair_need' => ['nullable', 'string', 'max:120'],
            'pair_stylist' => ['nullable', 'string', 'max:120'],
            'cancel_notice' => ['nullable', 'string', 'max:60'],
            'cancel_fee' => ['nullable', 'string', 'max:120'],
            'late_grace' => ['nullable', 'string', 'max:60'],
            'deposits' => ['required', 'in:none,some'],
            'deposit_detail' => ['nullable', 'string', 'max:255'],
            'payments_extra' => ['nullable', 'string', 'max:255'],
            'walkins' => ['required', 'in:yes,appointment_only'],
            'kids' => ['required', 'in:cuts,wait_only'],
            'address_spoken' => ['nullable', 'string', 'max:255'],
            'parking' => ['nullable', 'string', 'max:255'],
            'transit' => ['nullable', 'string', 'max:255'],
            'hours_spoken' => ['nullable', 'string', 'max:500'],
            'holiday_note' => ['nullable', 'string', 'max:255'],
            'dont_offer' => ['nullable', 'string', 'max:255'],
            'referral' => ['nullable', 'string', 'max:255'],
        ]);

        $this->stylists = array_values(array_filter(
            $this->stylists,
            fn (array $row): bool => trim((string) ($row['name'] ?? '')) !== ''
                || trim((string) ($row['specialties'] ?? '')) !== ''
                || trim((string) ($row['days'] ?? '')) !== '',
        ));

        $this->salon->forceFill(['voice_ai_settings' => $this->settings()])->save();
        $this->salon->refresh();
        $this->drafts = [];

        if ($this->stylists === []) {
            $this->stylists = [['name' => '', 'specialties' => '', 'days' => '']];
        }

        Flux::toast(variant: 'success', text: __('Voice AI settings saved — the articles below are ready to copy.'));
    }

    /** The persisted/generator-facing settings shape. @return array<string, mixed> */
    public function settings(): array
    {
        $payments = [];
        if ($this->pay_card) {
            $payments[] = 'card';
        }
        if ($this->pay_cash) {
            $payments[] = 'cash';
        }
        if ($this->pay_mobile) {
            $payments[] = 'mobile payments like Apple Pay';
        }
        if (trim($this->payments_extra) !== '') {
            $payments[] = trim($this->payments_extra);
        }

        return [
            'stylists' => $this->stylists,
            'pair_need' => $this->pair_need,
            'pair_stylist' => $this->pair_stylist,
            'cancel_notice' => $this->cancel_notice,
            'cancel_fee' => $this->cancel_fee,
            'late_grace' => $this->late_grace,
            'deposits' => $this->deposits,
            'deposit_detail' => $this->deposit_detail,
            'payments' => $payments,
            'pay_card' => $this->pay_card,
            'pay_cash' => $this->pay_cash,
            'pay_mobile' => $this->pay_mobile,
            'payments_extra' => $this->payments_extra,
            'walkins' => $this->walkins,
            'kids' => $this->kids,
            'address_spoken' => $this->address_spoken,
            'parking' => $this->parking,
            'transit' => $this->transit,
            'hours_spoken' => $this->hours_spoken,
            'holiday_note' => $this->holiday_note,
            'dont_offer' => $this->dont_offer,
            'referral' => $this->referral,
        ];
    }

    /** @return list<array{title: string, body: string}> */
    #[Computed]
    public function previews(): array
    {
        return app(VoiceAiPromptGenerator::class)->generate($this->settings());
    }

    /** @return list<string> human labels of blank required fields */
    #[Computed]
    public function missing(): array
    {
        $missing = [];
        $hasStylist = array_filter($this->stylists, fn (array $row): bool => trim((string) ($row['name'] ?? '')) !== '') !== [];

        if (! $hasStylist) {
            $missing[] = __('at least one stylist');
        }
        if (trim($this->address_spoken) === '') {
            $missing[] = __('the spoken address');
        }
        if (trim($this->hours_spoken) === '') {
            $missing[] = __('the spoken hours');
        }
        if (trim($this->dont_offer) === '') {
            $missing[] = __('the services you don\'t offer');
        }

        return $missing;
    }
}; ?>

<div class="flex flex-col gap-5">
    <p class="text-[13.5px] leading-relaxed text-secondary">{{ __('Fill this in once and save, then copy each article into this salon\'s GHL knowledge base. The agent prompt and trigger below never change per salon.') }}</p>

    <form wire:submit="save" class="flex flex-col gap-5" novalidate>
        {{-- 1 · Team --}}
        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('1 · Team') }}</h2>
            @if (in_array('stylists', $drafts, true))
                <p class="bts-pill self-start" style="background-color:#E3EDF6;color:#356088;">{{ __('from your salon profile — check the wording') }}</p>
            @endif
            <div class="flex flex-col gap-3">
                @foreach ($stylists as $i => $row)
                    <div class="grid gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]" wire:key="stylist-{{ $i }}">
                        <flux:input wire:model="stylists.{{ $i }}.name" :placeholder="__('Name')" :aria-label="__('Stylist name')" />
                        <flux:input wire:model="stylists.{{ $i }}.specialties" :placeholder="__('Specialties (optional) — e.g. balayage, curly hair')" :aria-label="__('Specialties')" />
                        <flux:input wire:model="stylists.{{ $i }}.days" :placeholder="__('Days (optional) — e.g. Tuesday to Friday')" :aria-label="__('Days')" />
                        <button type="button" wire:click="removeStylistRow({{ $i }})" class="bts-btn bts-btn-secondary bts-btn-sm self-center" aria-label="{{ __('Remove row') }}">×</button>
                    </div>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="addStylistRow">{{ __('Add a stylist') }}</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" wire:click="addMissingStylists">{{ __('Add missing stylists from staff') }}</x-ui.button>
            </div>
            <div class="grid gap-2 border-t border-row pt-4 sm:grid-cols-2">
                <flux:input wire:model="pair_need" :label="__('“For X …” (optional pairing)')" :placeholder="__('e.g. vivid color')" />
                <flux:input wire:model="pair_stylist" :label="__('“… suggest Y”')" :placeholder="__('e.g. Maya')" />
            </div>
        </x-ui.card>

        {{-- 2 · Policies --}}
        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('2 · Policies') }}</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="cancel_notice" :label="__('Cancellation notice')" />
                <flux:input wire:model="cancel_fee" :label="__('Late-cancel fee (blank = no fee sentence)')" />
                <flux:input wire:model="late_grace" :label="__('Late-arrival grace')" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-2">
                    <flux:radio.group wire:model.live="deposits" variant="segmented" :label="__('Deposits')">
                        <flux:radio value="none" :label="__('None')" />
                        <flux:radio value="some" :label="__('Some services')" />
                    </flux:radio.group>
                    @if ($deposits === 'some')
                        <flux:input wire:model="deposit_detail" :placeholder="__('e.g. color services over $150 take a $30 deposit.')" :aria-label="__('Deposit detail')" />
                    @endif
                </div>
                <div class="flex flex-col gap-2">
                    <div class="bts-field-label">{{ __('Payments') }}</div>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <flux:checkbox wire:model="pay_card" :label="__('Card')" />
                        <flux:checkbox wire:model="pay_cash" :label="__('Cash')" />
                        <flux:checkbox wire:model="pay_mobile" :label="__('Mobile (Apple Pay etc.)')" />
                    </div>
                    <flux:input wire:model="payments_extra" :placeholder="__('Anything else — e.g. gift cards')" :aria-label="__('Other payments')" />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:radio.group wire:model="walkins" variant="segmented" :label="__('Walk-ins')">
                    <flux:radio value="yes" :label="__('Yes, when open')" />
                    <flux:radio value="appointment_only" :label="__('Appointment only')" />
                </flux:radio.group>
                <flux:radio.group wire:model="kids" variant="segmented" :label="__('Kids')">
                    <flux:radio value="cuts" :label="__('We do kids’ cuts')" />
                    <flux:radio value="wait_only" :label="__('Welcome to wait only')" />
                </flux:radio.group>
            </div>
        </x-ui.card>

        {{-- 3 · Location & hours --}}
        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('3 · Location & hours') }}</h2>
            <div class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="bts-field-label">{{ __('Address, as spoken') }}</div>
                    @if (in_array('address_spoken', $drafts, true))
                        <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('from your salon profile — check the wording') }}</span>
                    @endif
                    <button type="button" class="text-[12.5px] font-medium text-accent transition hover:text-accent-hover"
                            x-on:click="$wire.address_spoken.trim() !== '' ? $store.confirm.ask({ title: {{ Js::from(__('Refill address')) }}, message: {{ Js::from(__('Replace the current wording with a fresh draft from the salon profile?')) }}, confirmLabel: {{ Js::from(__('Refill')) }}, danger: false }, () => $wire.refill('address_spoken')) : $wire.refill('address_spoken')">{{ __('↻ refill from salon data') }}</button>
                </div>
                <flux:input wire:model="address_spoken" :placeholder="__('e.g. We\'re at 12 Main Street in Springfield')" :aria-label="__('Address, as spoken')" class="{{ trim($address_spoken) === '' ? '!border-[#E2CFA4]' : '' }}" />
                <p class="text-[12.5px] text-faint">{{ __('Say it the way you\'d say it on the phone — no zip code, no unit-number noise.') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="parking" :label="__('Parking (optional)')" :placeholder="__('e.g. Free parking behind the building.')" />
                <flux:input wire:model="transit" :label="__('Transit (optional)')" :placeholder="__('e.g. Two blocks from Central Station.')" />
            </div>
            <div class="flex flex-col gap-1.5">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="bts-field-label">{{ __('Hours, as spoken') }}</div>
                    @if (in_array('hours_spoken', $drafts, true))
                        <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('from your salon profile — check the wording') }}</span>
                    @endif
                    <button type="button" class="text-[12.5px] font-medium text-accent transition hover:text-accent-hover"
                            x-on:click="$wire.hours_spoken.trim() !== '' ? $store.confirm.ask({ title: {{ Js::from(__('Refill hours')) }}, message: {{ Js::from(__('Replace the current wording with a fresh draft from the staff working hours?')) }}, confirmLabel: {{ Js::from(__('Refill')) }}, danger: false }, () => $wire.refill('hours_spoken')) : $wire.refill('hours_spoken')">{{ __('↻ refill from salon data') }}</button>
                </div>
                <flux:input wire:model="hours_spoken" :placeholder="__('e.g. Tuesday to Friday nine to seven, Saturdays nine to five, closed Sunday and Monday')" :aria-label="__('Hours, as spoken')" class="{{ trim($hours_spoken) === '' ? '!border-[#E2CFA4]' : '' }}" />
            </div>
            <flux:input wire:model="holiday_note" :label="__('Holiday note (optional)')" :placeholder="__('e.g. We\'re closed on public holidays.')" />
        </x-ui.card>

        {{-- 4 · Services --}}
        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('4 · Services') }}</h2>
            <div class="flex flex-col gap-1.5">
                <flux:input wire:model="dont_offer" :label="__('Services you do NOT offer')" :placeholder="__('e.g. nails, lash extensions, barber fades')" class="{{ trim($dont_offer) === '' ? '!border-[#E2CFA4]' : '' }}" />
                <p class="text-[12.5px] text-faint">{{ __('This is what stops Joy improvising when someone asks for nails.') }}</p>
            </div>
            <flux:input wire:model="referral" :label="__('Referral (optional)')" :placeholder="__('e.g. Polished Nail Bar two doors down')" />
        </x-ui.card>

        <div><x-ui.button type="submit" loading="save">{{ __('Save Voice AI settings') }}</x-ui.button></div>
    </form>

    {{-- Missing-required notice — the ONLY amber on the page. --}}
    @if ($this->missing !== [])
        <div class="rounded-[12px] border px-4 py-3 text-[13.5px]" style="background-color:#F6EFE2;border-color:#E2CFA4;color:#8A6D1F;">
            {{ __('Still missing: :list. The previews below use [PLACEHOLDER] until these are filled.', ['list' => implode(', ', $this->missing)]) }}
        </div>
    @endif

    {{-- The four generated articles — copy title + body into the GHL KB. --}}
    @foreach ($this->previews as $article)
        <x-ui.card padding="p-0" class="overflow-hidden" wire:key="preview-{{ $loop->index }}">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-divider px-5 py-3.5">
                <h3 class="text-[14.5px] font-semibold text-ink">{{ $article['title'] }}</h3>
                <div class="flex gap-2">
                    <div x-data="{ copied: false }">
                        <button type="button" class="rounded-[8px] border border-input-border px-2.5 py-1 text-[12.5px] font-semibold text-secondary transition hover:text-ink"
                                x-on:click="navigator.clipboard?.writeText(@js($article['title'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                            <span x-show="!copied">{{ __('Copy title') }}</span>
                            <span x-show="copied" x-cloak style="color:#3E5C3A;">{{ __('Copied ✓') }}</span>
                        </button>
                    </div>
                    <div x-data="{ copied: false }">
                        <button type="button" class="rounded-[8px] border border-input-border px-2.5 py-1 text-[12.5px] font-semibold text-secondary transition hover:text-ink"
                                x-on:click="navigator.clipboard?.writeText(@js($article['body'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                            <span x-show="!copied">{{ __('Copy body') }}</span>
                            <span x-show="copied" x-cloak style="color:#3E5C3A;">{{ __('Copied ✓') }}</span>
                        </button>
                    </div>
                </div>
            </div>
            <pre class="whitespace-pre-wrap bg-muted px-5 py-4 text-[13.5px] leading-relaxed text-body" style="font-family:inherit;">{{ $article['body'] }}</pre>
        </x-ui.card>
    @endforeach

    {{-- Static texts — identical for every salon, read-only, collapsed. --}}
    @foreach ([
        ['label' => __('Joy — agent prompt · Paste into Voice AI → Agent Goals → Advanced Mode'), 'text' => config('voice_ai_prompts.agent_prompt')],
        ['label' => __('Knowledge base trigger · Paste into \'When to use this knowledge base\' (GHL max 500 chars — the real text is 493)'), 'text' => config('voice_ai_prompts.kb_trigger')],
        ['label' => __('KB article: How to handle advice questions and unknowns · add as title + body'), 'text' => config('voice_ai_prompts.rules_article')],
    ] as $i => $static)
        <details class="overflow-hidden rounded-[14px] border border-divider bg-card" wire:key="static-{{ $i }}">
            <summary class="cursor-pointer px-5 py-3.5 text-[14px] font-semibold text-ink">{{ $static['label'] }}</summary>
            <div class="border-t border-divider">
                <div class="flex justify-end px-5 pt-3">
                    <div x-data="{ copied: false }">
                        <button type="button" class="rounded-[8px] border border-input-border px-2.5 py-1 text-[12.5px] font-semibold text-secondary transition hover:text-ink"
                                x-on:click="navigator.clipboard?.writeText(@js($static['text'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})">
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak style="color:#3E5C3A;">{{ __('Copied ✓') }}</span>
                        </button>
                    </div>
                </div>
                <pre class="whitespace-pre-wrap px-5 pb-4 pt-2 text-[13.5px] leading-relaxed text-body" style="font-family:inherit;">{{ $static['text'] }}</pre>
            </div>
        </details>
    @endforeach
</div>
