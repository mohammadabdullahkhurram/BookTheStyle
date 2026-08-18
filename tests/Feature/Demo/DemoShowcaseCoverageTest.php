<?php

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Diagnostics\ConnectionDiagnostics;
use App\Support\DemoMode;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

/*
| The demo showcase keeps pace with the shipped app: the guest can SEE every
| recent surface — the reshaped front-desk Check-in, both widget types
| (booking + the conversational chat bubble) with their in-app previews —
| while every mutation stays inert and agency-only surfaces (Health check,
| Voice AI prompts, diagnostics) never render or answer for the guest.
*/

function demoHostUrl(string $path = ''): string
{
    return 'http://demo.'.config('app.domain').$path;
}

it('shows the reshaped front-desk Check-in to the guest: every state, Check out present, no reschedule', function () {
    demoShowcase();

    $this->get(demoHostUrl('/appointments'))
        ->assertOk()
        // The seeded day covers the whole visit-state flow…
        ->assertSee(__('Completed'))
        ->assertSee(__('Checked in'))
        ->assertSee(__('Check out'))
        ->assertSee(__('Check in'))
        ->assertSee(__('No-show'))
        ->assertSee(__('Undo no-show'))
        // …and the tab offers no future-scheduling.
        ->assertDontSee(__('Reschedule'));
});

it('keeps the check-out flow inert for the demo: the popup opens, completing changes nothing', function () {
    $salon = demoShowcase();
    $arrived = $salon->bookings()->where('status', BookingStatus::Arrived)->firstOrFail();
    $viewer = salonOwnerOf($salon); // stand-in for the request-scoped demo viewer

    Livewire::actingAs($viewer)
        ->test('pages::salon.appointments.index', ['salon' => $salon])
        ->call('openCheckout', $arrived->id)
        ->assertSet('showCheckout', true)
        ->assertSee(__('In-app payments are coming soon'))
        ->call('completeVisit')
        ->assertHasNoErrors();

    // DemoMode::blocksWrite kept the visit exactly where it was.
    expect($arrived->refresh()->status)->toBe(BookingStatus::Arrived);
});

it('seeds BOTH widget types and lists them for the guest — chat bubble included', function () {
    $salon = demoShowcase();

    expect($salon->widgets()->where('type', 'chat')->exists())->toBeTrue();

    $this->get(demoHostUrl('/widgets'))
        ->assertOk()
        ->assertSee('Website booking form')
        ->assertSee('Website chat bubble')
        ->assertSee(__('Chat widget'));
});

it('renders the chat panel preview for the guest, and a preview booking stays an inert demo booking', function () {
    $salon = demoShowcase();
    $chat = $salon->widgets()->where('type', 'chat')->firstOrFail();

    $this->get(demoHostUrl('/widgets/chat-preview/'.$chat->public_id))
        ->assertOk()
        ->assertSee(__('Booking assistant'))
        ->assertSee(__('Book an appointment'));

    // The preview book endpoint commits an ORDINARY inert demo booking —
    // isolated to the showcase, cleared by the nightly reset, no GHL/mail.
    $service = $salon->services()->where('active', true)->firstOrFail();
    $slot = now($salon->timezone)->addDays(30)->setTime(10, 0); // beyond seeded traffic

    $before = $salon->bookings()->count();
    $response = $this->postJson(demoHostUrl('/api/widget-preview/book'), [
        'service' => $service->id,
        'stylist' => 'any',
        'date' => $slot->format('Y-m-d'),
        'time' => $slot->format('g:i A'),
        'client' => ['name' => 'Demo Visitor', 'phone' => '+1 555 010 7777'],
        'token' => Crypt::encryptString((string) json_encode(['salon' => $salon->id, 'iat' => now()->timestamp - 30])),
        'website' => '',
        'surface' => 'chat',
    ]);

    if ($response->status() === 201) {
        $booking = Booking::withoutGlobalScopes()->where('salon_id', $salon->id)->latest('id')->first();
        expect($salon->bookings()->count())->toBe($before + 1);
        expect($booking->source)->toBe(BookingSource::ChatWidget);
        expect($booking->salon->is_demo)->toBeTrue(); // never a real salon
    } else {
        // A closed/full seeded day is acceptable — but the request must have
        // been REFUSED by the engine, never an error.
        $response->assertStatus(409);
    }
});

it('never leaks agency-only surfaces to the guest: no Health check tab, no Voice AI tab, diagnostics refused', function () {
    $salon = demoShowcase();

    $this->get(demoHostUrl('/settings'))
        ->assertOk()
        ->assertDontSee("pick('health')", escape: false)
        ->assertDontSee(__('Run health check'))
        ->assertDontSee(__('Voice AI Prompts'));

    // The old health-check URL redirects to Settings — where the guest
    // simply has no tab; and the component itself refuses demo mounts.
    $this->get(demoHostUrl('/settings/check-connections'))->assertRedirect('/settings#health');
    // The component refuses a direct mount on the showcase (the demo
    // viewer is a salon role → policy 403; even a demo agency operator
    // would hit the is_demo 404).
    Livewire::actingAs(salonOwnerOf($salon))
        ->test('pages::salon.check-connections', ['salon' => $salon])
        ->assertForbidden();

    // No diagnostics test records exist in the showcase at all.
    expect($salon->clients()->where('name', ConnectionDiagnostics::CLIENT_NAME)->exists())->toBeFalse();
});

it('keeps the nightly reset rebuilding the full showcase — widgets and the state-rich day included', function () {
    demoShowcase();

    $this->artisan('demo:reset-showcase')->assertExitCode(0);

    $salon = DemoMode::showcaseSalon();
    expect($salon->widgets()->pluck('type')->sort()->values()->all())->toBe(['booking', 'chat']);
    expect($salon->bookings()->where('status', BookingStatus::NoShow)->exists())->toBeTrue();
    expect($salon->bookings()->where('status', BookingStatus::Arrived)->exists())->toBeTrue();
});
