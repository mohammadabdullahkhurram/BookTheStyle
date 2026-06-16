<?php

use App\Actions\Salons\UpdateBookingPolicy;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Livewire\Livewire;

function settingsOwner(Salon $salon): User
{
    $user = User::factory()->create();
    SalonMembership::factory()->for($user)->for($salon)->owner()->create();

    return $user;
}

it('persists the booking policy', function () {
    $salon = Salon::factory()->create(['allow_walkins' => true, 'max_advance_days' => 90]);

    app(UpdateBookingPolicy::class)->handle($salon, [
        'allow_walkins' => false,
        'allow_same_day' => false,
        'max_advance_days' => 30,
        'min_notice_minutes' => 45,
    ]);

    $salon->refresh();
    expect($salon->allow_walkins)->toBeFalse();
    expect($salon->allow_same_day)->toBeFalse();
    expect($salon->max_advance_days)->toBe(30);
    expect($salon->min_notice_minutes)->toBe(45);
});

it('saves + validates the booking policy through the settings screen', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(settingsOwner($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('allow_walkins', false)
        ->set('max_advance_days', 30)
        ->set('min_notice_minutes', 60)
        ->call('savePolicy')
        ->assertHasNoErrors();

    $salon->refresh();
    expect($salon->max_advance_days)->toBe(30);
    expect($salon->min_notice_minutes)->toBe(60);

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('max_advance_days', 0)
        ->call('savePolicy')
        ->assertHasErrors(['max_advance_days']);
});

it('saves feature flags', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(settingsOwner($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('flags.online_booking', true)
        ->call('saveFlags')
        ->assertHasNoErrors();

    expect($salon->fresh()->hasFeature('online_booking'))->toBeTrue();
});

it('saves + validates branding accent', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(settingsOwner($salon));

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('brandName', 'Glow Studio')
        ->set('accent', '#1F6F6B')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect($salon->fresh()->accentColor())->toBe('#1F6F6B');

    Livewire::test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', 'not-a-color')
        ->call('saveBranding')
        ->assertHasErrors(['accent']);
});

it('forbids a stylist from opening salon settings', function () {
    $salon = Salon::factory()->create();
    $stylist = User::factory()->create();
    SalonMembership::factory()->for($stylist)->for($salon)->stylist()->create();

    $this->actingAs($stylist)->get(route('salon.settings', $salon))->assertForbidden();
});
