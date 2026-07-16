<?php

use App\Actions\Salons\CreateSalon;
use App\Models\Agency;
use App\Rules\SalonSlug;
use App\Support\HexColor;
use App\Support\SalonProfile;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Stepped new-salon flow: Basics → Booking policy → Branding → GoHighLevel →
 * Review & create. Livewire state persists across steps; Next validates only
 * the current step; Create validates everything and, on failure, jumps back
 * to the step that owns the first invalid field (never a silent failure on a
 * hidden step). save() stays callable directly so programmatic use and tests
 * are unaffected.
 */
new #[Title('New salon')] class extends Component {
    /** Ordered step keys → titles; the review step is always last. */
    public const STEPS = [
        'basics' => 'Basics',
        'policy' => 'Booking policy',
        'branding' => 'Branding',
        'ghl' => 'GoHighLevel',
        'review' => 'Review & create',
    ];

    public string $step = 'basics';

    // Business + contact profile (name = business / trading name).
    public string $name = '';

    public string $legal_business_name = '';

    public string $business_email = '';

    public string $business_phone = '';

    public string $website = '';

    public string $address_line1 = '';

    public string $address_line2 = '';

    public string $city = '';

    public string $region = '';

    public string $postal_code = '';

    public string $country = '';

    public string $contact_name = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $slug = '';

    public string $timezone = 'America/New_York';

    public string $accent = '';

    public bool $allow_walkins = true;

    public bool $allow_same_day = true;

    public int $max_advance_days = 90;

    public int $min_notice_minutes = 0;

    // GoHighLevel connection — all optional at creation; can be filled later.
    public string $ghl_location_id = '';

    public string $ghl_calendar_id = '';

    public string $ghl_token = '';

    /**
     * Users think in subdomains; "slug" is internal naming. Keeps validation
     * messages (format, reserved, taken) speaking their language.
     *
     * @var array<string, string>
     */
    protected array $validationAttributes = ['slug' => 'subdomain'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required business + contact profile (includes the trading name).
            ...SalonProfile::rules(),
            // Format + reserved blocklist via the rule; uniqueness across all
            // salons (slugs are live subdomains, so global) via Rule::unique.
            'slug' => ['required', 'string', new SalonSlug, Rule::unique('salons', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'ghl_location_id' => ['nullable', 'string', 'max:255'],
            'ghl_calendar_id' => ['nullable', 'string', 'max:255'],
            'ghl_token' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Which step owns each field — Next validates a step's own fields, and a
     * failed Create jumps back to the step of the FIRST invalid field.
     *
     * @return array<string, list<string>>
     */
    private function stepFields(): array
    {
        return [
            'basics' => [...array_keys(SalonProfile::rules()), 'slug', 'timezone'],
            'policy' => ['allow_walkins', 'allow_same_day', 'max_advance_days', 'min_notice_minutes'],
            'branding' => ['accent'],
            'ghl' => ['ghl_location_id', 'ghl_calendar_id', 'ghl_token'],
        ];
    }

    public function mount(): void
    {
        $this->authorize('manageSalons', $this->agency());
    }

    /** Suggest a slug from the salon name while the slug is still untouched. */
    public function updatedName(string $value): void
    {
        if ($this->slug === '') {
            $this->slug = \Illuminate\Support\Str::slug($value);
        }
    }

    /** Accept 1F6F6B / #1f6f6b / whitespace — store canonical #RRGGBB live. */
    public function updatedAccent(): void
    {
        $this->accent = HexColor::tryNormalize($this->accent);
    }

    public function agency(): Agency
    {
        $agency = Auth::user()->agency;
        abort_if($agency === null, 403);

        return $agency;
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /** Validate the current step's own fields, then advance. */
    public function next(): void
    {
        $fields = $this->stepFields()[$this->step] ?? [];
        if ($fields !== []) {
            $this->validate(array_intersect_key($this->rules(), array_flip($fields)));
        }

        $keys = array_keys(self::STEPS);
        $index = (int) array_search($this->step, $keys, true);
        $this->step = $keys[min($index + 1, count($keys) - 1)];
    }

    /** Back never validates — nothing is lost, state persists in the component. */
    public function back(): void
    {
        $keys = array_keys(self::STEPS);
        $index = (int) array_search($this->step, $keys, true);
        $this->step = $keys[max($index - 1, 0)];
    }

    /** Jump from the review step's Edit links; earlier steps are always safe. */
    public function goTo(string $step): void
    {
        if (array_key_exists($step, self::STEPS)) {
            $this->step = $step;
        }
    }

    public function save(CreateSalon $action): void
    {
        $this->authorize('manageSalons', $this->agency());

        $this->accent = HexColor::tryNormalize($this->accent);

        try {
            $data = $this->validate();
        } catch (ValidationException $e) {
            // Never fail on a step the user can't see: land on the step that
            // owns the first invalid field, errors intact.
            $this->step = $this->stepForErrors(array_keys($e->errors()));

            throw $e;
        }

        $salon = $action->handle($this->agency(), $data);

        Flux::toast(variant: 'success', text: __('Salon ":name" created.', ['name' => $salon->name]));

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * @param  list<string>  $errorFields
     */
    private function stepForErrors(array $errorFields): string
    {
        foreach ($this->stepFields() as $step => $fields) {
            if (array_intersect($errorFields, $fields) !== []) {
                return $step;
            }
        }

        return $this->step;
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="__('Agency')" :title="__('New salon')">
            <x-slot:subtitle>{{ __('Set up a new sub-account, step by step.') }}</x-slot:subtitle>
        </x-ui.page-header>

        {{-- Progress: numbered steps, the current one announced. --}}
        @php($steps = [
            'basics' => __('Basics'),
            'policy' => __('Booking policy'),
            'branding' => __('Branding'),
            'ghl' => __('GoHighLevel'),
            'review' => __('Review & create'),
        ])
        @php($currentIndex = (int) array_search($step, array_keys($steps), true))
        <ol class="flex flex-wrap items-center gap-x-1 gap-y-2" aria-label="{{ __('Steps') }}">
            @foreach ($steps as $key => $label)
                @php($index = (int) array_search($key, array_keys($steps), true))
                <li class="flex items-center gap-1" @if ($key === $step) aria-current="step" @endif>
                    <span class="inline-flex items-center gap-2 rounded-[99px] px-3 py-1.5 text-[13px] font-semibold {{ $key === $step ? 'bg-accent-tint text-accent-ink' : ($index < $currentIndex ? 'text-body' : 'text-faint') }}">
                        <span class="inline-flex size-5 items-center justify-center rounded-full border text-[11.5px] {{ $key === $step ? 'border-accent text-accent-ink' : ($index < $currentIndex ? 'border-[#D8E4D5] bg-[#E7EFE4] text-[#3E5C3A]' : 'border-input-border') }}">
                            @if ($index < $currentIndex)
                                <flux:icon.check variant="micro" class="size-3.5" />
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        {{ $label }}
                    </span>
                    @unless ($loop->last)
                        <span class="text-faint" aria-hidden="true">·</span>
                    @endunless
                </li>
            @endforeach
        </ol>

        <x-ui.card>
        <form wire:submit="{{ $step === 'review' ? 'save' : 'next' }}" novalidate class="flex flex-col gap-6">
            @if ($step === 'basics')
                @include('partials.salon-profile-fields')

                <flux:separator :text="__('Subdomain and timezone')" />

                <div class="flex flex-col gap-2">
                    <flux:input wire:model.live="slug" :label="__('Subdomain')"
                        :description="__('This becomes the salon\'s web address. Lowercase letters, numbers, and hyphens only.')"
                        placeholder="demo" required />
                    <p class="text-[13px] text-faint">
                        {{ __('Web address:') }}
                        <span class="font-mono text-body">{{ ($slug !== '' ? $slug : __('yoursalon')).'.'.config('app.domain') }}</span>
                    </p>
                </div>

                <flux:select wire:model="timezone" :label="__('Timezone')">
                    @foreach ($this->timezones as $tz)
                        <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                    @endforeach
                </flux:select>
            @elseif ($step === 'policy')
                <div class="flex flex-col gap-3">
                    <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                    <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                    <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
                </div>

                <p class="text-[13px] text-faint">{{ __('Everything here can be changed later in the salon\'s settings.') }}</p>
            @elseif ($step === 'branding')
                {{-- Accent: colour-wheel swatch + hex, the same control as the
                     Branding tab. Hex accepts 1F6F6B or #1f6f6b — normalised
                     to #RRGGBB automatically. --}}
                <div>
                    <div class="bts-field-label mb-2">{{ __('Accent color') }}</div>
                    <div class="flex items-center gap-3" x-data>
                        <label class="relative inline-flex size-11 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border-2 border-input-border shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] transition hover:border-faint focus-within:outline focus-within:outline-2 focus-within:outline-[var(--focus-ring)] focus-within:outline-offset-2"
                               style="background-color: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#824C71' }};">
                            <input type="color" wire:model.live="accent"
                                   value="{{ preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#824C71' }}"
                                   aria-label="{{ __('Pick the accent color') }}"
                                   class="absolute inset-0 size-full cursor-pointer opacity-0">
                        </label>
                        <div class="w-40">
                            <flux:input wire:model.live.debounce.400ms="accent" placeholder="#824C71" aria-label="{{ __('Accent hex') }}" />
                        </div>
                    </div>
                    <p class="mt-2 text-[12.5px] text-faint">{{ __('Optional — the salon\'s brand color across the app and its booking widgets. Leave blank for the default.') }}</p>
                </div>
            @elseif ($step === 'ghl')
                <p class="-mt-1 text-[14px] text-secondary">{{ __('Optional — leave blank and connect this salon later from its settings. The token is stored encrypted.') }}</p>

                {{-- Scopes FIRST: grant these when creating the Private
                     Integration in GHL, before there is a token to paste. --}}
                @include('partials.ghl-scopes', ['open' => true])

                <flux:input wire:model="ghl_location_id" :label="__('Location ID')" :description="__('The GoHighLevel sub-account / location ID.')" placeholder="e.g. aBcD1234" />
                <flux:input wire:model="ghl_calendar_id" :label="__('Calendar ID')" :description="__('The salon\'s master GoHighLevel calendar ID.')" placeholder="e.g. cal_aBcD1234" />
                <flux:input type="password" wire:model="ghl_token" :label="__('Private integration token')" :description="__('Stored encrypted at rest. Write-only — never shown back.')" autocomplete="off" />
            @else
                {{-- Review: everything entered, each group with its Edit hop. --}}
                @php($review = [
                    'basics' => [
                        __('Salon name') => $name,
                        __('Subdomain') => $slug !== '' ? $slug.'.'.config('app.domain') : '',
                        __('Timezone') => $timezone,
                        __('Business email') => $business_email,
                        __('Business phone') => $business_phone,
                        __('Address') => trim($address_line1.' '.$city),
                        __('Contact') => trim($contact_name.' '.$contact_email),
                    ],
                    'policy' => [
                        __('Walk-ins') => $allow_walkins ? __('Allowed') : __('Not allowed'),
                        __('Same-day booking') => $allow_same_day ? __('Allowed') : __('Not allowed'),
                        __('Max advance') => trans_choice(':count day|:count days', $max_advance_days, ['count' => $max_advance_days]),
                        __('Min notice') => trans_choice(':count minute|:count minutes', $min_notice_minutes, ['count' => $min_notice_minutes]),
                    ],
                    'branding' => [
                        __('Accent color') => $accent !== '' ? $accent : __('Default'),
                    ],
                    'ghl' => [
                        __('Location ID') => $ghl_location_id !== '' ? $ghl_location_id : __('Not connected yet'),
                        __('Calendar ID') => $ghl_calendar_id,
                        __('Token') => $ghl_token !== '' ? __('Provided (stored encrypted)') : '',
                    ],
                ])

                <div class="flex flex-col gap-5">
                    @foreach ($review as $reviewStep => $rows)
                        <section class="rounded-[14px] border border-input-border">
                            <div class="flex items-center justify-between gap-3 border-b border-row px-4 py-2.5">
                                <h2 class="text-[13.5px] font-semibold text-ink">{{ $steps[$reviewStep] }}</h2>
                                <button type="button" wire:click="goTo('{{ $reviewStep }}')" class="text-[13px] font-semibold text-accent transition hover:text-accent-hover">{{ __('Edit') }}</button>
                            </div>
                            <dl class="flex flex-col divide-y divide-row px-4">
                                @foreach ($rows as $label => $value)
                                    @continue($value === '')
                                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 py-2.5 text-[13.5px]">
                                        <dt class="text-secondary">{{ $label }}</dt>
                                        <dd class="text-right font-medium text-ink">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>
                    @endforeach
                </div>
            @endif

            <div class="flex items-center gap-3">
                @if ($step !== 'basics')
                    <x-ui.button type="button" variant="secondary" wire:click="back">{{ __('Back') }}</x-ui.button>
                @endif
                <div class="flex-1"></div>
                <x-ui.button variant="secondary" :href="route('dashboard')" wire:navigate>{{ __('Cancel') }}</x-ui.button>
                @if ($step === 'review')
                    <x-ui.button type="submit" loading="save">{{ __('Create salon') }}</x-ui.button>
                @else
                    <x-ui.button type="submit">{{ __('Next') }}</x-ui.button>
                @endif
            </div>
        </form>
        </x-ui.card>
    </div>
</div>
