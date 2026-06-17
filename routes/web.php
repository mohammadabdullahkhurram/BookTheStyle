<?php

use App\Http\Controllers\Auth\PasswordChangeController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    // Forced first-login password change. Reachable even while flagged so the
    // user is never trapped by EnsurePasswordChanged.
    Route::get('password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::put('password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');
});

Route::middleware(['auth'])->group(function () {
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

    // Salon-scoped area. ResolveSalon enforces membership/operator reach for
    // {salon} before anything inside renders — the request-level tenant boundary.
    // Each screen then authorises the specific capability (manage staff/settings).
    Route::middleware('resolve.salon')->prefix('salons/{salon}')->group(function () {
        Route::get('/', fn () => view('salon.show', ['salon' => app('currentSalon')]))->name('salon.show');
        Route::livewire('clients', 'pages::salon.clients.index')->name('salon.clients');
        Route::livewire('staff', 'pages::salon.staff.index')->name('salon.staff');
        Route::livewire('services', 'pages::salon.services.index')->name('salon.services');
        Route::livewire('availability', 'pages::salon.availability.index')->name('salon.availability');
        Route::livewire('settings', 'pages::salon.settings')->name('salon.settings');
    });
});

require __DIR__.'/settings.php';
