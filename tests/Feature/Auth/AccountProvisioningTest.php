<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
| Phase 1 auth cleanups: no public registration, and email verification must
| never block an admin-provisioned account from logging in.
*/

it('keeps public registration unreachable', function () {
    expect(Route::has('register'))->toBeFalse();
    $this->get('/register')->assertNotFound();
});

it('no longer enables email verification', function () {
    expect(Features::enabled(Features::emailVerification()))->toBeFalse();
    expect(Route::has('verification.notice'))->toBeFalse();
});

it('does not block an unverified user from the app', function () {
    $user = User::factory()->unverified()->create();

    // No `verified` middleware anywhere → the dashboard is reachable.
    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
