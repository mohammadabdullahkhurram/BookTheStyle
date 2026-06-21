<?php

use App\Models\Salon;
use App\Models\StylistProfile;
use Livewire\Livewire;

/*
| Bio moved off the Availability screen. It is now self-edited on account
| settings and set by an owner/admin on the Staff edit screen — both writing
| the same StylistProfile.bio field (per user + salon). Storage is unchanged.
*/

it('no longer shows the stylist bio editor on the availability page', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);

    $html = $this->actingAs($stylist)->get(route('salon.availability', $salon))->assertOk()->getContent();

    expect($html)->not->toContain('Stylist bio');
});

it('lets a stylist edit their own bio from account settings', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $this->actingAs($stylist);

    Livewire::test('pages::settings.stylist-bio')
        ->assertSet("stylistSalons.{$salon->id}.name", $salon->name)
        ->set("stylistSalons.{$salon->id}.bio", 'Ten years of colour work.')
        ->call('saveBio', $salon->id);

    expect(StylistProfile::query()->where('salon_id', $salon->id)->where('user_id', $stylist->id)->value('bio'))
        ->toBe('Ten years of colour work.');
});

it('does not offer a bio section to a non-stylist on account settings', function () {
    $salon = Salon::factory()->create();
    $frontDesk = frontDeskOf($salon);

    $html = $this->actingAs($frontDesk)->get(route('profile.edit'))->assertOk()->getContent();

    expect($html)->not->toContain('Stylist bio');
});

it('lets an owner set a stylist\'s bio from the staff edit screen', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $membership = $stylist->membershipFor($salon);

    $this->actingAs($owner);

    Livewire::test('pages::salon.staff.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->set('editBio', 'Specialises in balayage.')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect(StylistProfile::query()->where('salon_id', $salon->id)->where('user_id', $stylist->id)->value('bio'))
        ->toBe('Specialises in balayage.');
});

it('preloads an existing bio into the staff edit screen', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    StylistProfile::create(['salon_id' => $salon->id, 'user_id' => $stylist->id, 'bio' => 'Loves precision cuts.']);
    $membership = $stylist->membershipFor($salon);

    $this->actingAs($owner);

    Livewire::test('pages::salon.staff.index', ['salon' => $salon])
        ->call('startEdit', $membership->id)
        ->assertSet('editBio', 'Loves precision cuts.');
});
