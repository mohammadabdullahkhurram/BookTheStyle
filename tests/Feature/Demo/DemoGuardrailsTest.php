<?php

use App\Models\Salon;
use App\Support\DemoMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| Demo guardrails in the guest model: personal surfaces need auth (guests
| are simply sent to login), salon plumbing stays hidden from the showcase,
| the showcase pages render read-only with honest notes, and the widget
| preview works in both contexts. Real salons keep full access everywhere —
| asserted in both directions.
*/

// ---------------------------------------------------------------------------
// Personal settings — plain auth keeps demo guests out
// ---------------------------------------------------------------------------

it('sends guests from personal-settings routes to the real login', function () {
    demoShowcase();

    $app = 'http://app.'.config('app.domain');

    $this->get($app.'/settings/profile')->assertRedirect(route('login'));
    $this->get($app.'/settings')->assertRedirect(); // the group redirect chain ends at login

    // Real users keep full access.
    $salon = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($salon))->get(route('profile.edit'))->assertOk();
});

// ---------------------------------------------------------------------------
// Salon plumbing hidden; showcase read-only with notes
// ---------------------------------------------------------------------------

it('keeps the integrations plumbing out of the demo settings surface', function () {
    $salon = demoShowcase();

    $this->get('http://demo.'.config('app.domain').'/settings')
        ->assertOk()
        ->assertDontSee(__('Integrations'))
        ->assertDontSee(__('Voice AI booking API'))
        ->assertDontSee(__('Load from GoHighLevel'));

    // The salon-keyed policy denies the viewer (and everyone) on demo salons.
    expect(salonOwnerOf($salon)->can('manageGhlConnection', $salon))->toBeFalse();
});

it('renders the showcase settings read-only with the demo notes', function () {
    $salon = demoShowcase();
    $host = 'http://demo.'.config('app.domain');

    $this->get($host.'/settings')->assertOk()
        ->assertSee(__('Play with the accent and themes freely — saving is disabled in the demo.'));
    $this->get($host.'/availability')->assertOk()
        ->assertSee(__('Browse every schedule freely — editing hours is disabled in the demo.'));
    $this->get($host.'/users')->assertOk()
        ->assertSee(__('Browse the team setup freely — staff changes are disabled in the demo.'));

    // The saves are no-ops.
    $before = $salon->accentColor();
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#123456')
        ->call('saveBranding')
        ->assertHasNoErrors();
    expect($salon->fresh()->accentColor())->toBe($before);
});

