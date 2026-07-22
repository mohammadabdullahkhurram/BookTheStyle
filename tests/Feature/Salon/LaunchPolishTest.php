<?php

use App\Models\Client;
use App\Models\Salon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| Camera-found product fixes (the 3x launch-asset audit): calendar chips
| never render half a text line, reports never show a bare count where a
| revenue figure belongs, and the widget states the salon's identity and
| the how-it-works guidance exactly once.
*/

beforeEach(fn () => Carbon::setTestNow(CarbonImmutable::parse('2026-06-22 12:00:00', 'UTC')));
afterEach(fn () => Carbon::setTestNow());

it('drops calendar chip lines whole — short chips collapse to one line, long chips show all three', function () {
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);

    // A 120-minute chip fits all three lines; a 15-minute chip cannot even
    // fit two, so it collapses to the "time · client" one-liner and its
    // service line disappears entirely (it used to render half-height).
    $long = serviceFor($salon, $stylist, 120);
    $long->update(['name' => 'Signature Colour Ritual']);
    $short = serviceFor($salon, $stylist, 15);
    $short->update(['name' => 'Fringe Dusting']);

    makeBooking($salon, $owner, $stylist, $long, '2026-06-22 10:00', 'Long Chip Client');
    makeBooking($salon, $owner, $stylist, $short, '2026-06-22 14:00', 'Short Chip Client');

    $this->actingAs($owner);
    Livewire::test('pages::salon.calendar', ['salon' => $salon])
        ->assertSee('Signature Colour Ritual')   // 3-line chip keeps its service
        ->assertSee('Long Chip Client')
        ->assertSee('Short Chip Client')         // 1-line chip keeps time · client…
        ->assertDontSee('Fringe Dusting');       // …and hides the service line WHOLE
});

it('renders an explicit dash — never a bare count — for booked-but-uncompleted revenue', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $owner = salonOwnerOf($salon);

    $service = serviceFor($salon, $stylist, 60);
    $service->update(['name' => 'Priced But Pending', 'price_cents' => 9900]);
    makeBooking($salon, $owner, $stylist, $service, '2026-06-22 10:00');

    // Booked, not completed → the report row counts it but has no revenue:
    // the cell must say so explicitly.
    $this->actingAs($owner);
    Livewire::test('pages::salon.reports', ['salon' => $salon])
        ->assertSee('Priced But Pending')
        ->assertSee('1 · —');
});

it('states the salon identity and the booking guidance exactly once on the widget', function () {
    Storage::fake('public');

    $salon = Salon::factory()->create(['name' => 'Dedup Test Salon']);
    stylistOf($salon); // makes the catalogue non-empty
    $url = 'http://'.$salon->slug.'.'.config('app.domain').'/widget/'.$salon->defaultWidget()->public_id;

    // Without a logo: the name renders as the visible h1 (once).
    $html = $this->get($url)->assertOk()->getContent();
    expect(substr_count($html, 'Dedup Test Salon'))->toBe(3); // <title> + data-salon attr + the ONE visible h1
    expect($html)->not->toContain('Choose a service to begin');
    expect($html)->toContain('Your visit builds here');

    // With a logo: the wordmark carries the identity, the h1 goes
    // screen-reader-only — never two stacked salon names.
    Storage::disk('public')->put('branding/'.$salon->id.'/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $salon->forceFill(['branding' => ['logo_path' => 'branding/'.$salon->id.'/logo.svg']])->save();

    $html = $this->get($url)->assertOk()->getContent();
    expect($html)->toContain('wb-logo');
    expect($html)->toContain('clip:rect(0,0,0,0)'); // the sr-only h1
});
