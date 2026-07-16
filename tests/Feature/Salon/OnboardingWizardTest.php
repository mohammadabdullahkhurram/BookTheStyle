<?php

use App\Models\Salon;
use App\Models\SalonGhlConnection;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\StylistProfile;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Onboarding\SalonOnboarding;
use App\Support\BookingApiToken;
use Livewire\Livewire;

/*
| The salon setup wizard: computed step statuses, persisted resume +
| attestations, dynamic copy-paste values, and the go-live gate.
*/

function ghlEvent(Salon $salon): WebhookEvent
{
    return WebhookEvent::create([
        'salon_id' => $salon->id,
        'event_type' => 'AppointmentUpdate',
        'payload' => ['test' => true],
        'payload_hash' => hash('sha256', uniqid('', true)),
        'status' => 'processed',
        'processed_at' => now(),
    ]);
}

function wizardOwner(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->owner()->create();

    return $user;
}

/** A salon with every verifiable step complete (webhook + voice attested separately). */
function completedSalon(): Salon
{
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60);

    SalonGhlConnection::factory()->for($salon)->create([
        'last_verified_at' => now(),
        'webhook_secret' => bin2hex(random_bytes(24)),
    ]);
    StylistProfile::factory()->create([
        'salon_id' => $salon->id,
        'user_id' => $stylist->id,
        'ghl_user_id' => 'ghl_u_1',
        'ghl_availability_status' => 'synced',
    ]);
    BookingApiToken::generate($salon);
    ghlEvent($salon);

    return $salon->refresh();
}

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('renders every step with progress for an owner', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    $component = Livewire::test('pages::salon.onboarding', ['salon' => $salon]);

    foreach (SalonOnboarding::steps() as $meta) {
        $component->assertSee($meta['title']);
    }

    $component->assertSee(__('Mark salon as live'));
});

it('refuses stylists; front desk holds the admin role and may run setup', function () {
    $salon = bookingSalon();

    $this->actingAs(stylistOf($salon));
    Livewire::test('pages::salon.onboarding', ['salon' => $salon])->assertForbidden();

    $this->actingAs(frontDeskOf($salon));
    Livewire::test('pages::salon.onboarding', ['salon' => $salon])->assertOk();
});

it('is tenant-scoped: an owner of one salon cannot onboard another', function () {
    $salonA = bookingSalon();
    $salonB = bookingSalon();

    $this->actingAs(wizardOwner($salonA));
    Livewire::test('pages::salon.onboarding', ['salon' => $salonB])->assertForbidden();
});

// ---------------------------------------------------------------------------
// Computed step statuses
// ---------------------------------------------------------------------------

it('computes step statuses from live data', function () {
    $salon = bookingSalon();
    $service = app(SalonOnboarding::class);

    // Fresh salon: basics done (factory fills them), the rest untouched.
    $statuses = $service->statuses($salon);
    expect($statuses['basics'])->toBe('done');
    expect($statuses['staff'])->toBe('not_started');
    expect($statuses['services'])->toBe('not_started');
    expect($statuses['ghl_connect'])->toBe('not_started');
    expect($statuses['api_token'])->toBe('not_started');

    // Add a stylist with hours + a service → those steps flip to done.
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    serviceFor($salon, $stylist, 60);

    $statuses = $service->statuses($salon->refresh());
    expect($statuses['staff'])->toBe('done');
    expect($statuses['services'])->toBe('done');
    expect($statuses['availability'])->toBe('done');

    // A second stylist without hours drops availability to in progress.
    stylistOf($salon);
    expect($service->status($salon, 'availability'))->toBe('in_progress');
});

it('marks the connection step done only after a verified test', function () {
    $salon = bookingSalon();
    $service = app(SalonOnboarding::class);

    $connection = SalonGhlConnection::factory()->for($salon)->create(['last_verified_at' => null]);
    expect($service->status($salon, 'ghl_connect'))->toBe('in_progress');

    $connection->forceFill(['last_verified_at' => now()])->save();
    expect($service->status($salon, 'ghl_connect'))->toBe('done');
});

it('marks mapping done only when a calendar is chosen and every stylist is mapped', function () {
    $salon = bookingSalon();
    $service = app(SalonOnboarding::class);
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);

    SalonGhlConnection::factory()->for($salon)->create(['last_verified_at' => now()]);
    StylistProfile::factory()->create(['salon_id' => $salon->id, 'user_id' => $anna->id, 'ghl_user_id' => 'ghl_u_1']);

    expect($service->status($salon, 'ghl_mapping'))->toBe('in_progress');
    expect($service->unmappedStylists($salon))->toBe([$ben->name]);

    StylistProfile::factory()->create(['salon_id' => $salon->id, 'user_id' => $ben->id, 'ghl_user_id' => 'ghl_u_2']);
    expect($service->status($salon, 'ghl_mapping'))->toBe('done');
});

it('verifies the webhook step by an observed inbound event', function () {
    $salon = bookingSalon();
    $service = app(SalonOnboarding::class);
    SalonGhlConnection::factory()->for($salon)->create(['webhook_secret' => bin2hex(random_bytes(24))]);

    expect($service->status($salon, 'webhook'))->toBe('in_progress');

    // An event for ANOTHER salon proves nothing.
    ghlEvent(bookingSalon());
    expect($service->status($salon, 'webhook'))->toBe('in_progress');

    ghlEvent($salon);
    expect($service->status($salon, 'webhook'))->toBe('done');
});

