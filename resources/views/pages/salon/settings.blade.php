<?php

use App\Actions\Salons\DisconnectGhl;
use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\UpdateBookingPolicy;
use App\Actions\Salons\UpdateBranding;
use App\Actions\Salons\UpdateFeatureFlags;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Salons\UpdateGhlStaffMapping;
use App\Enums\StaffType;
use App\Actions\Salons\UpdateSalonProfile;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlClient;
use App\Support\SalonProfile;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Salon settings')] class extends Component {
    public Salon $salon;

    #[Validate('boolean')]
    public bool $allow_walkins = true;

    #[Validate('boolean')]
    public bool $allow_same_day = true;

    #[Validate('required|integer|min:1|max:365')]
    public int $max_advance_days = 90;

    #[Validate('required|integer|min:0|max:10080')]
    public int $min_notice_minutes = 0;

    #[Validate('nullable|regex:/^#[0-9a-fA-F]{6}$/')]
    public string $accent = '';

    /** @var array<string, bool> */
    public array $flags = [];

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

    // GoHighLevel connection. The token is write-only: never seeded here, so the
    // stored secret is never rendered back to the page.
    public string $ghlLocationId = '';

    public string $ghlCalendarId = '';

    public string $ghlToken = '';

    public string $ghlStatus = 'not_connected';

    public bool $tokenIsSet = false;

    public ?string $ghlLastVerified = null;

    /** @var list<array{id: string, name: string, teamMemberIds: list<string>}> */
    public array $ghlCalendars = [];

    /** @var list<array{id: string, name: string, email: string}> */
    public array $ghlUsers = [];

    public bool $ghlDirectoryLoaded = false;

    /** @var array<int, string> stylist user id => GHL calendar-provider id ('' = unmapped) */
    public array $ghlStylistMap = [];

    /** @var array<int, string> non-stylist user id => GHL location-user id ('' = unmapped) */
    public array $ghlStaffMap = [];

    /** @var list<int> staff user ids pre-selected by email match (until saved) */
    public array $ghlAutoMatched = [];

    // The inbound-webhook shared secret (owner/admin-only screen; the GHL
    // workflow sends it back in the X-Webhook-Secret header).
    public ?string $ghlWebhookSecret = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;

        $this->allow_walkins = $salon->allow_walkins;
        $this->allow_same_day = $salon->allow_same_day;
        $this->max_advance_days = $salon->max_advance_days;
        $this->min_notice_minutes = $salon->min_notice_minutes;

        $this->accent = $salon->accentColor() ?? '';

        foreach (array_keys($this->catalog()) as $key) {
            $this->flags[$key] = $salon->hasFeature($key);
        }

        $this->loadProfile();
        $this->refreshGhlState();
    }

    /**
     * Load the business + contact profile from the salon into the form props.
     */
    private function loadProfile(): void
    {
        $this->name = $this->salon->name;
        $this->legal_business_name = $this->salon->legal_business_name;
        $this->business_email = $this->salon->business_email;
        $this->business_phone = $this->salon->business_phone;
        $this->website = $this->salon->website ?? '';
        $this->address_line1 = $this->salon->address_line1;
        $this->address_line2 = $this->salon->address_line2 ?? '';
        $this->city = $this->salon->city;
        $this->region = $this->salon->region;
        $this->postal_code = $this->salon->postal_code;
        $this->country = $this->salon->country;
        $this->contact_name = $this->salon->contact_name;
        $this->contact_email = $this->salon->contact_email;
        $this->contact_phone = $this->salon->contact_phone;
    }

    /**
     * Load the non-secret GHL connection state (location/calendar/status) for
     * display. Never loads the token into a property.
     */
    private function refreshGhlState(): void
    {
        $connection = $this->salon->ghlConnection()->first();

        $this->ghlLocationId = $connection?->location_id ?? '';
        $this->ghlCalendarId = $connection?->calendar_id ?? '';
        $this->tokenIsSet = (bool) $connection?->hasToken();
        $this->ghlStatus = $connection?->status() ?? 'not_connected';
        $this->ghlLastVerified = $connection?->last_verified_at?->diffForHumans();
        $this->ghlWebhookSecret = $connection?->webhook_secret;

        // Both mapping tiers, one entry per person so the unmapped show up
        // (as '') rather than disappearing. Stylists carry the calendar-
        // provider mapping (stylist_profiles); everyone else the location-
        // user identity link (salon_memberships).
        $stored = StylistProfile::forSalon($this->salon)->pluck('ghl_user_id', 'user_id');

        $this->ghlStylistMap = [];
        foreach ($this->salon->stylistUsers()->orderBy('name')->pluck('users.id') as $stylistId) {
            $this->ghlStylistMap[(int) $stylistId] = (string) ($stored[$stylistId] ?? '');
        }

        $this->ghlStaffMap = [];
        foreach ($this->nonStylistMemberships() as $membership) {
            $this->ghlStaffMap[(int) $membership->user_id] = (string) ($membership->ghl_location_user_id ?? '');
        }
    }

    private function nonStylistMemberships()
    {
        return $this->salon->memberships()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('staff_type')->orWhere('staff_type', '!=', StaffType::Stylist->value))
            ->with('user:id,name,email')
            ->get()
            ->sortBy(fn ($membership) => mb_strtolower($membership->user->name))
            ->values();
    }

    /**
     * Active stylists — the bookable-provider tier (id + name + email).
     */
    #[Computed]
    public function mappableStylists()
    {
        return $this->salon->stylistUsers()->orderBy('name')->get(['users.id', 'name', 'email']);
    }

    /**
     * Active non-stylist staff (front desk, managers, owners, admins) — the
     * identity/attribution tier.
     */
    #[Computed]
    public function mappableStaff()
    {
        return $this->nonStylistMemberships();
    }

    /**
     * Provider options for the STYLIST tier: the master calendar's declared
     * team members, resolved against the location users for names/emails.
     * A member id the users endpoint does not return (e.g. an agency-level
     * user) stays selectable under its raw id. Deliberately NO fallback to
     * all location users — only calendar members are bookable providers; an
     * empty list means stylists must be added to the calendar in GHL.
     *
     * @return list<array{id: string, name: string, email: string}>
     */
    #[Computed]
    public function ghlProviderOptions(): array
    {
        $selected = collect($this->ghlCalendars)->firstWhere('id', $this->ghlCalendarId);
        $users = collect($this->ghlUsers)->keyBy('id');

        $options = [];
        foreach ($selected['teamMemberIds'] ?? [] as $memberId) {
            $user = $users->get($memberId);
            $options[] = [
                'id' => $memberId,
                'name' => $user['name'] ?? '',
                'email' => $user['email'] ?? '',
            ];
        }

        return $options;
    }

    /**
     * Options for the NON-STYLIST tier: every location user, name-sorted.
     *
     * @return list<array{id: string, name: string, email: string}>
     */
    #[Computed]
    public function ghlStaffOptions(): array
    {
        $users = $this->ghlUsers;
        usort($users, fn (array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return $users;
    }

    /**
     * Verify the stored credentials against the GHL API (server-side read
     * call); stamps last-verified on success.
     */
    public function testGhlConnection(TestGhlConnection $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $check = $action->handle($this->salon);
        $this->refreshGhlState();

        Flux::toast(variant: $check->ok ? 'success' : 'danger', text: $check->message);
    }

    public function disconnectGhl(DisconnectGhl $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $action->handle($this->salon);

        $this->ghlCalendars = [];
        $this->ghlUsers = [];
        $this->ghlDirectoryLoaded = false;
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('GoHighLevel disconnected. Stylist mappings were kept.'));
    }

    /**
     * Fetch the location's calendars + users live from GHL to drive the
     * master-calendar picker and the stylist-mapping dropdowns.
     */
    public function loadGhlDirectory(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $connection = $this->salon->ghlConnection()->first();

        try {
            if ($connection === null) {
                throw GhlApiException::notConfigured();
            }

            $client = GhlClient::fromConnection($connection);
            $calendars = $client->calendars();
            $users = $client->users();
        } catch (GhlApiException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        $this->ghlCalendars = array_map(fn ($calendar): array => [
            'id' => $calendar->id,
            'name' => $calendar->name,
            'teamMemberIds' => $calendar->teamMemberIds,
        ], $calendars);

        $this->ghlUsers = array_map(fn ($user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ], $users);

        $this->ghlDirectoryLoaded = true;

        $this->autoMatchByEmail();
    }

    /**
     * Choosing a (different) master calendar changes the provider pool, so
     * re-run the email auto-match for anyone still unmapped.
     */
    public function updatedGhlCalendarId(): void
    {
        if ($this->ghlDirectoryLoaded) {
            unset($this->ghlProviderOptions); // bust the computed cache for the new calendar
            $this->autoMatchByEmail();
        }
    }

    /**
     * Pre-select GHL links by email (case-insensitive, trimmed) for everyone
     * still unmapped — stylists against the master calendar's providers,
     * other staff against all location users. Provisional until saved; every
     * pre-selection can be overridden in its dropdown.
     */
    private function autoMatchByEmail(): void
    {
        $this->ghlAutoMatched = [];

        $index = function (array $options): array {
            $byEmail = [];
            foreach ($options as $option) {
                $email = mb_strtolower(trim($option['email']));
                if ($email !== '' && ! isset($byEmail[$email])) {
                    $byEmail[$email] = $option['id'];
                }
            }

            return $byEmail;
        };

        $providersByEmail = $index($this->ghlProviderOptions);
        foreach ($this->mappableStylists as $stylist) {
            $email = mb_strtolower(trim((string) $stylist->email));
            if (($this->ghlStylistMap[$stylist->id] ?? '') === '' && $email !== '' && isset($providersByEmail[$email])) {
                $this->ghlStylistMap[$stylist->id] = $providersByEmail[$email];
                $this->ghlAutoMatched[] = (int) $stylist->id;
            }
        }

        $usersByEmail = $index($this->ghlStaffOptions);
        foreach ($this->mappableStaff as $membership) {
            $userId = (int) $membership->user_id;
            $email = mb_strtolower(trim((string) $membership->user->email));
            if (($this->ghlStaffMap[$userId] ?? '') === '' && $email !== '' && isset($usersByEmail[$email])) {
                $this->ghlStaffMap[$userId] = $usersByEmail[$email];
                $this->ghlAutoMatched[] = $userId;
            }
        }
    }

    /**
     * Create (or rotate) the shared secret the GHL workflow must send in the
     * X-Webhook-Secret header. Rotating invalidates the previous secret —
     * update the workflow after rotating.
     */
    public function generateGhlWebhookSecret(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $connection = $this->salon->ghlConnection()->first();

        if ($connection === null || ! $connection->hasToken()) {
            Flux::toast(variant: 'danger', text: __('Connect GoHighLevel first.'));

            return;
        }

        $connection->webhook_secret = bin2hex(random_bytes(24));
        $connection->save();
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('Webhook secret generated. Update the GoHighLevel workflow header.'));
    }

    /**
     * Persist the chosen master calendar + both mapping tiers.
     */
    public function saveGhlMapping(UpdateGhlStaffMapping $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $this->validate([
            'ghlCalendarId' => ['nullable', 'string', 'max:255'],
            'ghlStylistMap' => ['array'],
            'ghlStylistMap.*' => ['nullable', 'string', 'max:255'],
            'ghlStaffMap' => ['array'],
            'ghlStaffMap.*' => ['nullable', 'string', 'max:255'],
        ]);

        $action->handle($this->salon, $this->ghlCalendarId, $this->ghlStylistMap, $this->ghlStaffMap);
        $this->ghlAutoMatched = [];
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('Master calendar and staff mapping saved.'));
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function catalog(): array
    {
        /** @var array<string, string> $features */
        $features = config('salon_features', []);

        return $features;
    }

    public function savePolicy(UpdateBookingPolicy $action): void
    {
        $this->authorize('manage', $this->salon);

        $data = $this->validate([
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ]);

        $action->handle($this->salon, [
            'allow_walkins' => $data['allow_walkins'],
            'allow_same_day' => $data['allow_same_day'],
            'max_advance_days' => $data['max_advance_days'],
            'min_notice_minutes' => $data['min_notice_minutes'],
        ]);

        Flux::toast(variant: 'success', text: __('Booking policy saved.'));
    }

    public function saveBranding(UpdateBranding $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->validate([
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $action->handle($this->salon, ['accent' => $this->accent ?: null]);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Branding saved.'));
    }

    /**
     * Save the salon's business + point-of-contact profile. Gated tighter than
     * the rest of settings: salon owner/admin (+ agency owner/admin via before),
     * never salon staff or agency users.
     */
    public function saveProfile(UpdateSalonProfile $action): void
    {
        $this->authorize('manageProfile', $this->salon);

        $data = $this->validate(SalonProfile::rules());

        $action->handle($this->salon, $data);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Business profile saved.'));
    }

    public function saveFlags(UpdateFeatureFlags $action): void
    {
        $this->authorize('manage', $this->salon);

        // Keep only known flags; coerce to bool.
        $clean = [];
        foreach (array_keys($this->catalog()) as $key) {
            $clean[$key] = (bool) ($this->flags[$key] ?? false);
        }

        $action->handle($this->salon, $clean);

        Flux::toast(variant: 'success', text: __('Feature flags saved.'));
    }

    /**
     * Store the salon's GoHighLevel connection. Gated tighter than the rest of
     * settings: salon owner/admin (+ agency owner/admin), never salon staff or
     * agency users — they cannot touch the credentials.
     */
    public function saveGhlConnection(UpdateGhlConnection $action): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $data = $this->validate([
            'ghlLocationId' => ['nullable', 'string', 'max:255'],
            'ghlCalendarId' => ['nullable', 'string', 'max:255'],
            'ghlToken' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->handle($this->salon, [
            'location_id' => $data['ghlLocationId'],
            'calendar_id' => $data['ghlCalendarId'],
            'private_integration_token' => $data['ghlToken'],
        ]);

        $this->ghlToken = '';
        $this->refreshGhlState();

        Flux::toast(variant: 'success', text: __('GoHighLevel connection saved.'));
    }
}; ?>

<div>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-7 px-8 py-7">
        <x-ui.page-header :overline="$salon->name" :title="__('Salon settings')" />

        @can('manageProfile', $salon)
            <x-ui.card class="flex flex-col gap-5">
                <h2 class="bts-card-title">{{ __('Business profile') }}</h2>
                <form wire:submit="saveProfile" class="flex flex-col gap-5">
                    @include('partials.salon-profile-fields')
                    <div><x-ui.button type="submit">{{ __('Save business profile') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Booking policy') }}</h2>
            <form wire:submit="savePolicy" class="flex flex-col gap-5">
                <div class="flex flex-col gap-3">
                    <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                    <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                    <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
                </div>
                <div><x-ui.button type="submit">{{ __('Save policy') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Branding') }}</h2>
            <form wire:submit="saveBranding" class="flex flex-col gap-5">
                {{-- Accent preset selector (violet / sage / terracotta). Picking one
                     fills the hex below; a custom hex is still allowed. --}}
                <div>
                    <div class="bts-field-label mb-2">{{ __('Accent preset') }}</div>
                    <div class="flex flex-wrap gap-3">
                        @foreach (\App\Support\AccentPalette::PRESETS as $presetName => $preset)
                            @php($selected = strcasecmp($accent, $preset['accent']) === 0)
                            <button type="button" wire:click="$set('accent', '{{ $preset['accent'] }}')"
                                    class="flex items-center gap-2 rounded-[11px] border px-3.5 py-2 text-[14px] font-medium capitalize transition {{ $selected ? 'border-accent bg-accent-tint text-accent-ink' : 'border-input-border bg-field text-body hover:border-faint' }}">
                                <span class="size-4 rounded-full" style="background-color: {{ $preset['accent'] }}"></span>{{ $presetName }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <flux:input wire:model="accent" :label="__('Accent color')" :description="__('Hex color, e.g. #6555E4. Sets this salon\'s brand accent.')" placeholder="#6555E4" />
                <div><x-ui.button type="submit">{{ __('Save branding') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Feature flags') }}</h2>
            <form wire:submit="saveFlags" class="flex flex-col gap-5">
                <p class="text-[14px] text-secondary">{{ __('Per-salon toggles. Later phases read these to enable features for this salon.') }}</p>
                <div class="flex flex-col gap-3">
                    @foreach ($this->catalog as $key => $label)
                        <flux:checkbox wire:model="flags.{{ $key }}" :label="__($label)" />
                    @endforeach
                </div>
                <div><x-ui.button type="submit">{{ __('Save flags') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        @can('manageGhlConnection', $salon)
            @include('partials.ghl-connection-card')

            @if ($tokenIsSet)
                <x-ui.card class="flex flex-col gap-5">
                    <div class="flex items-center justify-between gap-4">
                        <h2 class="bts-card-title">{{ __('Master calendar and staff mapping') }}</h2>
                        <x-ui.button type="button" variant="secondary" wire:click="loadGhlDirectory" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="loadGhlDirectory">{{ $ghlDirectoryLoaded ? __('Reload from GoHighLevel') : __('Load from GoHighLevel') }}</span>
                            <span wire:loading wire:target="loadGhlDirectory">{{ __('Loading…') }}</span>
                        </x-ui.button>
                    </div>

                    <p class="text-[14px] text-secondary">
                        {{ __('Pick the salon\'s master GoHighLevel calendar, then link your team. Stylist links route bookings to the right provider; other staff links are identity only.') }}
                    </p>

                    @error('ghl')
                        <p class="text-[13.5px] font-medium text-[#A23A3A]">{{ $message }}</p>
                    @enderror

                    <form wire:submit="saveGhlMapping" class="flex flex-col gap-5">
                        @if ($ghlDirectoryLoaded)
                            <flux:select wire:model.live="ghlCalendarId" :label="__('Master calendar')"
                                :description="__('The team calendar whose members are your stylists.')">
                                <flux:select.option value="">{{ __('Choose a calendar') }}</flux:select.option>
                                @foreach ($ghlCalendars as $calendar)
                                    <flux:select.option value="{{ $calendar['id'] }}">{{ $calendar['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @elseif ($ghlCalendarId !== '')
                            <div class="flex flex-col gap-1">
                                <div class="bts-field-label">{{ __('Master calendar') }}</div>
                                <p class="font-mono text-[13.5px] text-body">{{ $ghlCalendarId }}</p>
                                <p class="text-[13px] text-faint">{{ __('Load from GoHighLevel to pick a different calendar by name.') }}</p>
                            </div>
                        @endif

                        @if ($ghlDirectoryLoaded && $ghlUsers === [])
                            <p class="text-[13.5px] font-medium text-[#A23A3A]">
                                {{ __('No users found in GoHighLevel. Add your team as users on the location (Settings → My Staff), then reload.') }}
                            </p>
                        @endif

                        {{-- Tier 1: stylists → calendar team members (bookable providers). --}}
                        <div class="flex flex-col gap-1">
                            <div class="bts-field-label">{{ __('Stylists — calendar providers') }}</div>
                            <p class="text-[13px] text-secondary">
                                {{ __('Each stylist maps to a team member of the master calendar. This is what routes bookings to the right provider.') }}
                            </p>
                            <div class="flex flex-col divide-y divide-row rounded-[11px] border border-input-border">
                                @forelse ($this->mappableStylists as $stylist)
                                    @php($mapped = ($ghlStylistMap[$stylist->id] ?? '') !== '')
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$stylist->name" :seed="$stylist->id" size="sm" />
                                            <span class="text-[14.5px] font-medium text-ink">{{ $stylist->name }}</span>
                                            @if (in_array($stylist->id, $ghlAutoMatched, true))
                                                <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('Matched by email') }}</span>
                                            @elseif (! $mapped)
                                                <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Unmapped') }}</span>
                                            @endif
                                        </div>
                                        <div class="w-full sm:w-72">
                                            @if ($ghlDirectoryLoaded && $this->ghlProviderOptions !== [])
                                                <flux:select wire:model="ghlStylistMap.{{ $stylist->id }}" aria-label="{{ __('Calendar provider for :name', ['name' => $stylist->name]) }}">
                                                    <flux:select.option value="">{{ __('Not mapped') }}</flux:select.option>
                                                    @foreach ($this->ghlProviderOptions as $provider)
                                                        <flux:select.option value="{{ $provider['id'] }}">{{ $provider['name'] !== '' ? $provider['name'] : $provider['id'] }}{{ $provider['email'] !== '' ? ' — '.$provider['email'] : '' }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            @elseif ($mapped)
                                                <p class="text-right font-mono text-[13px] text-secondary">{{ $ghlStylistMap[$stylist->id] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-4 py-4 text-[14px] text-faint">{{ __('No active stylists yet. Add stylists under Staff first.') }}</p>
                                @endforelse
                            </div>
                            @if ($ghlDirectoryLoaded && $ghlCalendarId !== '' && $this->ghlProviderOptions === [])
                                <p class="text-[13px] font-medium text-[#8A5A1E]">
                                    {{ __('This calendar has no team members yet. In GoHighLevel, add your stylists to the calendar (edit calendar → team members), then reload.') }}
                                </p>
                            @elseif ($ghlDirectoryLoaded && $ghlCalendarId === '')
                                <p class="text-[13px] text-faint">{{ __('Choose a master calendar to see its providers.') }}</p>
                            @endif
                            <p class="text-[13px] text-faint">
                                {{ __('A stylist missing from the dropdown must be added to the master calendar in GoHighLevel before they can receive bookings.') }}
                            </p>
                        </div>

                        {{-- Tier 2: everyone else → location users (identity only). --}}
                        <div class="flex flex-col gap-1">
                            <div class="bts-field-label">{{ __('Other staff — team members') }}</div>
                            <p class="text-[13px] text-secondary">
                                {{ __('Front desk, managers and owners link to a GoHighLevel user for attribution only — this never makes them bookable.') }}
                            </p>
                            <div class="flex flex-col divide-y divide-row rounded-[11px] border border-input-border">
                                @forelse ($this->mappableStaff as $membership)
                                    @php($staff = $membership->user)
                                    @php($mapped = ($ghlStaffMap[$staff->id] ?? '') !== '')
                                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                        <div class="flex items-center gap-3">
                                            <x-ui.avatar :name="$staff->name" :seed="$staff->id" size="sm" />
                                            <div class="flex flex-col">
                                                <span class="text-[14.5px] font-medium text-ink">{{ $staff->name }}</span>
                                                <span class="text-[12.5px] text-faint">{{ $membership->salon_role->label() }}{{ $membership->staff_type ? ' · '.$membership->staff_type->label() : '' }}</span>
                                            </div>
                                            @if (in_array($staff->id, $ghlAutoMatched, true))
                                                <span class="bts-pill" style="background-color:#E3EDF6;color:#356088;">{{ __('Matched by email') }}</span>
                                            @elseif (! $mapped)
                                                <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Unmapped') }}</span>
                                            @endif
                                        </div>
                                        <div class="w-full sm:w-72">
                                            @if ($ghlDirectoryLoaded && $ghlUsers !== [])
                                                <flux:select wire:model="ghlStaffMap.{{ $staff->id }}" aria-label="{{ __('GoHighLevel user for :name', ['name' => $staff->name]) }}">
                                                    <flux:select.option value="">{{ __('Not mapped') }}</flux:select.option>
                                                    @foreach ($this->ghlStaffOptions as $ghlUser)
                                                        <flux:select.option value="{{ $ghlUser['id'] }}">{{ $ghlUser['name'] !== '' ? $ghlUser['name'] : $ghlUser['id'] }}{{ $ghlUser['email'] !== '' ? ' — '.$ghlUser['email'] : '' }}</flux:select.option>
                                                    @endforeach
                                                </flux:select>
                                            @elseif ($mapped)
                                                <p class="text-right font-mono text-[13px] text-secondary">{{ $ghlStaffMap[$staff->id] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                @empty
                                    <p class="px-4 py-4 text-[14px] text-faint">{{ __('No other active staff.') }}</p>
                                @endforelse
                            </div>
                        </div>

                        @unless ($ghlDirectoryLoaded)
                            <p class="text-[13px] text-faint">{{ __('Load from GoHighLevel to link staff by name.') }}</p>
                        @endunless

                        @if ($ghlDirectoryLoaded)
                            <div><x-ui.button type="submit">{{ __('Save mapping') }}</x-ui.button></div>
                        @endif
                    </form>
                </x-ui.card>

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
                            <x-ui.button type="button" variant="secondary" wire:click="generateGhlWebhookSecret"
                                wire:confirm="{{ __('Rotate the webhook secret? The current one stops working immediately.') }}">
                                {{ __('Rotate secret') }}
                            </x-ui.button>
                        @else
                            <x-ui.button type="button" variant="secondary" wire:click="generateGhlWebhookSecret">
                                {{ __('Generate secret') }}
                            </x-ui.button>
                        @endif
                    </div>
                </x-ui.card>
            @endif
        @endcan
    </div>
</div>
