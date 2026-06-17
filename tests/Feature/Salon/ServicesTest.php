<?php

use App\Actions\Services\CreateService;
use App\Actions\Services\SetServiceActive;
use App\Actions\Services\SyncServiceStylists;
use App\Actions\Services\UpdateService;
use App\Models\Salon;
use App\Models\Service;
use App\Models\ServiceStylist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('lets an owner create a service scoped to the salon', function () {
    $salon = Salon::factory()->create();

    $service = app(CreateService::class)->handle($salon, [
        'name' => 'Cut & Style', 'duration_min' => 45, 'color' => '#1F6F6B',
    ]);

    expect($service->salon_id)->toBe($salon->id);
    expect($service->name)->toBe('Cut & Style');
    expect($service->active)->toBeTrue();
});

it('forbids front desk and stylists from the services screen', function () {
    $salon = Salon::factory()->create();

    $this->actingAs(frontDeskOf($salon))->get(route('salon.services', $salon))->assertForbidden();
    $this->actingAs(stylistOf($salon))->get(route('salon.services', $salon))->assertForbidden();

    // An owner/admin can.
    $this->actingAs(salonOwnerOf($salon))->get(route('salon.services', $salon))->assertOk();
});

it('assigns only salon stylists to a service and lists them', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $foreignStylist = stylistOf(Salon::factory()->create());

    $service = Service::factory()->create(['salon_id' => $salon->id]);

    app(SyncServiceStylists::class)->handle($salon, $service, [$stylist->id, $foreignStylist->id]);

    // The foreign-salon stylist was dropped; only the salon's stylist sticks.
    expect($service->stylists()->pluck('users.id')->all())->toBe([$stylist->id]);
});

it('blocks cross-salon service edits (anti-IDOR)', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $serviceA = Service::factory()->create(['salon_id' => $salonA->id]);

    expect(fn () => app(UpdateService::class)->handle($salonB, $serviceA, [
        'name' => 'x', 'duration_min' => 30, 'color' => '#1F6F6B',
    ]))->toThrow(AuthorizationException::class);

    expect(fn () => app(SetServiceActive::class)->handle($salonB, $serviceA, false))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(SyncServiceStylists::class)->handle($salonB, $serviceA, []))
        ->toThrow(AuthorizationException::class);
});

it('404s acting on another salon\'s service through the screen', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $serviceA = Service::factory()->create(['salon_id' => $salonA->id]);

    $this->actingAs(salonOwnerOf($salonB));

    expect(fn () => Livewire::test('pages::salon.services.index', ['salon' => $salonB])
        ->call('toggleActive', $serviceA->id))
        ->toThrow(ModelNotFoundException::class);
});

it('creates + assigns through the services screen', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->set('name', 'Blowout')->set('duration_min', 40)->set('color', '#2B6CB0')
        ->call('create')->assertHasNoErrors();

    $service = $salon->services()->where('name', 'Blowout')->firstOrFail();

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->call('startEdit', $service->id)
        ->set('editStylistIds', [$stylist->id])
        ->call('saveEdit')->assertHasNoErrors();

    expect($service->fresh()->stylists()->pluck('users.id')->all())->toBe([$stylist->id]);
});

it('validates service input', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon));

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->set('name', 'X')->set('duration_min', 0)->set('color', '#1F6F6B')
        ->call('create')->assertHasErrors(['duration_min']);

    Livewire::test('pages::salon.services.index', ['salon' => $salon])
        ->set('name', 'X')->set('duration_min', 30)->set('color', 'not-a-color')
        ->call('create')->assertHasErrors(['color']);
});

it('keeps the service_stylist pivot salon-scoped with a matching salon_id', function () {
    $salonA = Salon::factory()->create();
    $salonB = Salon::factory()->create();
    $stylistA = stylistOf($salonA);
    $serviceA = Service::factory()->create(['salon_id' => $salonA->id]);

    app(SyncServiceStylists::class)->handle($salonA, $serviceA, [$stylistA->id]);

    // The pivot row carries salon A's id (defense-in-depth column).
    expect(ServiceStylist::query()->where('service_id', $serviceA->id)->value('salon_id'))
        ->toBe($salonA->id);

    // The global scope hides it from another salon's context.
    app()->instance('currentSalon', $salonB);
    expect(ServiceStylist::count())->toBe(0);

    app()->instance('currentSalon', $salonA);
    expect(ServiceStylist::count())->toBe(1);
});
