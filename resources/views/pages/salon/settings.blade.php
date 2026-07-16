<?php

use App\Actions\Salons\DisconnectGhl;
use App\Actions\Salons\TestGhlConnection;
use App\Actions\Salons\GenerateBookingApiToken;
use App\Actions\Salons\UpdateBookingPolicy;
use App\Actions\Salons\UpdateBranding;
use App\Actions\Salons\UpdateCurrency;
use App\Actions\Salons\UpdateGhlConnection;
use App\Actions\Salons\UpdateGhlStaffMapping;
use App\Enums\StaffType;
use App\Actions\Salons\UpdateSalonProfile;
use App\Actions\Salons\UpdateTimezone;
use App\Jobs\SyncAvailabilityToGhl;
use App\Jobs\SyncBookingToGhl;
use App\Jobs\SyncGhlCalendarSlotSettings;
use App\Models\Salon;
use App\Models\StylistProfile;
use App\Services\Ghl\GhlApiException;
use App\Services\Ghl\GhlAvailabilityPusher;
use App\Services\Ghl\GhlBookingPusher;
use App\Services\Ghl\GhlClient;
use App\Support\Money;
use App\Support\SalonProfile;
use Flux\Flux;
use Illuminate\Validation\Rule;
use App\Support\WidgetBranding;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Salon settings')] class extends Component {
    use WithFileUploads;

    public Salon $salon;

    #[Validate('boolean')]
    public bool $allow_walkins = true;

    #[Validate('boolean')]
    public bool $allow_same_day = true;

    #[Validate('required|integer|min:1|max:365')]
    public int $max_advance_days = 90;

    #[Validate('required|integer|min:0|max:10080')]
    public int $min_notice_minutes = 0;

    // Booking automation (policy panel). Auto-no-show is opt-in by design.
    #[Validate('boolean')]
    public bool $auto_no_show = false;

    #[Validate('required|integer|min:0|max:1440')]
    public int $auto_no_show_grace_minutes = 15;

    #[Validate('boolean')]
    public bool $auto_complete = true;

    #[Validate(['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'])]
    public string $accent = '';

    // Brand logo upload (settings → Branding).
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    // The salon's IANA timezone (General settings).
    public string $timezone = '';

    // Display currency for service prices (General settings). Display only.
    public string $currency = 'USD';

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

    // Booking API token plaintext — present ONLY right after generation.
    public ?string $apiTokenPlain = null;

    public function mount(Salon $salon): void
    {
        $this->authorize('manage', $salon);
        $this->salon = $salon;

        $this->allow_walkins = $salon->allow_walkins;
        $this->allow_same_day = $salon->allow_same_day;
        $this->max_advance_days = $salon->max_advance_days;
        $this->min_notice_minutes = $salon->min_notice_minutes;
        $this->auto_no_show = $salon->auto_no_show;
        $this->auto_no_show_grace_minutes = $salon->auto_no_show_grace_minutes;
        $this->auto_complete = $salon->auto_complete;

        $this->accent = $salon->accentColor() ?? '';

        $this->timezone = $salon->timezone;
        $this->currency = $salon->currency;
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
    public function testGhlConnection(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        // Runs through the shared checks engine so the inline result panel
        // (partials.integration-check-result) and last-verified stamp persist.
        $check = app(\App\Services\Ghl\IntegrationChecks::class)->run($this->salon, \App\Services\Ghl\IntegrationChecks::CONNECTION);
        $this->refreshGhlState();
        $this->salon->refresh();

        Flux::toast(variant: $check->ok() ? 'success' : 'danger', text: $check->message);
    }

    /**
     * Run one named integration check (partials.integration-check buttons);
     * the outcome persists on the salon and renders inline.
     */
    public function runIntegrationCheck(string $key): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        app(\App\Services\Ghl\IntegrationChecks::class)->run(
            $this->salon,
            $key,
            $key === \App\Services\Ghl\IntegrationChecks::VOICE ? $this->apiTokenPlain : null,
        );

        $this->salon->refresh();
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
     * Bookings whose GHL push failed for good (all retries exhausted, or the
     * appointment vanished from GHL) — owner/admin visibility instead of a
     * silent dead queue job. Salon-scoped by the relation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Booking>
     */
    #[Computed]
    public function ghlSyncIssues()
    {
        return $this->salon->bookings()
            ->where('ghl_sync_status', GhlBookingPusher::STATUS_FAILED)
            ->with(['client:id,name', 'items.service:id,name', 'items.stylist:id,name'])
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }

    /**
     * Re-dispatch the push for one failed booking (the job pushes the
     * booking's CURRENT state, so a retry is always safe).
     */
    public function retryGhlSync(int $bookingId): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $booking = $this->salon->bookings()->whereKey($bookingId)->firstOrFail();

        SyncBookingToGhl::queueFor($booking);
        unset($this->ghlSyncIssues);

        Flux::toast(variant: 'success', text: __('Sync queued for :name.', ['name' => $booking->client->name]));
    }

    /**
     * Mapped stylists with their availability-sync state (Phase 6e) —
     * salon-scoped by construction.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StylistProfile>
     */
    #[Computed]
    public function ghlAvailabilityStates()
    {
        return StylistProfile::forSalon($this->salon)
            ->whereNotNull('ghl_user_id')
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (StylistProfile $profile) => mb_strtolower((string) $profile->user?->name))
            ->values();
    }

    /**
     * Manual "sync availability to GoHighLevel": every mapped stylist's
     * weekly hours + time off, plus the master calendar's slot settings.
     * First-time setup and repair both land here.
     */
    public function syncGhlAvailability(): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        if (! ($this->salon->ghlConnection()->first()?->isConnected() ?? false)) {
            Flux::toast(variant: 'danger', text: __('Connect GoHighLevel (and choose a master calendar) first.'));

            return;
        }

        $queued = SyncAvailabilityToGhl::queueForSalon($this->salon);
        SyncGhlCalendarSlotSettings::queueFor($this->salon);

        unset($this->ghlAvailabilityStates);

        Flux::toast(
            variant: $queued > 0 ? 'success' : 'danger',
            text: $queued > 0
                ? __('Queued availability sync for :count stylist(s).', ['count' => $queued])
                : __('No stylists are mapped to GoHighLevel providers yet.'),
        );
    }

    /**
     * Re-push one stylist's availability (retry after a failure).
     */
    public function retryGhlAvailability(int $profileId): void
    {
        $this->authorize('manageGhlConnection', $this->salon);

        $profile = StylistProfile::forSalon($this->salon)->whereKey($profileId)->firstOrFail();

        SyncAvailabilityToGhl::queueFor($profile);
        unset($this->ghlAvailabilityStates);

        Flux::toast(variant: 'success', text: __('Availability sync queued for :name.', ['name' => $profile->user?->name ?? __('stylist')]));
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
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return timezone_identifiers_list();
    }

    /**
     * Change the salon timezone. Booking instants are stored in UTC and do
     * not move; every consumer reads the salon's current timezone live, so
     * only displayed local times and weekly-window interpretation shift.
     */
    public function saveTimezone(UpdateTimezone $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->validate(['timezone' => ['required', 'timezone:all']]);

        $action->handle($this->salon, $this->timezone);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Timezone saved.'));
    }

    public function generateApiToken(GenerateBookingApiToken $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->apiTokenPlain = $action->handle($this->salon);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('API token generated — copy it now.'));
    }

    public function saveCurrency(UpdateCurrency $action): void
    {
        $this->authorize('manage', $this->salon);

        $data = $this->validate([
            'currency' => ['required', 'string', Rule::in(Money::codes())],
        ]);

        $action->handle($this->salon, $data['currency']);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Currency saved.'));
    }

    public function savePolicy(UpdateBookingPolicy $action): void
    {
        $this->authorize('manage', $this->salon);

        $data = $this->validate([
            'allow_walkins' => ['boolean'],
            'allow_same_day' => ['boolean'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:365'],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'auto_no_show' => ['boolean'],
            'auto_no_show_grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'auto_complete' => ['boolean'],
        ]);

        $action->handle($this->salon, [
            'allow_walkins' => $data['allow_walkins'],
            'allow_same_day' => $data['allow_same_day'],
            'max_advance_days' => $data['max_advance_days'],
            'min_notice_minutes' => $data['min_notice_minutes'],
            'auto_no_show' => $data['auto_no_show'],
            'auto_no_show_grace_minutes' => $data['auto_no_show_grace_minutes'],
            'auto_complete' => $data['auto_complete'],
        ]);

        Flux::toast(variant: 'success', text: __('Booking policy saved.'));
    }

    /** Accept 1F6F6B / #1f6f6b / whitespace — canonical #RRGGBB, live. */
    public function updatedAccent(): void
    {
        $this->accent = \App\Support\HexColor::tryNormalize($this->accent);
    }

    public function saveBranding(UpdateBranding $action): void
    {
        $this->authorize('manage', $this->salon);

        $this->accent = \App\Support\HexColor::tryNormalize($this->accent);

        $this->validate([
            'accent' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ]);

        $data = [
            'accent' => $this->accent ?: null,
        ];

        if ($this->logo !== null) {
            $data['logo_path'] = $this->logo->store('branding/'.$this->salon->id, 'public');
        }

        $action->handle($this->salon, $data);
        $this->logo = null;
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Branding saved.'));
    }

    /**
     * Pick this salon's APP theme (Settings → Branding). Only registry
     * themes that are available in the app scope are selectable — the
     * coming-soon cards are locked previews.
     */
    public function saveAppTheme(string $key): void
    {
        $this->authorize('manage', $this->salon);

        if (! \App\Support\ThemeRegistry::selectable($key, \App\Support\ThemeRegistry::SCOPE_APP)) {
            Flux::toast(variant: 'danger', text: __('That theme is not available yet.'));

            return;
        }

        $this->salon->update(['app_theme' => $key]);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('App theme updated.'));
        $this->redirect(route('salon.settings', $this->salon).'#branding', navigate: false);
    }

    /** Remove the uploaded widget logo (the file is deleted too). */
    public function removeLogo(UpdateBranding $action): void
    {
        $this->authorize('manage', $this->salon);

        $action->handle($this->salon, ['remove_logo' => true]);
        $this->logo = null;
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Logo removed.'));
    }

    /**
     * AA-style contrast guidance for the picked brand colours. Body text on
     * the branded surface is always DERIVED to the readable side (light or
     * dark family, by WCAG contrast) — the two pairings a salon can still
     * break are text ON the accent, and the accent against the background.
     */
    #[Computed]
    public function brandingContrastWarning(): ?string
    {
        $accent = $this->accent ?: '#824C71';
        $hex = fn (string $value): bool => preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;

        if ($hex($accent)
            && WidgetBranding::contrast($accent, '#FFFFFF') < 4.5
            && WidgetBranding::contrast($accent, '#1C1B1A') < 4.5) {
            return __('The accent is a mid tone — neither white nor dark text reads on it at 4.5:1. Consider a lighter or deeper shade.');
        }

        return null;
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
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-7 px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
        <x-ui.page-header :overline="$salon->name" :title="__('Salon settings')" />

        {{-- Category navigation + one panel per category. Panels are Alpine
             show/hide (all content stays in the DOM), so every wire binding
             and save method behaves exactly as on the old single page. The
             hash is WHITELISTED against the tabs this user can actually see:
             an unknown or unauthorized #fragment falls back to General
             instead of matching no panel (blank page); back/forward work via
             the hashchange listener. --}}
        <div x-data="{
                 tabs: ['general', 'policy', 'branding'@can('manageGhlConnection', $salon), 'integrations'@endcan],
                 tab: 'general',
                 resolve(hash) { return this.tabs.includes(hash) ? hash : 'general' },
                 pick(name) { this.tab = name; window.location.hash = name },
                 init() { this.tab = this.resolve(window.location.hash.slice(1)) },
             }"
             @hashchange.window="tab = resolve(window.location.hash.slice(1))"
             class="flex items-start gap-8 max-md:flex-col">
            <div class="w-full md:w-[210px] md:shrink-0">
                <nav class="flex gap-1 overflow-x-auto md:flex-col" aria-label="{{ __('Salon settings') }}">
                    <button type="button" x-on:click="pick('general')" :aria-current="tab === 'general' ? 'page' : null"
                            class="bts-nav-item shrink-0 text-left" :class="tab === 'general' && 'bts-nav-item-active'">{{ __('General') }}</button>
                    <button type="button" x-on:click="pick('policy')" :aria-current="tab === 'policy' ? 'page' : null"
                            class="bts-nav-item shrink-0 text-left" :class="tab === 'policy' && 'bts-nav-item-active'">{{ __('Booking policy') }}</button>
                    <button type="button" x-on:click="pick('branding')" :aria-current="tab === 'branding' ? 'page' : null"
                            class="bts-nav-item shrink-0 text-left" :class="tab === 'branding' && 'bts-nav-item-active'">{{ __('Branding') }}</button>
                    @can('manageGhlConnection', $salon)
                        <button type="button" x-on:click="pick('integrations')" :aria-current="tab === 'integrations' ? 'page' : null"
                                class="bts-nav-item shrink-0 text-left" :class="tab === 'integrations' && 'bts-nav-item-active'">{{ __('Integrations') }}</button>
                    @endcan
                </nav>
            </div>

            <div class="flex min-w-0 flex-1 flex-col">

        {{-- General: business profile + timezone. --}}
        <section x-show="tab === 'general'" x-cloak class="flex flex-col gap-6">
        @if ($salon->onboarded_at === null)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-[18px] border border-border bg-accent-soft px-4 py-3">
                <p class="text-[14px] text-accent-ink">{{ __('This salon is not live yet — the setup wizard walks through every remaining step.') }}</p>
                <a href="{{ route('salon.onboarding', $salon) }}" wire:navigate class="bts-btn bts-btn-secondary bts-btn-sm shrink-0">{{ __('Open setup') }}</a>
            </div>
        @endif
        @can('manageProfile', $salon)
            <x-ui.card class="flex flex-col gap-5">
                <h2 class="bts-card-title">{{ __('Business profile') }}</h2>
                <form wire:submit="saveProfile" class="flex flex-col gap-5" novalidate>
                    @include('partials.salon-profile-fields')
                    <div><x-ui.button type="submit">{{ __('Save business profile') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Timezone') }}</h2>
            <form wire:submit="saveTimezone" class="flex flex-col gap-4" novalidate>
                <flux:select wire:model="timezone" :label="__('Salon timezone')">
                    @foreach ($this->timezones as $tz)
                        <flux:select.option value="{{ $tz }}">{{ $tz }}</flux:select.option>
                    @endforeach
                </flux:select>
                <p class="text-[13px] text-faint">
                    {{ __('Changing the timezone changes how availability and bookings are shown. Existing bookings keep their exact moment in time — only the displayed local time follows the new timezone.') }}
                </p>
                <div><x-ui.button type="submit">{{ __('Save timezone') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card class="flex flex-col gap-4">
            <h2 class="bts-card-title">{{ __('Currency') }}</h2>
            <form wire:submit="saveCurrency" class="flex flex-col gap-4" novalidate>
                <div class="max-w-56">
                    <flux:select wire:model="currency" :label="__('Display currency')">
                        @foreach (\App\Support\Money::codes() as $code)
                            <flux:select.option value="{{ $code }}">{{ $code }} ({{ trim(\App\Support\Money::symbol($code)) }})</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <p class="text-[13px] text-faint">
                    {{ __('Used to display service prices. Prices are informational only — the app never takes payments.') }}
                </p>
                <div><x-ui.button type="submit">{{ __('Save currency') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        </section>

        {{-- Booking policy. --}}
        <section x-show="tab === 'policy'" x-cloak class="flex flex-col gap-6">
        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Booking policy') }}</h2>
            <form wire:submit="savePolicy" class="flex flex-col gap-5" novalidate>
                <div class="flex flex-col gap-3">
                    <flux:checkbox wire:model="allow_walkins" :label="__('Allow walk-ins')" />
                    <flux:checkbox wire:model="allow_same_day" :label="__('Allow same-day booking')" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input type="number" wire:model="max_advance_days" :label="__('Max advance (days)')" min="1" max="365" />
                    <flux:input type="number" wire:model="min_notice_minutes" :label="__('Min notice (minutes)')" min="0" max="10080" />
                </div>

                {{-- Booking automation: what the scheduler does to elapsed bookings. --}}
                <div class="flex flex-col gap-3 border-t border-row pt-5">
                    <h3 class="text-[13px] font-semibold uppercase tracking-wide text-secondary">{{ __('Booking automation') }}</h3>

                    <div class="flex flex-col gap-1.5">
                        <flux:checkbox wire:model.live="auto_no_show" :label="__('Auto-mark no-shows')" />
                        <p class="text-[12.5px] leading-relaxed text-faint">{{ __('When on, appointments that are still "Booked" after they end are automatically marked as no-shows (and synced to GoHighLevel). Leave off if your front desk doesn\'t always check clients in — staff can mark no-shows manually either way.') }}</p>
                    </div>

                    @if ($auto_no_show)
                        <div class="flex flex-col gap-1.5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <flux:input type="number" wire:model="auto_no_show_grace_minutes" :label="__('No-show grace period (minutes)')" min="0" max="1440" />
                            </div>
                            <p class="text-[12.5px] leading-relaxed text-faint">{{ __('How long after the end time to wait before auto-marking — covers a busy front desk checking someone in late.') }}</p>
                        </div>
                    @endif

                    <div class="flex flex-col gap-1.5">
                        <flux:checkbox wire:model="auto_complete" :label="__('Auto-complete checked-in appointments')" />
                        <p class="text-[12.5px] leading-relaxed text-faint">{{ __('When on, checked-in appointments are marked completed once their end time passes.') }}</p>
                    </div>
                </div>

                <div><x-ui.button type="submit">{{ __('Save policy') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        </section>

        {{-- Branding. --}}
        <section x-show="tab === 'branding'" x-cloak class="flex flex-col gap-6">
        <x-ui.card class="flex flex-col gap-5">
            <h2 class="bts-card-title">{{ __('Branding') }}</h2>
            <form wire:submit="saveBranding" class="flex flex-col gap-5" novalidate>
                {{-- Accent: a colour-wheel swatch + hex, synced both ways.
                     The swatch is the styled trigger; the picker itself is
                     the OS colour wheel. --}}
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
                    <p class="mt-2 text-[12.5px] text-faint">{{ __('Your brand color — buttons, highlights, and selected states across the app and your booking widgets, on top of whichever theme is active.') }}</p>
                </div>

                @if ($this->brandingContrastWarning)
                    <p class="rounded-[10px] px-3 py-2 text-[13.5px]" style="background:#FBEFD6;color:#8A5A1E">{{ $this->brandingContrastWarning }}</p>
                @endif

                {{-- Logo: upload with preview; used on the booking widget. --}}
                <div class="flex flex-col gap-2">
                    <div class="bts-field-label">{{ __('Logo') }}</div>
                    @php($brandingTheme = \App\Support\WidgetBranding::for($salon))
                    @if ($logo && $logo->isPreviewable())
                        <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('Logo preview') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                        <p class="text-[12.5px] text-faint">{{ __('Preview — save to apply.') }}</p>
                    @elseif ($brandingTheme['logo_url'])
                        <div class="flex items-center gap-3">
                            <img src="{{ $brandingTheme['logo_url'] }}" alt="{{ __('Current logo') }}" class="max-h-14 w-auto max-w-[220px] rounded-[8px] border border-border object-contain p-1" />
                            {{-- Themed confirm (replaces wire:confirm). --}}
                            <button type="button"
                                    x-on:click="$store.confirm.ask({
                                        title: {{ Js::from(__('Remove logo')) }},
                                        message: {{ Js::from(__('Remove the logo? The widget shows the salon name alone until a new one is uploaded.')) }},
                                        confirmLabel: {{ Js::from(__('Remove')) }},
                                        danger: true,
                                    }, () => $wire.removeLogo())"
                                    class="text-[13px] font-medium text-secondary transition hover:text-danger">{{ __('Remove') }}</button>
                        </div>
                    @endif
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"
                           class="text-[14px] file:mr-3 file:rounded-[9px] file:border file:border-input-border file:bg-field file:px-3 file:py-1.5 file:text-[13px] file:font-semibold file:text-body" />
                    <p class="text-[12.5px] text-faint">{{ __('PNG, JPG, WebP or SVG, up to 1 MB. Shown at the top of your booking widget.') }}</p>
                    @error('logo') <p class="text-[13px] text-danger">{{ $message }}</p> @enderror
                    <div wire:loading wire:target="logo" class="text-[12.5px] text-faint">{{ __('Uploading…') }}</div>
                </div>

                <div><x-ui.button type="submit" loading="saveBranding">{{ __('Save branding') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        {{-- App theme: which design language this salon's app renders in.
             Live themes are selectable cards; coming-soon ones are locked
             previews. Booking-widget theming lives per widget, in Widgets. --}}
        <x-ui.card class="flex flex-col gap-5">
            <div>
                <h2 class="bts-card-title">{{ __('App theme') }}</h2>
                <p class="mt-1 text-[14px] text-secondary">{{ __('The design language your salon app renders in. Your booking widgets have their own theme, set per widget in Widgets.') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach (\App\Support\ThemeRegistry::picker(\App\Support\ThemeRegistry::SCOPE_APP) as $themeKey => $theme)
                    @if ($theme['status'] === 'available')
                        <button type="button" wire:click="saveAppTheme('{{ $themeKey }}')"
                                aria-pressed="{{ $salon->app_theme === $themeKey ? 'true' : 'false' }}"
                                class="flex flex-col gap-2 rounded-[14px] border p-4 text-left transition {{ $salon->app_theme === $themeKey ? 'border-accent bg-accent-tint' : 'border-input-border bg-field hover:border-faint' }}">
                            <span class="flex items-center gap-1.5" aria-hidden="true">
                                @foreach ($theme['swatches'] as $swatch)
                                    <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                @endforeach
                            </span>
                            <span class="text-[15px] font-semibold text-ink">{{ $theme['name'] }}
                                @if ($salon->app_theme === $themeKey)
                                    <span class="ms-1 text-[12px] font-semibold text-accent-ink">{{ __('· Active') }}</span>
                                @endif
                            </span>
                            <span class="text-[13px] text-secondary">{{ $theme['description'] }}</span>
                        </button>
                    @else
                        <div class="relative overflow-hidden rounded-[14px] border border-border bg-field p-4" aria-disabled="true">
                            <div class="blur-[2px] opacity-60" aria-hidden="true">
                                <span class="flex items-center gap-1.5">
                                    @foreach ($theme['swatches'] as $swatch)
                                        <span class="size-5 rounded-full border border-border" style="background-color: {{ $swatch }}"></span>
                                    @endforeach
                                </span>
                                <p class="mt-2 text-[15px] font-semibold text-ink">{{ $theme['name'] }}</p>
                                <p class="text-[13px] text-secondary">{{ $theme['description'] }}</p>
                            </div>
                            <span class="absolute right-3 top-3 rounded-full bg-muted px-2.5 py-1 text-[11.5px] font-semibold uppercase tracking-wide text-secondary">{{ __('Coming soon') }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </x-ui.card>

        </section>


        {{-- Integrations: GoHighLevel connection, mapping, inbound webhook. --}}
        <section x-show="tab === 'integrations'" x-cloak class="flex flex-col gap-6">
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

                    <form wire:submit="saveGhlMapping" class="flex flex-col gap-5" novalidate>
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

                    {{-- Live verification: the calendar still exists in GHL and
                         every stylist maps to a real team member on it. --}}
                    <div class="border-t border-row pt-4">
                        @include('partials.integration-check', ['check' => 'mapping', 'label' => __('Verify mapping')])
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Client contact sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Bookings keep GoHighLevel contacts in step with the app\'s clients. This verifies the token can actually read and write contacts (the contacts.readonly and contacts.write scopes) before a booking needs to.') }}
                    </p>
                    @include('partials.integration-check', ['check' => 'contacts', 'label' => __('Verify contact sync')])
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

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Availability sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Mirrors each mapped stylist\'s weekly hours and time off into GoHighLevel, so the voice AI, chat widget and booking pages only offer times the app would allow. The app remains the source of truth.') }}
                    </p>
                    @if ($this->ghlAvailabilityStates->isEmpty())
                        <p class="text-[14px] text-faint">{{ __('Map stylists to GoHighLevel providers above, then sync.') }}</p>
                    @else
                        <div class="divide-y divide-row rounded-[18px] border border-border">
                            @foreach ($this->ghlAvailabilityStates as $state)
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <x-ui.avatar :name="$state->user?->name ?? ''" :seed="$state->user_id" size="sm" />
                                        <div class="flex flex-col">
                                            <span class="text-[14.5px] font-medium text-ink">{{ $state->user?->name }}</span>
                                            @if ($state->ghl_availability_status === 'failed')
                                                <span class="text-[12.5px]" style="color:#A23A3A;">{{ $state->ghl_availability_error }}</span>
                                            @elseif ($state->ghl_availability_status === 'skipped')
                                                <span class="text-[12.5px] text-faint">{{ $state->ghl_availability_error }}</span>
                                            @elseif ($state->ghl_availability_synced_at)
                                                <span class="text-[12.5px] text-faint">{{ __('Synced') }} {{ $state->ghl_availability_synced_at->diffForHumans() }}</span>
                                            @else
                                                <span class="text-[12.5px] text-faint">{{ __('Never synced') }}</span>
                                            @endif
                                        </div>
                                        @if ($state->ghl_availability_status === 'failed')
                                            <span class="bts-pill" style="background-color:#F8E3E3;color:#A23A3A;">{{ __('Failed') }}</span>
                                        @elseif ($state->ghl_availability_status === 'pending')
                                            <span class="bts-pill" style="background-color:#FBEFD6;color:#8A5A1E;">{{ __('Pending') }}</span>
                                        @elseif ($state->ghl_availability_status === 'synced')
                                            <span class="bts-pill" style="background-color:#E7EFE4;color:#3E5C3A;">{{ __('Synced') }}</span>
                                        @endif
                                    </div>
                                    <x-ui.button type="button" variant="secondary" wire:click="retryGhlAvailability({{ $state->id }})">
                                        {{ $state->ghl_availability_status === 'failed' ? __('Retry sync') : __('Sync') }}
                                    </x-ui.button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div>
                        <x-ui.button type="button" wire:click="syncGhlAvailability">
                            {{ __('Sync availability to GoHighLevel') }}
                        </x-ui.button>
                    </div>

                    {{-- Read-back verification: each mapped stylist's schedule
                         actually exists in GHL, not just our last push status. --}}
                    <div class="border-t border-row pt-4">
                        @include('partials.integration-check', ['check' => 'availability', 'label' => __('Verify in GoHighLevel')])
                    </div>
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Outbound booking sync') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Proves the app can write appointments to the master calendar. The round trip creates ONE clearly-titled test appointment through the same push path real bookings use, reads it back from GoHighLevel, and deletes it — no real client data, nothing left behind.') }}
                    </p>
                    @include('partials.integration-check', ['check' => 'booking', 'label' => __('Run round-trip test')])
                </x-ui.card>

                <x-ui.card class="flex flex-col gap-4">
                    <h2 class="bts-card-title">{{ __('Sync issues') }}</h2>
                    <p class="text-[14px] text-secondary">
                        {{ __('Bookings that could not be mirrored to GoHighLevel. Retry re-sends the booking\'s current state.') }}
                    </p>
                    @if ($this->ghlSyncIssues->isEmpty())
                        <p class="text-[14px] text-faint">{{ __('No sync issues — everything is mirrored.') }}</p>
                    @else
                        <div class="divide-y divide-row rounded-[18px] border border-border">
                            @foreach ($this->ghlSyncIssues as $issue)
                                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <div class="flex min-w-0 flex-col">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-[14.5px] font-medium text-ink">{{ $issue->client->name }}</span>
                                            <span class="text-[13px] text-secondary">
                                                {{ $issue->items->min('starts_at')?->setTimezone($salon->timezone)->format('D, M j · g:i A') }}
                                                @if ($issue->items->isNotEmpty())
                                                    · {{ $issue->items->first()->service->name }} · {{ $issue->items->first()->stylist->name }}
                                                @endif
                                            </span>
                                        </div>
                                        <span class="text-[12.5px]" style="color:#A23A3A;">{{ $issue->ghl_sync_error }}</span>
                                        @if ($issue->ghl_last_attempt_at)
                                            <span class="text-[12px] text-faint">{{ __('Last attempt') }} {{ $issue->ghl_last_attempt_at->diffForHumans() }}</span>
                                        @endif
                                    </div>
                                    <x-ui.button type="button" variant="secondary" wire:click="retryGhlSync({{ $issue->id }})">
                                        {{ __('Retry sync') }}
                                    </x-ui.button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif
        @endcan

        {{-- Voice-AI Booking API: the per-salon bearer token GHL Custom
             Actions authenticate with. Hash-only storage; shown once. --}}
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
                POST {{ rtrim(config('app.url'), '/') }}/api/v1/booking/availability · POST {{ rtrim(config('app.url'), '/') }}/api/v1/booking/create — Authorization: Bearer &lt;token&gt;
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
        </section>

            </div>
        </div>
    </div>
</div>
