<?php

use Illuminate\Support\Facades\Route;

// Account settings are central — pinned to the apex domain alongside auth and
// the agency console (a salon subdomain's "/settings" is the salon settings).
Route::domain(config('app.domain'))->middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware(['password.confirm'])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
