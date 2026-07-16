<?php

use App\Actions\Clients\CreateClient;
use App\Actions\Clients\UpdateClient;
use App\Enums\SalonRole;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('lets every booking-area role reach the directory; editing stays front-desk level', function () {
    // Viewing matches the client-profile rule (accessBookings): stylists may
    // look — they serve these clients — but add/edit stays manageBookings.
    $salon = Salon::factory()->create();

    $this->actingAs(frontDeskOf($salon))->get(route('salon.clients', $salon))->assertOk();
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.clients', $salon))->assertOk();
    $this->actingAs(stylistOf($salon))->get(route('salon.clients', $salon))->assertOk();

    // A user with no booking surface at all is still refused.
    $outsider = User::factory()->create();
    SalonMembership::factory()->for($outsider)->for($salon)->create([
        'salon_role' => SalonRole::Staff, 'staff_type' => null,
    ]);
    $this->actingAs($outsider)->get(route('salon.clients', $salon))->assertForbidden();

    // Stylists cannot create or edit clients from the screen.
    Livewire::actingAs(stylistOf($salon))
        ->test('pages::salon.clients.index', ['salon' => $salon])
        ->set('name', 'Sneaky Add')
        ->call('create')
        ->assertForbidden();
});

it('creates a client scoped to the salon', function () {
    $salon = Salon::factory()->create();

    $client = app(CreateClient::class)->handle($salon, ['name' => 'Jane Doe', 'phone' => '555', 'email' => 'j@e.com']);

    expect($client->salon_id)->toBe($salon->id);
    expect($client->name)->toBe('Jane Doe');
});

it('blocks cross-salon client edits and 404s through the screen', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $clientA = Client::factory()->create(['salon_id' => $salonA->id]);

    expect(fn () => app(UpdateClient::class)->handle($salonB, $clientA, ['name' => 'x']))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(salonOwnerOf($salonB));
    expect(fn () => Livewire::test('pages::salon.clients.index', ['salon' => $salonB])->call('startEdit', $clientA->id))
        ->toThrow(ModelNotFoundException::class);
});

it('keeps clients salon-scoped under the global scope', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    Client::factory()->create(['salon_id' => $salonA->id]);

    app()->instance('currentSalon', $salonB);
    expect(Client::count())->toBe(0);

    app()->instance('currentSalon', $salonA);
    expect(Client::count())->toBe(1);
});

it('searches clients through the screen', function () {
    $salon = Salon::factory()->create();
    Client::factory()->create(['salon_id' => $salon->id, 'name' => 'Alice Apple']);
    Client::factory()->create(['salon_id' => $salon->id, 'name' => 'Bob Banana']);

    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.clients.index', ['salon' => $salon])
        ->set('search', 'Apple')
        ->assertSee('Alice Apple')
        ->assertDontSee('Bob Banana');
});

it('creates a client through the screen with validation', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(frontDeskOf($salon));

    Livewire::test('pages::salon.clients.index', ['salon' => $salon])
        ->set('name', 'New Client')->set('email', 'not-an-email')
        ->call('create')->assertHasErrors(['email']);

    Livewire::test('pages::salon.clients.index', ['salon' => $salon])
        ->set('name', 'New Client')
        ->call('create')->assertHasNoErrors();

    expect($salon->clients()->where('name', 'New Client')->exists())->toBeTrue();
});
