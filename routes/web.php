<?php

use App\Http\Controllers\Api\VoiceBookingController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\GhlWebhookController;
use App\Http\Controllers\WidgetController;
use App\Http\Middleware\AuthenticateBookingApi;
use Illuminate\Support\Facades\Route;

$central = config('app.domain');     // apex, e.g. bookthestyle.com / lvh.me
$app = 'app.'.$central;              // the application
$register = 'register.'.$central;   // public "book a call"

/*
|--------------------------------------------------------------------------
| Apex  ({app.domain})  — public marketing
|--------------------------------------------------------------------------
| The landing page only. No auth, no tenant data. "Book a call" points at
| register.{domain}; "Log in" points at app.{domain}/login.
*/
Route::domain($central)->group(function () {
    Route::view('/', 'welcome')->name('home');
});

/*
|--------------------------------------------------------------------------
| register.{app.domain}  — public "book a call"
|--------------------------------------------------------------------------
| A clean page that hosts a GoHighLevel calendar iframe (added later). Public,
| no auth, no tenant data. Its CSP gets a configurable frame-src (SecurityHeaders
| + config('app.register_embed_frame_src')) so the embed will be permitted.
*/
Route::domain($register)->group(function () {
    Route::view('/', 'register')->name('book-call');
});

/*
|--------------------------------------------------------------------------
| app.{app.domain}  — the application
|--------------------------------------------------------------------------
| All central/auth surface: login/logout + forced password change (Fortify is
| pinned to this host via config('fortify.domain')), account settings (see
| settings.php), the agency console, and the salon picker/dashboard shell.
|
| Registered BEFORE the wildcard salon group so app.{domain}/ resolves here
| (not as a salon named "app"). Future system paths /cal (ICS feeds, Phase 5)
| and /webhooks (GHL inbound, Phase 6) will live on this host too — path-based
| and authenticated by their own token/signature, not the session.
*/
Route::domain($app)->middleware(['auth'])->group(function () {
    // app.{domain}/ → salon picker for an authenticated user; a guest is bounced
    // to login by the auth middleware (never to the marketing landing).
    Route::redirect('/', '/dashboard');

    // Forced first-login password change. Reachable even while flagged so the
    // user is never trapped by EnsurePasswordChanged.
    Route::get('password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::put('password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');

    // The salon picker / landing after login.
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Agency console (agency owners/admins). Each screen authorises against the
    // actor's own agency and rejects out-of-agency {salon}/{user} ids with 403.
    Route::prefix('agency')->name('agency.')->group(function () {
        Route::livewire('/', 'pages::agency.overview')->name('overview');
        Route::livewire('salons', 'pages::agency.salons.index')->name('salons.index');
        Route::livewire('salons/create', 'pages::agency.salons.create')->name('salons.create');
        Route::livewire('salons/{salon}/edit', 'pages::agency.salons.edit')->name('salons.edit');
        Route::livewire('users', 'pages::agency.users.index')->name('users.index');
        Route::livewire('users/create', 'pages::agency.users.create')->name('users.create');
        Route::livewire('users/{user}/edit', 'pages::agency.users.edit')->name('users.edit');
    });
});

/*
| Personal calendar ICS feeds (Phase 5) — GET /cal/{token}.ics on the
| application host. Public + token-authorized (no session; calendar clients
| fetch unauthenticated), and independent of salon-subdomain resolution. The
| token is hashed for lookup; an unknown/revoked token 404s without revealing
| validity. Rate-limited per IP. "cal" is a reserved slug, so no salon can
| shadow it.
*/
Route::domain($app)->middleware('throttle:calendar-feed')->group(function () {
    Route::get('cal/{token}.ics', CalendarFeedController::class)
        ->where('token', '[A-Fa-f0-9]+')
        ->name('cal.feed');
});

/*
| GHL inbound webhook (Phase 6c) — POST /webhooks/ghl on the application
| host. Sessionless + CSRF-exempt (see bootstrap/app.php); authenticated by
| the per-salon shared secret in X-Webhook-Secret, with the salon resolved
| from the payload's location id. "webhooks" is a reserved slug, so no salon
| can shadow it. Rate-limited per IP.
*/
Route::domain($app)->middleware('throttle:ghl-webhook')
    ->post('webhooks/ghl', GhlWebhookController::class)
    ->name('webhooks.ghl');

/*
| Voice-AI Booking API (Stage 2) — POST /api/v1/booking/* on the application
| host. Sessionless + CSRF-exempt (see bootstrap/app.php); authenticated by a
| per-salon bearer token (hashed at rest) that ALSO resolves the salon —
| nothing tenant-identifying is ever taken from the URL or body. Rate-limited
| per token. GHL Voice AI Custom Actions call these mid-conversation.
| "api" is a reserved slug, so no salon can shadow it.
*/
Route::domain($app)
    ->middleware(['throttle:booking-api', AuthenticateBookingApi::class])
    ->prefix('api/v1/booking')
    ->group(function () {
        Route::post('availability', [VoiceBookingController::class, 'availability'])->name('api.booking.availability');
        Route::post('create', [VoiceBookingController::class, 'create'])->name('api.booking.create');
    });

// Account settings live on app.{domain} too. Required before the wildcard salon
// group so app.{domain}/settings/* wins over a salon path.
require __DIR__.'/settings.php';

/*
|--------------------------------------------------------------------------
| Public booking widget  ({slug}.{app.domain}/widget)  — no auth
|--------------------------------------------------------------------------
| The embeddable client-facing booking surface. The page is loaded inside an
| iframe on external salon websites (SecurityHeaders relaxes frame-ancestors
| for THIS route only); its JSON endpoints live on the same salon subdomain,
| so the page calls them same-origin (no CORS surface). Everything resolves
| the ACTIVE salon from the slug inside the controller — no session, no
| membership, rate-limited per IP + salon, bot-gated on the book submit.
| The api/widget/* paths fall under the global api/* CSRF exemption.
| The loader script external sites embed is served from the app host below.
*/
Route::domain('{salon}.'.$central)->middleware('throttle:widget-api')->group(function () {
    Route::get('widget/{widget?}', [WidgetController::class, 'page'])->name('salon.widget');
    Route::prefix('api/widget')->group(function () {
        Route::get('services', [WidgetController::class, 'services'])->name('salon.widget.services');
        Route::get('availability', [WidgetController::class, 'availability'])->name('salon.widget.availability');
        Route::get('month', [WidgetController::class, 'month'])->name('salon.widget.month');
        Route::post('book', [WidgetController::class, 'book'])->name('salon.widget.book');
    });
});

Route::domain($app)->get('widget.js', [WidgetController::class, 'script'])->name('widget.script');

/*
|--------------------------------------------------------------------------
| Salon subdomain  ({slug}.{app.domain})  — tenants
|--------------------------------------------------------------------------
| Wildcard, so it is registered LAST: the explicit app./register. groups above
| take precedence on "/". The active salon is resolved from the subdomain slug;
| ResolveSalon enforces active status + membership (and rejects reserved slugs
| such as "app"/"register" as a safety net) before anything inside renders.
*/
Route::domain('{salon}.'.$central)->middleware(['auth', 'resolve.salon'])->group(function () {
    Route::livewire('/', 'pages::salon.dashboard')->name('salon.show');
    Route::livewire('calendar', 'pages::salon.calendar')->name('salon.calendar');
    Route::livewire('appointments', 'pages::salon.appointments.index')->name('salon.appointments');
    Route::livewire('appointments/all', 'pages::salon.appointments.all')->name('salon.appointments.all');
    Route::livewire('book', 'pages::salon.bookings.create')->name('salon.bookings.create');
    Route::livewire('clients', 'pages::salon.clients.index')->name('salon.clients');
    Route::livewire('clients/{clientId}', 'pages::salon.clients.show')->name('salon.client');
    Route::livewire('staff', 'pages::salon.staff.index')->name('salon.staff');
    Route::livewire('services', 'pages::salon.services.index')->name('salon.services');
    Route::livewire('availability', 'pages::salon.availability.index')->name('salon.availability');
    Route::livewire('reports', 'pages::salon.reports')->name('salon.reports');
    Route::livewire('settings', 'pages::salon.settings')->name('salon.settings');
    Route::livewire('widgets', 'pages::salon.widgets')->name('salon.widgets');
    // TEMPORARY: design-direction gallery (owner/admin) — removed once a
    // direction is chosen and rolled out app-wide.
    Route::livewire('ui-ux', 'pages::salon.uiux')->name('salon.uiux');
    Route::livewire('setup', 'pages::salon.onboarding')->name('salon.onboarding');
});
