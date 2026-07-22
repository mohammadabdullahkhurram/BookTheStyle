<?php

use App\Console\Commands\LaunchCapture;
use App\Models\BookingItem;
use App\Models\Client;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Database\Seeders\LaunchSalonSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| The launch-video capture fixture: LaunchSalonSeeder (a busy, FICTIONAL
| salon pinned to a fixed anchor date so screenshots reproduce exactly) and
| the launch:capture command that steers it. Everything is additive and
| local-only — the capture harness itself (scripts/capture-launch-assets.mjs)
| refuses non-local targets; these tests pin the PHP side of those rules.
*/

it('seeds the launch salon: busy, anchored to a fixed date, and idempotent', function () {
    Storage::fake('public');

    (new LaunchSalonSeeder)->run();
    $salon = Salon::query()->where('slug', LaunchSalonSeeder::SLUG)->firstOrFail();

    // A real, healthy business: menu, staff, client book, mixed outcomes.
    expect($salon->services()->count())->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(12);
    expect($salon->clients()->count())->toBeGreaterThanOrEqual(25)->toBeLessThanOrEqual(40);
    expect($salon->stylistUsers()->count())->toBeGreaterThanOrEqual(4);
    expect($salon->bookings()->count())->toBeGreaterThan(30);
    expect($salon->bookings()->where('status', 'no_show')->count())->toBeGreaterThan(0);
    expect($salon->bookings()->where('status', 'completed')->count())->toBeGreaterThan(10);

    // Branded: accent + a generated placeholder logo on the public disk.
    expect($salon->accentColor())->toBe(LaunchSalonSeeder::ACCENT);
    $logo = $salon->branding['logo_path'] ?? null;
    expect($logo)->not->toBeNull();
    expect(Storage::disk('public')->exists($logo))->toBeTrue();
    expect($salon->widgets()->where('public_id', LaunchSalonSeeder::WIDGET_PUBLIC_ID)->exists())->toBeTrue();

    // ANCHORED — never now(): the three-week dataset brackets the fixed
    // anchor, so captures look identical no matter when they run.
    $anchor = CarbonImmutable::parse(LaunchSalonSeeder::ANCHOR, LaunchSalonSeeder::TIMEZONE);
    $min = CarbonImmutable::parse((string) BookingItem::query()->where('salon_id', $salon->id)->min('starts_at'));
    $max = CarbonImmutable::parse((string) BookingItem::query()->where('salon_id', $salon->id)->max('starts_at'));
    expect($min->between($anchor->subWeeks(4), $anchor))->toBeTrue();
    expect($max->between($anchor, $anchor->addWeeks(4)))->toBeTrue();

    // The story is internally consistent on camera: a client's stored
    // preferred stylist is the one their visit history actually shows.
    $visits = $salon->bookings()->whereNotNull('client_id')->with('items')->get()
        ->flatMap(fn ($booking) => $booking->items->map(fn ($item) => ['client' => $booking->client_id, 'stylist' => $item->stylist_id]))
        ->groupBy('client');
    foreach ($visits as $clientId => $rows) {
        $counts = $rows->countBy('stylist');
        $tiedForMax = $counts->filter(fn (int $c) => $c === $counts->max())->keys()->all();
        expect($tiedForMax)->toContain($salon->clients()->whereKey($clientId)->value('preferred_stylist_id'));
    }

    // Menu order is deliberate, not alphabetical: the signature work leads
    // and Beard trim closes the list.
    $menu = $salon->services()->displayOrder()->pluck('name');
    expect($menu->first())->toBe('Hair cut');
    expect($menu->last())->toBe('Beard trim');

    // Idempotent: a second run is a no-op — nothing new, nothing touched.
    $before = [Salon::count(), $salon->bookings()->count(), $salon->clients()->count(), $salon->services()->count()];
    (new LaunchSalonSeeder)->run();
    $salon->refresh();
    expect([Salon::count(), $salon->bookings()->count(), $salon->clients()->count(), $salon->services()->count()])
        ->toBe($before);
});

