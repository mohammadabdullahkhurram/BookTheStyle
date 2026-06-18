<?php

use Illuminate\Support\Facades\Route;

// Account settings live on the application subdomain (app.{domain}) alongside
// auth and the agency console (a salon subdomain's "/settings" is the salon
// settings). Pinned to app. so route('profile.edit') generates app. URLs.
Route::domain('app.'.config('app.domain'))->middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(['password.confirm'])
        ->name('security.edit');
});

// Passkey discovery endpoint — public (browsers fetch it before login), pinned
// to the application host where authentication happens.
Route::domain('app.'.config('app.domain'))->get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
