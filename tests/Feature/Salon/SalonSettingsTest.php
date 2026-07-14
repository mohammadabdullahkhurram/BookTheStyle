<?php

use App\Actions\Salons\UpdateBookingPolicy;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\Service;
use App\Models\User;
use App\Services\Calendar\CalendarData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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
        'auto_no_show' => true,
        'auto_no_show_grace_minutes' => 20,
        'auto_complete' => false,
    ]);

    $salon->refresh();
    expect($salon->allow_walkins)->toBeFalse();
    expect($salon->allow_same_day)->toBeFalse();
    expect($salon->max_advance_days)->toBe(30);
    expect($salon->min_notice_minutes)->toBe(45);
    expect($salon->auto_no_show)->toBeTrue();
    expect($salon->auto_no_show_grace_minutes)->toBe(20);
    expect($salon->auto_complete)->toBeFalse();
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

/*
| Settings categories + the editable timezone.
*/

it('renders the settings category navigation with every panel\'s fields present', function () {
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon));

    $this->get(route('salon.settings', $salon))
        ->assertOk()
        // The nav categories…
        ->assertSee('General')
        ->assertSee('Booking policy')
        ->assertSee('Features')
        ->assertSee('Branding')
        ->assertSee('Integrations')
        // …and each panel's content is on the page.
        ->assertSee('Business profile')
        ->assertSee('Salon timezone')
        ->assertSee('Allow walk-ins')
        ->assertSee('Feature flags')
        ->assertSee('Accent color')
        ->assertSee('GoHighLevel connection');
});

it('lets an owner change the salon timezone, shifting display but never the stored instant', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));

    $salon = Salon::factory()->create(['timezone' => 'America/New_York']);
    $owner = salonOwnerOf($salon);
    $stylist = stylistOf($salon);
    $service = Service::factory()->for($salon)->create();
    $client = Client::factory()->for($salon)->create();
    $booking = Booking::factory()->for($salon)->for($client)->create();
    $item = BookingItem::factory()->create([
        'salon_id' => $salon->id,
        'booking_id' => $booking->id,
        'service_id' => $service->id,
        'stylist_id' => $stylist->id,
        'starts_at' => CarbonImmutable::parse('2026-06-22 10:00', 'America/New_York'), // 14:00 UTC
        'ends_at' => CarbonImmutable::parse('2026-06-22 11:00', 'America/New_York'),
    ]);
    $instantBefore = $item->fresh()->starts_at->getTimestamp();

    Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->assertSet('timezone', 'America/New_York')
        ->set('timezone', 'America/Chicago')
        ->call('saveTimezone')
        ->assertHasNoErrors();

    expect($salon->fresh()->timezone)->toBe('America/Chicago');

    // The stored instant did not move…
    expect($item->fresh()->starts_at->getTimestamp())->toBe($instantBefore);

    // …but the calendar (which reads the salon's timezone live) now shows it
    // an hour earlier on the wall clock: 10:00 ET = 09:00 CT = minute 540.
    $grid = app(CalendarData::class)
        ->day($salon->fresh(), CarbonImmutable::parse('2026-06-22', 'America/Chicago'), null);
    $column = collect($grid['columns'])->firstWhere('stylistId', $stylist->id);
    $block = collect($column['bookings'])->firstWhere('startMin', 9 * 60);
    expect($block)->not->toBeNull();

    Carbon::setTestNow();
});

it('rejects an invalid timezone', function () {
    $salon = Salon::factory()->create();

    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('timezone', 'Mars/Olympus_Mons')
        ->call('saveTimezone')
        ->assertHasErrors('timezone');

    expect($salon->fresh()->timezone)->not->toBe('Mars/Olympus_Mons');
});
