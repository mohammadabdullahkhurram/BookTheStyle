<?php

use App\Actions\Salons\DeleteSalon;
use App\Enums\AgencyRole;
use App\Models\Agency;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Salon;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Deleting a whole salon — agency owner/admin only, typed-name confirmed,
| demo refused, strictly scoped to the one salon. Salon-owned rows cascade
| via their FKs (the mechanism the demo sweep has used in production since
| launch); orphaned member accounts soft-delete.
*/

/** A populated salon: owner + stylist with hours, a service, a booking. */
function delSalonFixture(): array
{
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $at = CarbonImmutable::now($salon->timezone)->addDays(2);
    $stylist = stylistWithHours($salon, (int) $at->format('N') - 1, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $booking = makeBooking($salon, $owner, $stylist, $service, $at->setTime(10, 0)->format('Y-m-d H:i'));
    $operator = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::Admin]);

    return compact('salon', 'owner', 'stylist', 'service', 'booking', 'operator');
}

it('deletes the salon and every owned record FK-safely after the typed-name confirmation — another salon untouched', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'service' => $service, 'booking' => $booking, 'operator' => $operator] = delSalonFixture();
    $other = delSalonFixture();

    app(DeleteSalon::class)->handle($operator, $salon, $salon->name);

    // The salon and everything under it is gone.
    expect(Salon::withoutGlobalScopes()->whereKey($salon->id)->exists())->toBeFalse();
    expect(Service::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)->exists())->toBeFalse();
    expect(Client::withoutGlobalScopes()->withTrashed()->where('salon_id', $salon->id)->exists())->toBeFalse();
    expect(Booking::withoutGlobalScopes()->whereKey($booking->id)->exists())->toBeFalse();
    expect(DB::table('booking_items')->where('salon_id', $salon->id)->exists())->toBeFalse();
    expect(Availability::withoutGlobalScopes()->where('salon_id', $salon->id)->exists())->toBeFalse();
    expect(DB::table('salon_memberships')->where('salon_id', $salon->id)->exists())->toBeFalse();

    // Members with no other membership are ARCHIVED (soft), not destroyed.
    expect(User::whereKey($owner->id)->exists())->toBeFalse();
    expect(User::withTrashed()->whereKey($owner->id)->exists())->toBeTrue();
    expect(User::withTrashed()->whereKey($stylist->id)->sole()->trashed())->toBeTrue();

    // The other salon is byte-for-byte untouched.
    expect(Salon::withoutGlobalScopes()->whereKey($other['salon']->id)->exists())->toBeTrue();
    expect(Booking::withoutGlobalScopes()->whereKey($other['booking']->id)->exists())->toBeTrue();
    expect(User::whereKey($other['stylist']->id)->exists())->toBeTrue();
});

it('keeps a cross-salon member\'s account alive when one of their salons is deleted', function () {
    ['salon' => $salon, 'stylist' => $stylist, 'operator' => $operator] = delSalonFixture();
    $second = bookingSalon();
    stylistOf($second, $stylist);

    app(DeleteSalon::class)->handle($operator, $salon, $salon->name);

    expect(User::whereKey($stylist->id)->exists())->toBeTrue(); // still logs in
    expect($second->memberships()->where('user_id', $stylist->id)->exists())->toBeTrue();
});

it('refuses the wrong name, wrong roles, cross-agency operators, and demo salons', function () {
    ['salon' => $salon, 'owner' => $owner, 'stylist' => $stylist, 'operator' => $operator] = delSalonFixture();

    // Typed-name confirmation is server-side.
    expect(fn () => app(DeleteSalon::class)->handle($operator, $salon, 'wrong name'))
        ->toThrow(ValidationException::class);

    // Salon roles — the salon's own OWNER included — cannot delete a salon.
    foreach ([$owner, $stylist, managerOf($salon)] as $actor) {
        expect(fn () => app(DeleteSalon::class)->handle($actor, $salon, $salon->name))
            ->toThrow(AuthorizationException::class);
    }

    // A delegated agency_user is not an operator; a foreign agency's op is refused.
    $delegated = User::factory()->create(['agency_id' => $salon->agency_id, 'agency_role' => AgencyRole::User]);
    $foreignOp = User::factory()->create(['agency_id' => Agency::factory()->create()->id, 'agency_role' => AgencyRole::Owner]);
    foreach ([$delegated, $foreignOp] as $actor) {
        expect(fn () => app(DeleteSalon::class)->handle($actor, $salon, $salon->name))
            ->toThrow(AuthorizationException::class);
    }

    // Demo salons belong to the demo lifecycle.
    $demo = demoShowcase();
    $demoOp = User::factory()->create(['agency_id' => $demo->agency_id, 'agency_role' => AgencyRole::Owner]);
    expect(fn () => app(DeleteSalon::class)->handle($demoOp, $demo, $demo->name))
        ->toThrow(AuthorizationException::class);

    expect(Salon::withoutGlobalScopes()->whereKey($salon->id)->exists())->toBeTrue(); // nothing happened
});

it('walks the UI flow: blast radius shown, wrong name surfaces the error, exact name deletes and redirects', function () {
    ['salon' => $salon, 'operator' => $operator] = delSalonFixture();

    $component = Livewire::actingAs($operator)
        ->test('pages::agency.salons.edit', ['salon' => $salon])
        ->call('startDeleteSalon')
        ->assertSet('showDeleteSalon', true)
        ->assertSee(__('This deletes, permanently:'))
        ->assertSee(trans_choice(':count appointment, past and future|:count appointments, past and future', 1, ['count' => 1]))
        ->assertSee(__('The GHL sub-account itself and the hPanel subdomain are NOT touched — clean those up by hand.'));

    $component->set('confirmName', 'not the name')->call('deleteSalon')->assertHasErrors(['confirmName']);
    expect(Salon::withoutGlobalScopes()->whereKey($salon->id)->exists())->toBeTrue();

    $component->set('confirmName', $salon->name)->call('deleteSalon')->assertHasNoErrors()
        ->assertRedirect(route('dashboard'));
    expect(Salon::withoutGlobalScopes()->whereKey($salon->id)->exists())->toBeFalse();
});

it('never renders the danger zone for non-operators — the whole editor is agency-op gated', function () {
    ['salon' => $salon, 'owner' => $owner] = delSalonFixture();

    $this->actingAs($owner)->get(route('agency.salons.edit', $salon))->assertForbidden();
});
