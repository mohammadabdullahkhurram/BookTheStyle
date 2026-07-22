<?php

use App\Actions\Services\CreateService;
use App\Actions\Services\MoveService;
use App\Models\Salon;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
| Owner-controlled menu order (services.sort_order): every service-listing
| surface orders by sort_order then name, the admin table nudges rows up and
| down, and new services join the END of the menu. Legacy rows (sort_order 0,
| never reordered) keep their historical name ordering ahead of explicitly
| ordered ones — and the first manual nudge materialises the whole visible
| order into explicit positions.
*/

function orderedSalon(): array
{
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $services = collect([
        ['Waves', 0],
        ['Alpha Cut', 0],
        ['Mid Treatment', 1],
        ['Top Colour', 2],
    ])->map(function (array $row) use ($salon, $stylist): Service {
        $service = Service::factory()->create([
            'salon_id' => $salon->id,
            'name' => $row[0],
            'sort_order' => $row[1],
        ]);
        $service->stylists()->attach($stylist->id, ['salon_id' => $salon->id]);

        return $service;
    });

    return [$salon, $services];
}

it('orders services by sort_order then name on every listing surface', function () {
    [$salon] = orderedSalon();

    // The scope: legacy zeros first (alphabetical — their historical order),
    // then the explicitly ordered menu.
    expect($salon->services()->displayOrder()->pluck('name')->all())
        ->toBe(['Alpha Cut', 'Waves', 'Mid Treatment', 'Top Colour']);

    // The public widget catalogue follows the same order.
    $names = $this->getJson('http://'.$salon->slug.'.'.config('app.domain').'/api/widget/services')
        ->assertOk()
        ->json('services.*.name');
    expect($names)->toBe(['Alpha Cut', 'Waves', 'Mid Treatment', 'Top Colour']);

    // The admin table renders in the same order.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->assertSeeInOrder(['Alpha Cut', 'Waves', 'Mid Treatment', 'Top Colour']);
});

it('nudges a service in the admin menu and materialises explicit positions', function () {
    [$salon, $services] = orderedSalon();
    $other = Salon::factory()->create();
    $otherService = Service::factory()->create(['salon_id' => $other->id, 'sort_order' => 0]);

    // Move "Top Colour" up one: Alpha, Waves, Top, Mid.
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->call('move', $services->firstWhere('name', 'Top Colour')->id, -1)
        ->assertSeeInOrder(['Alpha Cut', 'Waves', 'Top Colour', 'Mid Treatment']);

    // One nudge renumbers the WHOLE visible order 1..n — no zeros left behind.
    expect($salon->services()->displayOrder()->pluck('sort_order', 'name')->all())
        ->toBe(['Alpha Cut' => 1, 'Waves' => 2, 'Top Colour' => 3, 'Mid Treatment' => 4]);

    // Edges are no-ops.
    app(MoveService::class)->handle($salon, $salon->services()->displayOrder()->first(), -1);
    expect($salon->services()->displayOrder()->first()->name)->toBe('Alpha Cut');

    // Tenant isolation: the other salon's rows were never touched, and its
    // service cannot be moved through this salon.
    expect($otherService->fresh()->sort_order)->toBe(0);
    expect(fn () => app(MoveService::class)->handle($salon, $otherService, -1))
        ->toThrow(AuthorizationException::class);
});

it('appends newly created services to the end of the menu', function () {
    [$salon] = orderedSalon();

    $new = app(CreateService::class)->handle($salon, ['name' => 'Aaa Brand New', 'duration_min' => 30]);

    expect($new->sort_order)->toBe(3); // max(2) + 1
    expect($salon->services()->displayOrder()->pluck('name')->last())->toBe('Aaa Brand New');
});

it('keeps the stylist matrix forbidden from reordering (managers only)', function () {
    [$salon, $services] = orderedSalon();

    Livewire::actingAs(stylistOf($salon))
        ->test('pages::salon.services.index', ['salon' => $salon])
        ->assertForbidden();

    expect($services->firstWhere('name', 'Top Colour')->fresh()->sort_order)->toBe(2);
});
