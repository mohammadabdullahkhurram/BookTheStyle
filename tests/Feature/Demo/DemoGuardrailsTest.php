<?php

use App\Models\Salon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
| Demo UX guardrails: surfaces that are meaningless or leaky inside the demo
| are guarded at the ROUTE/ACTION level (hiding nav is cosmetic, never the
| guard), while everything kept visible stays read-only with an honest note.
| Real salons keep full edit access everywhere — every block here asserts
| both directions.
*/

function enterDemoSalon($test): Salon
{
    $test->get('http://app.'.config('app.domain').'/demo')->assertRedirect();

    return Salon::query()
        ->whereKey(session('demo_salon_id'))
        ->where('is_demo', true)
        ->firstOrFail();
}

// ---------------------------------------------------------------------------
// Item 1 — personal/account settings: gone in demo, untouched for real users
// ---------------------------------------------------------------------------

it('bounces demo visitors off every personal-settings route', function () {
    enterDemoSalon($this);

    $app = 'http://app.'.config('app.domain');

    $this->get($app.'/settings/profile')->assertRedirect(route('demo.enter'));
    $this->get($app.'/settings/security')->assertRedirect(route('demo.enter'));
    // The bare /settings redirect is inside the guarded group too.
    $this->get($app.'/settings')->assertRedirect(route('demo.enter'));
});

it('hides the personal-settings nav entries from demo visitors', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.show', $salon))
        ->assertOk()
        ->assertDontSee(__('Account settings'));
});

it('keeps personal settings fully reachable for real users', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)->get(route('profile.edit'))->assertOk();
    $this->actingAs($owner)->get(route('security.edit'))->assertRedirect(); // password.confirm gate, not a demo bounce

    $this->actingAs($owner)
        ->get(route('salon.show', $salon))
        ->assertOk()
        ->assertSee(__('Account settings'));
});

// ---------------------------------------------------------------------------
// Item 3 — My calendar: the personal feed link cannot be generated in demo
// ---------------------------------------------------------------------------

it('blocks calendar-feed link generation in demo and hides the control', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.account', $salon))
        ->assertOk()
        ->assertSee(__('Calendar links are disabled in the demo.'))
        ->assertDontSee(__('Generate calendar link'));

    // The action itself is a no-op even when invoked directly.
    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('subscribeUrl', null)
        ->assertSet('connected', false);

    expect(auth()->user()->calendarConnection()->first()?->hasFeed())->not->toBeTrue();
});

it('keeps calendar-feed generation working for real users', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.account', $salon))
        ->assertOk()
        ->assertSee(__('Generate calendar link'));

    Livewire\Livewire::actingAs($owner)
        ->test('pages::settings.calendar-feed')
        ->call('generate')
        ->assertSet('connected', true);

    expect($owner->fresh()->calendarConnection()->first()?->hasFeed())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Item 2 — salon settings: plumbing removed, the showcase read-only
// ---------------------------------------------------------------------------

it('removes the integrations plumbing from demo salon settings entirely', function () {
    $salon = enterDemoSalon($this);

    $this->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertDontSee(__('Integrations'))
        ->assertDontSee(__('Voice AI booking API'))
        ->assertDontSee(__('Load from GoHighLevel'));

    // The gate itself denies — every GHL action authorizes against it.
    expect(auth()->user()->can('manageGhlConnection', $salon))->toBeFalse();

    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.settings', ['salon' => $salon])
        ->call('saveGhlConnection')
        ->assertForbidden();
});

it('keeps the showcase settings visible but read-only in demo', function () {
    $salon = enterDemoSalon($this);
    $before = $salon->accentColor();

    $this->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Branding'))
        ->assertSee(__('Play with the accent and themes freely — saving is disabled in the demo.'));

    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#123456')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect($salon->fresh()->accentColor())->toBe($before);

    // Hours and staff pages render with the note; their writes are no-ops.
    $this->get(route('salon.availability', $salon))
        ->assertOk()
        ->assertSee(__('Browse every schedule freely — editing hours is disabled in the demo.'));
    $this->get(route('salon.users', $salon))
        ->assertOk()
        ->assertSee(__('Browse the team setup freely — staff changes are disabled in the demo.'));

    $staffBefore = $salon->memberships()->count();
    Livewire\Livewire::actingAs(auth()->user())
        ->test('pages::salon.users.index', ['salon' => $salon])
        ->call('invite')
        ->assertHasNoErrors();
    expect($salon->memberships()->count())->toBe($staffBefore);
});

it('keeps every salon setting fully editable for real salons', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    $this->actingAs($owner)
        ->get(route('salon.settings', $salon))
        ->assertOk()
        ->assertSee(__('Integrations'))
        ->assertDontSee(__('saving is disabled in the demo'));

    expect($owner->can('manageGhlConnection', $salon))->toBeTrue();

    Livewire\Livewire::actingAs($owner)
        ->test('pages::salon.settings', ['salon' => $salon])
        ->set('accent', '#123456')
        ->call('saveBranding')
        ->assertHasNoErrors();

    expect($salon->fresh()->accentColor())->toBe('#123456');
});

