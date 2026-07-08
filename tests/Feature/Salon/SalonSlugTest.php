<?php

use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Support\ReservedSlugs;
use Livewire\Livewire;

/*
| Salon slug validation in the agency console: format (DNS-safe label), the
| reserved system-subdomain blocklist, and global uniqueness. A salon's slug
| becomes a live subdomain, so these are the guardrails on what can be claimed.
*/

function agencyOwnerFor(Agency $agency): User
{
    return User::factory()->create([
        'agency_id' => $agency->id,
        'agency_role' => AgencyRole::Owner,
    ]);
}

it('rejects every reserved system slug', function () {
    $agency = Agency::factory()->create();

    foreach (ReservedSlugs::all() as $reserved) {
        Livewire::actingAs(agencyOwnerFor($agency))
            ->test('pages::agency.salons.create')
            ->set('name', 'Reserved Co')
            ->set('slug', $reserved)
            ->call('save')
            ->assertHasErrors('slug');
    }

    expect(Salon::query()->whereIn('slug', ReservedSlugs::all())->exists())->toBeFalse();
});

it('rejects malformed slugs', function (string $slug) {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->set('name', 'Some Salon')
        ->set('slug', $slug)
        ->call('save')
        ->assertHasErrors('slug');
})->with([
    'uppercase' => 'BadSlug',
    'underscore' => 'bad_slug',
    'space' => 'bad slug',
    'leading hyphen' => '-bad',
    'trailing hyphen' => 'bad-',
    'double hyphen' => 'bad--slug',
    'dot' => 'bad.slug',
    'too short' => 'a',
]);

it('accepts a valid slug and persists it', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput(['name' => 'Glow Bar']))
        ->set('slug', 'glow-bar')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('agency.salons.index'));

    expect(Salon::where('slug', 'glow-bar')->where('agency_id', $agency->id)->exists())->toBeTrue();
});

it('rejects a slug already taken by another salon', function () {
    $agency = Agency::factory()->create();
    Salon::factory()->create(['slug' => 'taken']);

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->set('name', 'Another Salon')
        ->set('slug', 'taken')
        ->call('save')
        ->assertHasErrors('slug');
});

it('lets a salon keep its own slug on edit but blocks taking another', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create(['slug' => 'mine']);
    Salon::factory()->create(['slug' => 'theirs']);

    // Saving with the salon's own slug unchanged is fine.
    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('slug', 'mine')
        ->call('save')
        ->assertHasNoErrors();

    // But it cannot grab a slug owned by a different salon.
    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->set('slug', 'theirs')
        ->call('save')
        ->assertHasErrors('slug');
});

/*
| User-facing copy: the field is labelled "Subdomain" (slug stays internal),
| a live preview shows the resulting web address, and validation messages
| speak in "subdomain".
*/

it('labels the field subdomain and previews the web address as typed on create', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->assertSee('Subdomain')
        ->assertDontSee('Subdomain slug')
        ->assertSee('This becomes the salon\'s web address. Lowercase letters, numbers, and hyphens only.')
        ->assertSee('yoursalon.'.config('app.domain'))
        ->set('slug', 'glow-bar')
        ->assertSee('glow-bar.'.config('app.domain'));
});

it('labels the field subdomain and previews the web address on edit', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create(['slug' => 'mine']);

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->assertSee('Subdomain')
        ->assertDontSee('Subdomain slug')
        ->assertSee('mine.'.config('app.domain'))
        ->set('slug', 'renamed')
        ->assertSee('renamed.'.config('app.domain'));
});

it('describes a reserved choice as a subdomain problem', function () {
    $agency = Agency::factory()->create();

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput(['name' => 'Reserved Co']))
        ->set('slug', 'app')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasErrors('slug')
        ->assertSee('The subdomain')
        ->assertSee('is reserved');
});

it('describes a taken choice as a subdomain problem', function () {
    $agency = Agency::factory()->create();
    Salon::factory()->create(['slug' => 'taken']);

    Livewire::actingAs(agencyOwnerFor($agency))
        ->test('pages::agency.salons.create')
        ->set(salonProfileInput(['name' => 'Another Salon']))
        ->set('slug', 'taken')
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertHasErrors('slug')
        ->assertSee('The subdomain has already been taken');
});