it('refuses to seed in production — weak fixture credentials never go live', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        expect(fn () => (new LaunchSalonSeeder)->run())->toThrow(RuntimeException::class);
    } finally {
        $this->app['env'] = $original;
    }

    expect(Salon::query()->where('slug', LaunchSalonSeeder::SLUG)->exists())->toBeFalse();
});

it('steers the fixture via launch:capture — style variants, baseline reset, sentinel cleanup', function () {
    Storage::fake('public');

    $this->artisan('launch:capture', ['action' => 'prepare'])->assertExitCode(0);
    $salon = Salon::query()->where('slug', LaunchSalonSeeder::SLUG)->firstOrFail();

    // The accent/theme beat: same salon, different style.
    $this->artisan('launch:capture', ['action' => 'style', '--accent' => '#C0613E', '--theme' => 'glacier'])
        ->assertExitCode(0);
    $salon->refresh();
    expect($salon->accentColor())->toBe('#C0613E');
    expect($salon->app_theme)->toBe('glacier');
    // …and the logo survives an accent swap (branding is merged, not replaced).
    expect($salon->branding['logo_path'] ?? null)->not->toBeNull();

    // Garbage in, refusal out.
    $this->artisan('launch:capture', ['action' => 'style', '--accent' => 'tomato'])->assertExitCode(1);
    $this->artisan('launch:capture', ['action' => 'style', '--theme' => 'velvet'])->assertExitCode(1);

    // The widget confirmation shot books a real slot as the sentinel client;
    // prepare removes exactly that and resets style, so a re-run books the
    // same slot again. Other bookings are untouched.
    $bookingsBefore = $salon->bookings()->count();
    $sentinel = Client::create([
        'salon_id' => $salon->id,
        'name' => 'Jamie Rivera',
        'phone' => LaunchCapture::CAPTURE_CLIENT_PHONE,
    ]);
    $booking = $sentinel->bookings()->create([
        'salon_id' => $salon->id,
        'status' => 'booked',
        'booked_by_type' => 'web_widget',
        'source' => 'web_widget',
        'is_walkin' => false,
    ]);

    $this->artisan('launch:capture', ['action' => 'prepare'])->assertExitCode(0);
    $salon->refresh();
    expect($salon->accentColor())->toBe(LaunchSalonSeeder::ACCENT);
    expect($salon->app_theme)->toBe('marble');
    expect(Client::query()->whereKey($sentinel->id)->exists())->toBeFalse();
    expect($salon->bookings()->whereKey($booking->id)->exists())->toBeFalse();
    expect($salon->bookings()->count())->toBe($bookingsBefore);
});

it('refuses launch:capture outside local and testing environments', function () {
    $original = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $this->artisan('launch:capture', ['action' => 'info'])->assertExitCode(1);
    } finally {
        $this->app['env'] = $original;
    }
});

it('keeps the widget bot gate and rate limit at their protective defaults — capture overrides never ship', function () {
    // The capture harness lifts these ONLY inside the environment of the
    // server it spawns itself (scripts/capture-launch-assets.mjs); nothing
    // tracked sets them. BOOKING_WIDGET_MIN_SECONDS=0 disables the bot gate
    // outright and must never reach production — this pins the committed
    // defaults so a leaked override fails the build.
    expect((int) config('booking_api.widget_min_seconds'))->toBeGreaterThanOrEqual(4);
    expect((int) config('booking_api.widget_rate_limit'))->toBeLessThanOrEqual(30);
});

it('never registers the programmatic capture login outside APP_ENV=local', function () {
    // Routes were registered under APP_ENV=testing — the local-only guard in
    // routes/web.php must have skipped the capture login entirely. In
    // production the same guard runs with APP_ENV=production, and deploys
    // cache routes, so the route cannot exist there either.
    expect(Route::has('capture.login'))->toBeFalse();
});
