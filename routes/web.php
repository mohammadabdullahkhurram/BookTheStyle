<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use Illuminate\Support\Facades\Route;

$central = config('app.domain');

/*
|--------------------------------------------------------------------------
| Central / apex domain  ({app.domain})
|--------------------------------------------------------------------------
| Marketing, all authentication, account settings, and the agency console
| live on the apex. They are constrained to the apex host so they never
| resolve on a salon subdomain (where "/" is the salon dashboard instead).
*/
Route::domain($central)->group(function () {
    Route::view('/', 'welcome')->name('home');

    Route::middleware(['auth'])->group(function () {
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
});

/*
|--------------------------------------------------------------------------
| Salon subdomain  ({slug}.{app.domain})
|--------------------------------------------------------------------------
| The active salon is resolved from the subdomain slug (the {salon} domain
| parameter — bound to a Salon by its slug route key). ResolveSalon enforces
| active status + membership/operator reach before anything inside renders;
| this is the request-level tenant-isolation boundary. Each screen then
| authorises the specific capability (manage staff/settings/etc.).
*/
Route::domain('{salon}.'.$central)->middleware(['auth', 'resolve.salon'])->group(function () {
    Route::livewire('/', 'pages::salon.dashboard')->name('salon.show');
    Route::livewire('appointments', 'pages::salon.appointments.index')->name('salon.appointments');
    Route::livewire('book', 'pages::salon.bookings.create')->name('salon.bookings.create');
    Route::livewire('clients', 'pages::salon.clients.index')->name('salon.clients');
    Route::livewire('staff', 'pages::salon.staff.index')->name('salon.staff');
    Route::livewire('services', 'pages::salon.services.index')->name('salon.services');
    Route::livewire('availability', 'pages::salon.availability.index')->name('salon.availability');
    Route::livewire('settings', 'pages::salon.settings')->name('salon.settings');
});

require __DIR__.'/settings.php';
