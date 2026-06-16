<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
| BookTheStyle has no public self-registration. Staff are provisioned by
| admins. These tests assert the registration surface is genuinely gone.
*/

it('does not enable the Fortify registration feature', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

it('has no register route registered', function () {
    expect(Route::has('register'))->toBeFalse();
    expect(Route::has('register.store'))->toBeFalse();
});

it('returns 404 for the registration URLs', function () {
    $this->get('/register')->assertNotFound();

    $this->post('/register', [
        'name' => 'Intruder',
        'email' => 'intruder@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});

it('does not link to registration from the login page', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up')
        ->assertDontSee(route('home').'/register');
});
