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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // Salon-scoped area. ResolveSalon enforces membership for {salon} before
    // anything inside renders — the request-level tenant-isolation boundary.
    Route::middleware('resolve.salon')->group(function () {
        Route::get('salons/{salon}', function () {
            return view('salon.show', ['salon' => app('currentSalon')]);
        })->name('salon.show');
    });
});

require __DIR__.'/settings.php';