// ---------------------------------------------------------------------------
// Item 4 — the inline widget preview, in BOTH contexts
// ---------------------------------------------------------------------------

it('renders the inline widget preview for a demo salon from the session tenant', function () {
    $salon = enterDemoSalon($this);

    $host = 'http://demo.'.config('app.domain');
    $widget = $salon->defaultWidget();

    // The widgets page embeds the preview iframe, never the public host.
    $this->get($host.'/widgets')
        ->assertOk()
        ->assertSee($host.'/widgets/preview/'.$widget->public_id, false);

    // The preview route itself renders the real widget page for the SESSION
    // salon — on the demo host, where the public widget structurally 404s.
    $html = $this->get($host.'/widgets/preview/'.$widget->public_id)
        ->assertOk()
        ->assertSee($salon->name)
        ->getContent();

    // Its API endpoints stay on the demo host (the tenant-scoped twins).
    // @json escapes slashes, so match the JSON-encoded form.
    expect($html)->toContain('demo.'.config('app.domain').'\/api\/widget-preview\/availability');
    // And nothing points at the salon's raw (unroutable) slug host.
    expect($html)->not->toContain($salon->slug);
});

it('books through the demo preview inertly — a real demo booking, no GHL, no mail', function () {
    config(['booking_api.widget_min_seconds' => 0]);
    $salon = enterDemoSalon($this);

    Queue::fake();
    Mail::fake();

    $host = 'http://demo.'.config('app.domain');
    $html = $this->get($host.'/widgets/preview/'.$salon->defaultWidget()->public_id)->assertOk()->getContent();
    preg_match('/var TOKEN = "([^"]+)"/', $html, $m);
    $token = json_decode('"'.$m[1].'"');

    // Find a genuinely open slot through the preview availability twin.
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
        ->assertJson(['success' => true, 'preview' => true])
        ->assertJsonPath('message', __('Preview only — no booking was created.'));

    expect($salon->bookings()->count())->toBe($before);

    // The public widget page keeps working for real salons, untouched.
    $this->get($host.'/widget/'.$salon->defaultWidget()->public_id)->assertOk();
});

it('refuses the preview routes to guests and non-members', function () {
    $salon = bookingSalon();
    $host = 'http://'.$salon->slug.'.'.config('app.domain');

    // Guest → login (the preview is an in-app surface, not a public one).
    $this->get($host.'/widgets/preview')->assertRedirect();

    // A member of ANOTHER salon → 403 from ResolveSalon.
    $other = Salon::factory()->create();
    $this->actingAs(salonOwnerOf($other))->get($host.'/widgets/preview')->assertForbidden();
});

it('never flags real accounts as demo accounts', function () {
    $salon = Salon::factory()->create();
    $owner = salonOwnerOf($salon);

    expect($owner->isDemoAccount())->toBeFalse();

    $demo = enterDemoSalon($this);
    expect(auth()->user()->isDemoAccount())->toBeTrue();
    expect($demo->is_demo)->toBeTrue();
});
