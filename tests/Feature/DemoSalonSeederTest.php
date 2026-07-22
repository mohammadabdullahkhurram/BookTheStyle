<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Agency;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Salon;
use App\Models\TimeOff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSalonSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| The demo-salon seeder: a complete, realistic salon for UI review —
| STRICTLY ADDITIVE (never resets anything) and idempotent (keyed on the
| demo slug). CLAUDE.md golden rule 10 forbids destructive DB resets
| repo-wide; this seeder is the sanctioned way to restore test data.
*/

it('seeds a complete demo salon: staff, services, availability, clients, bookings, widget', function () {
    $this->seed(DemoSalonSeeder::class);

    $salon = Salon::query()->where('slug', 'glamour')->firstOrFail();

    // Tenanted under the Bluejaypro agency, themed, onboarded.
    expect($salon->agency->name)->toBe('Bluejaypro');
    expect($salon->app_theme)->toBe('marble');
    expect($salon->timezone)->toBe('America/Los_Angeles');
    expect($salon->onboarded_at)->not->toBeNull();

    // Staff: owner + front desk + 4 stylists, all with known passwords.
    $owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();
    expect($owner->membershipFor($salon)?->salon_role->value)->toBe('salon_owner');
    expect($salon->stylistUsers()->count())->toBe(4);
    expect(User::query()->where('email', 'frontdesk@demo.test')->exists())->toBeTrue();

    // Services with prices and per-stylist duration overrides.
    expect($salon->services()->count())->toBe(5);
    expect($salon->services()->whereNotNull('price_cents')->count())->toBe(5);
    $overrides = DB::table('service_stylist')
        ->where('salon_id', $salon->id)->whereNotNull('duration_override')->count();
    expect($overrides)->toBe(2);

    // Availability: weekly hours (incl. a break) + time off + date-specific.
    expect(Availability::forSalon($salon)->where('kind', 'work')->count())->toBeGreaterThanOrEqual(18);
    expect(Availability::forSalon($salon)->where('kind', 'break')->count())->toBeGreaterThanOrEqual(5);
    expect(TimeOff::forSalon($salon)->where('kind', TimeOff::KIND_OFF)->count())->toBe(1);
    expect(TimeOff::forSalon($salon)->where('kind', TimeOff::KIND_HOURS)->count())->toBe(1);

    // A rich client book: allergies, formulas, preferred stylists, birthdays.
    expect($salon->clients()->count())->toBeGreaterThanOrEqual(15);
    expect($salon->clients()->whereNotNull('allergies')->count())->toBeGreaterThanOrEqual(3);
    expect($salon->clients()->whereNotNull('preferred_stylist_id')->count())->toBeGreaterThanOrEqual(15);

    // Bookings: past + today + upcoming, every status and source that the
    // dashboard, calendar, check-in and reports screens need.
    $bookings = $salon->bookings();
    expect($bookings->count())->toBeGreaterThanOrEqual(40);
    foreach ([BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled, BookingStatus::Booked, BookingStatus::Arrived, BookingStatus::InService] as $status) {
        expect($salon->bookings()->where('status', $status->value)->exists())->toBeTrue();
    }
    foreach ([BookingSource::InApp, BookingSource::VoiceAi, BookingSource::WebWidget, BookingSource::ChatWidget] as $source) {
        expect($salon->bookings()->where('source', $source->value)->exists())->toBeTrue();
    }

    // Multi-service visits linked by a visit group (more than one).
    $groups = $salon->bookings()->whereNotNull('visit_group_id')->distinct('visit_group_id')->count('visit_group_id');
    expect($groups)->toBeGreaterThanOrEqual(2);

    // Every booking is tenant-correct with items in the same salon.
    expect(Booking::query()->where('salon_id', '!=', $salon->id)->whereIn('id', $salon->bookings()->pluck('id'))->count())->toBe(0);
    expect(DB::table('booking_items')->whereIn('booking_id', $salon->bookings()->pluck('id'))->where('salon_id', '!=', $salon->id)->count())->toBe(0);

    // The Widgets area has a row to show.
    expect($salon->widgets()->count())->toBe(1);
});

it('is idempotent and strictly additive — existing data survives, nothing duplicates', function () {
    // Pre-existing, unrelated data that a reset would destroy.
    $existing = bookingSalon();
    $stylist = stylistWithHours($existing, 0, 9 * 60, 17 * 60);
    $service = serviceFor($existing, $stylist, 60);
    Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC'));
    $booking = makeBooking($existing, salonOwnerOf($existing), $stylist, $service);
    Carbon::setTestNow();

    $this->seed(DemoSalonSeeder::class);
    $demo = Salon::query()->where('slug', 'glamour')->firstOrFail();
    $bookingCount = $demo->bookings()->count();
    $clientCount = $demo->clients()->count();

    // Running again is a clean no-op — no duplicates of anything.
    $this->seed(DemoSalonSeeder::class);
    expect(Salon::query()->where('slug', 'glamour')->count())->toBe(1);
    expect($demo->bookings()->count())->toBe($bookingCount);
    expect($demo->clients()->count())->toBe($clientCount);
    expect(User::query()->where('email', 'owner@demo.test')->count())->toBe(1);

    // And the pre-existing salon's data is untouched.
    expect($existing->fresh())->not->toBeNull();
    expect(Booking::query()->whereKey($booking->id)->exists())->toBeTrue();
    expect($existing->services()->whereKey($service->id)->exists())->toBeTrue();

    // The repo-wide rule is written down where every future prompt reads it.
    expect(file_get_contents(base_path('CLAUDE.md')))
        ->toContain('Never reset the database')
        ->toContain('DemoSalonSeeder');
});

it('composes with the base seeder without conflict', function () {
    $this->seed(); // DatabaseSeeder: the agency + agency accounts
    $this->seed(DemoSalonSeeder::class);

    // One agency, shared by both seeders.
    expect(Agency::query()->where('name', 'Bluejaypro')->count())->toBe(1);
    expect(Salon::query()->where('slug', 'glamour')->firstOrFail()->agency->name)->toBe('Bluejaypro');
});

it('refuses to run in production — demo accounts carry weak passwords', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new DemoSalonSeeder)->run())
        ->toThrow(RuntimeException::class, 'must never run in production');

    expect(Salon::query()->where('slug', 'glamour')->exists())->toBeFalse();
});