it('marks availability sync done only when every mapped stylist is synced', function () {
    $salon = bookingSalon();
    $service = app(SalonOnboarding::class);
    $anna = stylistOf($salon);
    $ben = stylistOf($salon);

    StylistProfile::factory()->create(['salon_id' => $salon->id, 'user_id' => $anna->id, 'ghl_user_id' => 'g1', 'ghl_availability_status' => 'synced']);
    $benProfile = StylistProfile::factory()->create(['salon_id' => $salon->id, 'user_id' => $ben->id, 'ghl_user_id' => 'g2', 'ghl_availability_status' => 'failed']);

    expect($service->status($salon, 'availability_sync'))->toBe('in_progress');

    $benProfile->update(['ghl_availability_status' => 'synced']);
    expect($service->status($salon, 'availability_sync'))->toBe('done');
});

// ---------------------------------------------------------------------------
// Persistence: resume + attestations
// ---------------------------------------------------------------------------

it('persists the current step and resumes there', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'webhook')
        ->assertSet('step', 'webhook');

    expect($salon->refresh()->onboarding['step'])->toBe('webhook');

    // A fresh visit resumes on the remembered step.
    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->assertSet('step', 'webhook');
});

it('opens on the first incomplete step when nothing is remembered', function () {
    $salon = bookingSalon(); // basics done, staff not
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->assertSet('step', 'staff');
});

it('persists attestations for the GHL-only steps and allows undo', function () {
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create(['webhook_secret' => bin2hex(random_bytes(24))]);
    $this->actingAs(wizardOwner($salon));
    $service = app(SalonOnboarding::class);

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('attest', 'webhook');

    expect($service->status($salon->refresh(), 'webhook'))->toBe('done');

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('attest', 'webhook', false);

    expect($service->status($salon->refresh(), 'webhook'))->toBe('in_progress');

    // Attestation is restricted to the GHL-only steps: attesting 'staff'
    // changes nothing (status stays computed — in progress, since the owner
    // is a member but no stylist exists).
    $service->attest($salon, 'staff');
    expect($service->status($salon->refresh(), 'staff'))->toBe('in_progress');
    expect($salon->onboarding['attested'] ?? [])->not->toHaveKey('staff');
});

// ---------------------------------------------------------------------------
// Dynamic values on the GHL screens
// ---------------------------------------------------------------------------

it('shows the required scopes from config on the connect step', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    $component = Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'ghl_connect');

    foreach (array_keys(config('ghl.required_scopes')) as $scope) {
        $component->assertSee($scope);
    }
});

it('shows the webhook URL, header name and secret with copy affordances', function () {
    $salon = bookingSalon();
    $connection = SalonGhlConnection::factory()->for($salon)->create(['webhook_secret' => 'sekrit0123456789']);
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'webhook')
        ->assertSee(route('webhooks.ghl'))
        ->assertSee('X-Webhook-Secret')
        ->assertSee('sekrit0123456789')
        ->assertSee(__('Copy'));
});

it('shows both custom-action endpoint URLs and the GHL parameter list', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'voice_actions')
        ->assertSee(route('api.booking.availability'))
        ->assertSee(route('api.booking.create'))
        ->assertSee('client_name')
        ->assertSee('client_phone')
        ->assertSee('11:00 AM')
        ->assertSee('application/json');
});

it('generates the booking API token and shows the plaintext once', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    $component = Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('goTo', 'api_token')
        ->call('generateApiToken');

    $plain = $component->get('apiTokenPlain');
    expect($plain)->toStartWith('btsk_'.$salon->id.'_');
    expect($salon->refresh()->api_token_hash)->toBe(hash('sha256', $plain));

    // Navigating away clears the plaintext — shown once.
    $component->call('goTo', 'voice_actions')->assertSet('apiTokenPlain', null);
});

it('generates a webhook secret only once connected', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    // No connection yet → refused.
    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('generateWebhookSecret');
    expect($salon->ghlConnection()->first()?->webhook_secret)->toBeNull();

    SalonGhlConnection::factory()->for($salon)->create(['webhook_secret' => null]);

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('generateWebhookSecret');
    expect($salon->ghlConnection()->first()->webhook_secret)->not->toBeNull();
});

it('saves connection details through the existing action without clearing the calendar', function () {
    $salon = bookingSalon();
    SalonGhlConnection::factory()->for($salon)->create(['calendar_id' => 'cal_keep']);
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->set('ghlLocationId', 'loc_new_1')
        ->set('ghlToken', 'pit-new-token')
        ->call('saveConnection')
        ->assertHasNoErrors();

    $connection = $salon->ghlConnection()->first();
    expect($connection->location_id)->toBe('loc_new_1');
    expect($connection->calendar_id)->toBe('cal_keep'); // preserved
    expect($connection->private_integration_token)->toBe('pit-new-token');
});

// ---------------------------------------------------------------------------
// Go live
// ---------------------------------------------------------------------------

it('refuses to mark a salon live while steps are incomplete', function () {
    $salon = bookingSalon();
    $this->actingAs(wizardOwner($salon));

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('markLive');

    expect($salon->refresh()->onboarded_at)->toBeNull();
});

it('marks the salon live when every step is done', function () {
    $salon = completedSalon();
    app(SalonOnboarding::class)->attest($salon, 'voice_actions');
    $this->actingAs(wizardOwner($salon));

    expect(app(SalonOnboarding::class)->allDone($salon->refresh()))->toBeTrue();

    Livewire::test('pages::salon.onboarding', ['salon' => $salon])
        ->call('markLive');

    expect($salon->refresh()->onboarded_at)->not->toBeNull();
});

it('lists what is still incomplete for the go-live summary', function () {
    $salon = completedSalon(); // voice_actions not attested yet

    $pending = array_keys(array_filter(
        app(SalonOnboarding::class)->statuses($salon),
        fn (string $status): bool => $status !== SalonOnboarding::STATUS_DONE,
    ));
    expect($pending)->toBe(['voice_actions']);
});
