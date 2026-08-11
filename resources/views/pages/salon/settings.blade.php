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
    public bool $owner_is_stylist = false;

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
        $this->owner_is_stylist = $this->salon->memberships()
            ->where('salon_role', \App\Enums\SalonRole::Owner->value)
            ->where('active', true)
            ->value('staff_type') === \App\Enums\StaffType::Stylist->value;
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
        if (\App\Support\DemoMode::blocksWrite($this->salon)) {
            return;
        }

        $this->authorize('manage', $this->salon);

        $this->validate(['timezone' => ['required', 'timezone:all']]);

        $action->handle($this->salon, $this->timezone);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('Timezone saved.'));
    }

    public function generateApiToken(GenerateBookingApiToken $action): void
    {
        if ($this->salon->is_demo) {
            Flux::toast(variant: 'danger', text: __('The demo salon cannot hold API tokens — nothing in the demo reaches the outside world.'));

            return;
        }

        $this->authorize('manage', $this->salon);

        $this->apiTokenPlain = $action->handle($this->salon);
        $this->salon->refresh();

        Flux::toast(variant: 'success', text: __('API token generated — copy it now.'));
    }

    public function saveCurrency(UpdateCurrency $action): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon)) {
            return;
        }

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
        if (\App\Support\DemoMode::blocksWrite($this->salon)) {
            return;
        }

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

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Branding changes are disabled in the demo.'))) {
            return;
        }

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

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Theme changes are disabled in the demo.'))) {
            return;
        }

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

        if (\App\Support\DemoMode::blocksWrite($this->salon, __('Branding changes are disabled in the demo.'))) {
            return;
        }

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
    public ?string $ownerTempPassword = null;
    public ?string $ownerTempForName = null;
    public bool $showOwnerTempPassword = false;

    public function saveProfile(UpdateSalonProfile $action, \App\Actions\Salons\ReconcileSalonOwner $reconcile): void
    {
        if (\App\Support\DemoMode::blocksWrite($this->salon)) {
            return;
        }

        $this->authorize('manageProfile', $this->salon);

        $data = $this->validate([...SalonProfile::rules(), 'owner_is_stylist' => ['boolean']]);

        $action->handle($this->salon, $data);
        $this->salon->refresh();

        // Owner details are the source of truth: provision a missing owner,
        // sync details, or transfer on an email change (rules in the action).
        $result = $reconcile->handle(Auth::user(), $this->salon, (bool) $this->owner_is_stylist);

        if ($result?->temporaryPassword !== null) {
            $this->ownerTempPassword = $result->temporaryPassword;
            $this->ownerTempForName = $result->user->name;
            $this->showOwnerTempPassword = true;
        }

        Flux::toast(variant: 'success', text: __('Business profile saved.'));
    }


    /**
     * Per-step status for the guided Integrations flow. Purely derived —
     * 'done' / 'attention' (started or broken, look at it) / 'todo'.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function integrationStepStatuses(): array
    {
        $checks = (array) $this->salon->integration_checks;
        $passed = fn (string $key): bool => ($checks[$key]['state'] ?? null) === \App\Services\Ghl\IntegrationCheckResult::PASSED;

        $credentialsIn = $this->tokenIsSet && $this->ghlLocationId !== '';
        $mapDone = $this->ghlCalendarId !== '' && $this->ghlStylistMap !== [] && ! in_array('', $this->ghlStylistMap, true);
        $mapStarted = $this->ghlCalendarId !== '' || array_filter($this->ghlStylistMap) !== [];

        $states = $this->ghlAvailabilityStates;
        $syncDone = $states->isNotEmpty() && $states->every(fn (StylistProfile $s): bool => $s->ghl_availability_status === 'synced');
        $syncBroken = $states->contains(fn (StylistProfile $s): bool => $s->ghl_availability_status === 'failed')
            || $this->ghlSyncIssues->isNotEmpty();

        return [
            'connect' => $credentialsIn ? 'done' : ($this->ghlStatus === 'incomplete' ? 'attention' : 'todo'),
            'mapping' => $mapDone ? 'done' : ($mapStarted ? 'attention' : 'todo'),
            'token' => $this->salon->api_token_generated_at !== null ? 'done' : 'todo',
            'webhook' => filled($this->ghlWebhookSecret) ? 'done' : 'todo',
            'test' => $syncBroken ? 'attention' : ($syncDone && $passed(\App\Services\Ghl\IntegrationChecks::BOOKING) ? 'done' : 'todo'),
        ];
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
                 tabs: ['general', 'policy', 'branding'@can('manageGhlConnection', $salon), 'integrations', 'voice'@endcan],
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
                        <button type="button" x-on:click="pick('voice')" :aria-current="tab === 'voice' ? 'page' : null"
                                class="bts-nav-item shrink-0 text-left" :class="tab === 'voice' && 'bts-nav-item-active'">{{ __('Voice AI Prompts') }}</button>
                    @endcan
                </nav>
            </div>

            <div class="flex min-w-0 flex-1 flex-col">

        {{-- General: business profile + timezone. --}}
        <section x-show="tab === 'general'" x-cloak class="flex flex-col gap-6">
        @include('partials.settings.general')
        </section>

        {{-- Booking policy. --}}
        <section x-show="tab === 'policy'" x-cloak class="flex flex-col gap-6">
        @include('partials.settings.policy')

        </section>

        {{-- Branding. --}}
        <section x-show="tab === 'branding'" x-cloak class="flex flex-col gap-6">
        @include('partials.settings.branding')

        </section>


        {{-- Integrations: GoHighLevel connection, mapping, inbound webhook. --}}
        <section x-show="tab === 'integrations'" x-cloak class="flex flex-col gap-6">
        @include('partials.settings.integrations')
        </section>

        {{-- Voice AI Prompts: its own nested component (the Settings
             component stays this size) — same gate as Integrations. --}}
        <section x-show="tab === 'voice'" x-cloak class="flex flex-col gap-6">
        @can('manageGhlConnection', $salon)
            @livewire('pages::salon.voice-ai-prompts', ['salon' => $salon], key('voice-ai-'.$salon->id))
        @endcan
        </section>

            </div>
        </div>
    </div>

    <x-ui.modal wire:model="showOwnerTempPassword" class="max-w-md"
        :heading="$ownerTempForName ? __('Temporary password for :name', ['name' => $ownerTempForName]) : __('Temporary password')">
        @if ($ownerTempPassword)
            <x-temp-password-panel :name="$ownerTempForName" :password="$ownerTempPassword" :show-heading="false" />
        @endif
        <div class="flex justify-end">
            <x-ui.button wire:click="$set('showOwnerTempPassword', false)">{{ __('Done') }}</x-ui.button>
        </div>
    </x-ui.modal>
</div>
