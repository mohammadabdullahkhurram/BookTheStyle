<?php

use App\Models\Salon;
use App\Services\Calendar\CalendarFeedService;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/*
| Cloudflare-fronted origin (client → Cloudflare → Hostinger): the real
| visitor IP must come from CF-Connecting-IP (spoof-proof through the edge),
| forwarded proto must drive isSecure()/URL generation, per-IP rate limits
| must key off real visitors, and the machine-fetched surfaces (webhook,
| voice API, widget JSON, calendar feed) must behave through the proxy.
| Local dev on plain-http lvh.me must stay untouched.
*/

/** Simulated edge headers: Cloudflare connecting on behalf of $clientIp. */
function cloudflareHeaders(string $clientIp, array $extra = []): array
{
    return array_merge([
        'CF-Connecting-IP' => $clientIp,
        // Leftmost XFF hop is CLIENT-supplied — an attacker's spoof attempt.
        'X-Forwarded-For' => '198.51.100.99, '.$clientIp,
        'X-Forwarded-Proto' => 'https',
    ], $extra);
}

// ---------------------------------------------------------------------------
// Real client IP + HTTPS detection
// ---------------------------------------------------------------------------

it('resolves the real visitor IP behind Cloudflare — CF-Connecting-IP beats spoofable XFF', function () {
    $this->get('/up', cloudflareHeaders('203.0.113.7'))->assertOk();

    expect(request()->ip())->toBe('203.0.113.7');
    expect(request()->isSecure())->toBeTrue();
});

it('rejects a malformed CF-Connecting-IP instead of adopting it', function () {
    $this->get('/up', [
        'CF-Connecting-IP' => 'not-an-ip"><script>',
        'X-Forwarded-Proto' => 'https',
    ])->assertOk();

    expect(request()->ip())->toBe('127.0.0.1');
});

it('still honours plain X-Forwarded-For on the Cloudflare-less Hostinger path', function () {
    $this->get('/up', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();

    expect(request()->ip())->toBe('203.0.113.9');
});

// ---------------------------------------------------------------------------
// Per-IP rate limits key off the REAL visitor
// ---------------------------------------------------------------------------

it('keys the public rate limiters per real visitor — two clients behind Cloudflare are never conflated', function () {
    $keysByLimiter = [];

    foreach (['203.0.113.7', '198.51.100.23'] as $clientIp) {
        $this->get('/up', cloudflareHeaders($clientIp))->assertOk();

        foreach (['widget-api', 'calendar-feed', 'ghl-webhook', 'booking-api'] as $limiter) {
            $limit = call_user_func(RateLimiter::limiter($limiter), request());
            $keysByLimiter[$limiter][] = $limit->key;
        }
    }

    foreach ($keysByLimiter as $limiter => $keys) {
        expect($keys[0])->not->toBe($keys[1], "limiter [{$limiter}] conflated two clients");
        expect($keys[0])->toContain('203.0.113.7');
        expect($keys[1])->toContain('198.51.100.23');
    }
});

it('keys the login limiter off the real visitor too', function () {
    $this->get('/up', cloudflareHeaders('203.0.113.7'))->assertOk();
    request()->merge(['email' => 'someone@example.com']);

    $limit = call_user_func(RateLimiter::limiter('login'), request());

    expect($limit->key)->toContain('203.0.113.7');
});

// ---------------------------------------------------------------------------
// HTTPS URL generation (the copy-paste URLs must come out https)
// ---------------------------------------------------------------------------

it('generates https URLs during a proxied request — routes, assets, and the copy-paste integration URLs', function () {
    $this->get('/up', cloudflareHeaders('203.0.113.7'))->assertOk();

    expect(url('/'))->toStartWith('https://');
    expect(route('webhooks.ghl'))->toStartWith('https://')->toContain('app.'.config('app.domain'));
    expect(route('widget.script'))->toStartWith('https://');
    expect(route('api.booking.availability'))->toStartWith('https://');
    expect(app(CalendarFeedService::class)->subscribeUrl('token-x'))->toStartWith('https://');
});

it('signed URLs generated behind the proxy validate behind the proxy', function () {
    $this->get('/up', cloudflareHeaders('203.0.113.7'))->assertOk();

    $signed = URL::signedRoute('webhooks.ghl');
    expect($signed)->toStartWith('https://');

    // A later proxied request for that URL sees the same scheme + host, so
    // the signature holds.
    $incoming = Request::create($signed, 'GET', server: [
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
    ]);

    expect($incoming->hasValidSignature())->toBeTrue();
});

// ---------------------------------------------------------------------------
// The machine-fetched surfaces work through the proxy
// ---------------------------------------------------------------------------

it('serves the calendar feed through the proxy', function () {
    $salon = Salon::factory()->create();
    $stylist = stylistOf($salon);
    $service = app(CalendarFeedService::class);
    $url = $service->subscribeUrl($service->regenerate($stylist));

    $this->get($url, cloudflareHeaders('203.0.113.7'))
        ->assertOk()
        ->assertSee('BEGIN:VCALENDAR');
});

it('keeps the widget embeddable cross-site through the proxy', function () {
    $salon = Salon::factory()->create();
    $salon->defaultWidget();

    $response = $this->get(route('salon.widget', $salon), cloudflareHeaders('203.0.113.7'));

    $response->assertOk();
    // Cross-site framing allowed on this one surface: CSP says any ancestor,
    // and X-Frame-Options (which cannot express "allow all") is absent.
    expect((string) $response->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');
    expect($response->headers->has('X-Frame-Options'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Optional proxy pinning (TRUSTED_PROXIES=<Cloudflare ranges>)
// ---------------------------------------------------------------------------

it('pinned TRUSTED_PROXIES stop honouring forwarded headers from unlisted sources', function () {
    try {
        // What AppServiceProvider does when TRUSTED_PROXIES lists ranges.
        TrustProxies::at(['198.51.100.0/24']);

        // The test connection (127.0.0.1) is not in the pinned range, so its
        // forwarded headers are attacker-controlled noise — ignored.
        $this->get('/up', ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])->assertOk();

        expect(request()->ip())->toBe('127.0.0.1');
        expect(request()->isSecure())->toBeFalse();
    } finally {
        // Middleware statics outlive the per-test app — never leak the pin.
        TrustProxies::flushState();
    }
});

it('wires the pin from config so it survives config:cache (env() goes empty there)', function () {
    $provider = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

    expect($provider)->toContain("config('app.trusted_proxies'")
        ->and($provider)->toContain('TrustProxies::at(');
    expect((string) file_get_contents(base_path('bootstrap/app.php')))->not->toContain("env('TRUSTED_PROXIES'");
    expect((string) config('app.trusted_proxies'))->toBe('*');
});

// ---------------------------------------------------------------------------
// Local dev unaffected
// ---------------------------------------------------------------------------

it('leaves plain-http local dev exactly as it was', function () {
    $this->get('/up')->assertOk();

    expect(request()->ip())->toBe('127.0.0.1');
    expect(request()->isSecure())->toBeFalse();
    expect(url('/'))->toStartWith('http://');
});
