<?php

use App\Actions\Staff\InviteStaff;
use App\Mail\AccountCreatedMail;
use App\Mail\SalonAddedMail;
use App\Mail\StaffInviteMail;
use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Services\Calendar\CalendarFeedService;
use App\Support\AppHost;
use Illuminate\Support\Facades\Mail;

/*
| Host-split regression guard (the production password-reset 404): APP_URL is
| the APEX, but auth/account/machine surfaces live on other hosts. Every URL
| that leaves the app in an email — or is shown for copy-paste — must target
| the host that actually serves it. These tests exist because the bug is
| invisible until a real user clicks a real email.
*/

function emailHostOf(string $url): string
{
    return (string) parse_url($url, PHP_URL_HOST);
}

// ---------------------------------------------------------------------------
// The bug itself: password reset must land on app.{domain}
// ---------------------------------------------------------------------------

it('builds the password-reset email link on app.{domain}, never the apex', function () {
    $user = User::factory()->create();

    $mail = (new ResetPasswordNotification('test-token'))->toMail($user);
    $url = $mail->actionUrl;

    expect(emailHostOf($url))->toBe('app.'.config('app.domain'));
    expect(emailHostOf($url))->not->toBe(config('app.domain'));
    expect($url)->toContain('/reset-password/test-token');

    // And the link resolves to a REAL route — the apex 404s it.
    $this->get($url)->assertOk();
    $this->get('http://'.config('app.domain').'/reset-password/test-token?email='.$user->email)
        ->assertNotFound();
});

it('reproduces production faithfully: apex APP_URL still yields an app-host reset link', function () {
    // Production config: APP_URL is the apex. The link must STILL be app.
    config(['app.url' => 'https://bookthestyle.com']);
    $user = User::factory()->create();

    $url = (new ResetPasswordNotification('tok'))->toMail($user)->actionUrl;

    // The route's own domain wins — never APP_URL.
    expect(emailHostOf($url))->toBe('app.'.config('app.domain'));
});

// ---------------------------------------------------------------------------
// Every other email link, per mailable
// ---------------------------------------------------------------------------

it('targets the app host in every login link a mailable carries', function () {
    Mail::fake();
    $salon = Salon::factory()->create();
    app(InviteStaff::class)->handle(salonOwnerOf($salon), $salon, [
        'name' => 'Nina New', 'email' => 'nina@example.com',
        'salon_role' => 'stylist', 'staff_type' => 'stylist',
    ]);

    Mail::assertQueued(StaffInviteMail::class, function ($mail) {
        return emailHostOf($mail->loginUrl) === 'app.'.config('app.domain');
    });
    Mail::assertQueued(AccountCreatedMail::class, function ($mail) {
        return emailHostOf($mail->loginUrl) === 'app.'.config('app.domain');
    });
});

it('targets the salon tenant host in the salon-created email links', function () {
    $agency = Agency::factory()->create();
    $salon = Salon::factory()->for($agency)->create(['slug' => 'host-check']);

    $html = (new SalonAddedMail('Ava', $salon, $agency->name))->render();

    // The salon link and the setup-wizard button both live on {slug}.{domain}.
    expect($html)->toContain('://host-check.'.config('app.domain'));
    expect($html)->not->toContain('https://host-check.'.config('app.domain').'.'); // no mangling
});

// ---------------------------------------------------------------------------
// Copy-paste + machine URLs, re-proven per host now the class is known
// ---------------------------------------------------------------------------

it('keeps every copy-paste and machine URL on its correct host', function () {
    $domain = config('app.domain');
    $salon = Salon::factory()->create(['slug' => 'hostmap']);

    // app.{domain}: auth, webhook, voice API, calendar feed, widget script.
    foreach ([
        route('login'),
        route('password.request'),
        route('webhooks.ghl'),
        route('api.booking.availability'),
        route('api.booking.create'),
        app(CalendarFeedService::class)->subscribeUrl('tok'),
        route('widget.script'),
        AppHost::app('webhooks/ghl'),
        AppHost::app('api/v1/booking/availability'),
    ] as $url) {
        expect(emailHostOf($url))->toBe('app.'.$domain, $url);
    }

    // {slug}.{domain}: the tenant app, its widget page, the setup wizard.
    foreach ([
        route('salon.show', $salon),
        route('salon.widget', $salon),
        route('salon.onboarding', $salon),
        AppHost::salon('hostmap'),
    ] as $url) {
        expect(emailHostOf($url))->toBe('hostmap.'.$domain, $url);
    }

    // Apex: marketing only (url() root is APP_URL by design).
    expect(emailHostOf(route('home')))->toBe($domain);
});

it('derives AppHost from config — scheme/port follow APP_URL, host follows APP_DOMAIN', function () {
    // Local dev shape.
    config(['app.url' => 'http://lvh.me:8000', 'app.domain' => 'lvh.me']);
    expect(AppHost::app('webhooks/ghl'))->toBe('http://app.lvh.me:8000/webhooks/ghl');

    // Production shape: apex APP_URL, https, no port.
    config(['app.url' => 'https://bookthestyle.com', 'app.domain' => 'bookthestyle.com']);
    expect(AppHost::app('api/v1/booking/availability'))
        ->toBe('https://app.bookthestyle.com/api/v1/booking/availability');
    expect(AppHost::salon('glow'))->toBe('https://glow.bookthestyle.com');
});