it('shows the calendar-feed page with links disabled in demo, working for real users', function () {
    $salon = demoShowcase();

    $this->get('http://demo.'.config('app.domain').'/account')
        ->assertOk()
        ->assertSee(__('Calendar links are disabled in the demo.'))
        ->assertDontSee(__('Generate calendar link'));

    // The action is a no-op in demo context even when invoked directly.
    app()->instance('currentSalon', $salon);
    $owner = salonOwnerOf($salon);
    Livewire::actingAs($owner)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('connected', false);
    expect($owner->calendarConnection()->first()?->hasFeed())->not->toBeTrue();
    app()->forgetInstance('currentSalon');

    // A real user still generates a working feed link.
    $real = Salon::factory()->create();
    $realOwner = salonOwnerOf($real);
    Livewire::actingAs($realOwner)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('connected', true);
    expect($realOwner->fresh()->calendarConnection()->first()?->hasFeed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// The widget preview — both contexts (design unchanged: static in-app iframe)
// ---------------------------------------------------------------------------

it('opens the widget preview popup onto the in-app route, never the public host', function () {
    $salon = demoShowcase();
    $host = 'http://demo.'.config('app.domain');
    $widget = $salon->defaultWidget();

    // Closed: trigger only, no iframe on the page.
    $this->get($host.'/widgets')
        ->assertOk()
        ->assertSee('openPreview', false)
        ->assertDontSee('/widgets/preview/', false);

    // Open: the modal iframe targets the in-app preview route for the
    // CURRENTLY-SELECTED widget, on the demo host.
    $component = Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->call('openPreview')
        ->assertSet('showPreview', true)
        ->assertSeeHtml('/widgets/preview/'.$widget->public_id);
    $first = $component->get('previewNonce');

    // Re-opening busts the src — a fresh load every time, never stale.
    $component->set('showPreview', false)
        ->call('openPreview')
        ->assertSet('showPreview', true);
    expect($component->get('previewNonce'))->not->toBe($first)->not->toBe('');

    // The popup's target renders as before (in-app, guest-reachable)…
    $html = $this->get($host.'/widgets/preview/'.$widget->public_id)
        ->assertOk()
        ->assertSee($salon->name)
        ->getContent();

    // …with tenant-scoped endpoints and no slug-host leak anywhere.
    expect($html)->toContain('demo.'.config('app.domain').'\/api\/widget-preview\/availability');
    expect($html)->not->toContain($salon->slug);
});

it('opens the preview popup for a real salon onto its own subdomain route', function () {
    $salon = bookingSalon();
    $owner = salonOwnerOf($salon);
    $widget = $salon->defaultWidget();

    $this->actingAs($owner)
        ->get(route('salon.widgets', $salon))
        ->assertOk()
        ->assertSee('openPreview', false)
        ->assertDontSee('/widgets/preview/', false);

    $html = Livewire::actingAs($owner)
        ->test('pages::salon.widgets', ['salon' => $salon])
        ->call('openPreview')
        ->assertSet('showPreview', true)
        ->assertSeeHtml('/widgets/preview/'.$widget->public_id)
        ->html();

    // In-app host only — never the public widget host ({slug}./widget/…).
    expect($html)->not->toContain('/widget/'.$widget->public_id.'"');
});

it('books through the demo preview inertly — a real demo booking, no GHL, no mail', function () {
    config(['booking_api.widget_min_seconds' => 0]);
    $salon = demoShowcase();

    Queue::fake();
    Mail::fake();

    $host = 'http://demo.'.config('app.domain');
    $html = $this->get($host.'/widgets/preview/'.$salon->defaultWidget()->public_id)->assertOk()->getContent();
    preg_match('/var TOKEN = "([^"]+)"/', $html, $m);
    $token = json_decode('"'.$m[1].'"');

    $service = $salon->services()->whereHas('stylists')->firstOrFail();
    $slot = null;
    foreach (range(1, 14) as $ahead) {
        $date = now($salon->timezone)->addDays($ahead)->format('Y-m-d');
        $data = $this->getJson($host.'/api/widget-preview/availability?service='.$service->id.'&date='.$date)->json();
        if (($data['slots'] ?? []) !== []) {
            $slot = ['date' => $date, 'slot' => $data['slots'][0]];

            break;
        }
    }
    expect($slot)->not->toBeNull('no open preview slot found in 14 days');

    $before = $salon->bookings()->count();
    $this->postJson($host.'/api/widget-preview/book', [
        'service' => $service->id,
        'stylist' => (string) $slot['slot']['stylist_id'],
        'date' => $slot['date'],
        'time' => $slot['slot']['time'],
        'client' => ['name' => 'Preview Guest', 'phone' => '555-0100', 'email' => 'guest@gmail.com'],
        'token' => $token,
        'website' => '',
    ])->assertCreated();

    expect($salon->bookings()->count())->toBe($before + 1);
    Queue::assertNothingPushed();
    Mail::assertNothingOutgoing();
});

it('renders the preview for a real salon and never persists its final submit', function () {
    config(['booking_api.widget_min_seconds' => 0]);
    $salon = bookingSalon();
    $stylist = stylistWithHours($salon, 0, 9 * 60, 17 * 60);
    $service = serviceFor($salon, $stylist, 60);
    $owner = salonOwnerOf($salon);

    $host = 'http://'.$salon->slug.'.'.config('app.domain');

    $html = $this->actingAs($owner)
        ->get($host.'/widgets/preview/'.$salon->defaultWidget()->public_id)
        ->assertOk()
        ->getContent();
    preg_match('/var TOKEN = "([^"]+)"/', $html, $m);
    $token = json_decode('"'.$m[1].'"');

    $date = now($salon->timezone)->next(CarbonInterface::MONDAY)->format('Y-m-d');
    $before = $salon->bookings()->count();

    $this->actingAs($owner)->postJson($host.'/api/widget-preview/book', [
        'service' => $service->id,
        'stylist' => (string) $stylist->id,
        'date' => $date,
        'time' => '10:00',
        'client' => ['name' => 'Owner Poking', 'phone' => '555-0101'],
        'token' => $token,
        'website' => '',
    ])
        ->assertCreated()
        ->assertJson(['success' => true, 'preview' => true]);

    expect($salon->bookings()->count())->toBe($before);

    $this->get($host.'/widget/'.$salon->defaultWidget()->public_id)->assertOk();
});

it('still refuses the preview routes to guests and non-members on REAL salons', function () {
    $salon = bookingSalon();
    $host = 'http://'.$salon->slug.'.'.config('app.domain');

    $this->get($host.'/widgets/preview')->assertRedirect();

    app()->forgetInstance('currentSalon');
    $other = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($other))->get($host.'/widgets/preview')->assertForbidden();
});

it('exposes demo context through DemoMode helpers consistently', function () {
    $salon = demoShowcase();

    $this->get('http://demo.'.config('app.domain').'/')->assertOk();
    expect(DemoMode::inDemoContext())->toBeTrue();
    expect(DemoMode::showcaseSalon()?->id)->toBe($salon->id);

    app()->forgetInstance('currentSalon');
    expect(DemoMode::inDemoContext())->toBeFalse();
});
