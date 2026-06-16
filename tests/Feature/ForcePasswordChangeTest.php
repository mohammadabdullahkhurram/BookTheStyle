<?php

use App\Models\User;

/*
| A user provisioned with a temporary password (must_change_password = true)
| must be forced to set a new one before reaching anything else, and the flag
| must clear once they do.
*/

it('redirects a flagged user to the change-password screen', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('password.change'));
});

it('blocks a flagged user from a salon page too', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    // Even a salon-scoped URL bounces to the change screen first.
    $this->actingAs($user)
        ->get('/salons/1')
        ->assertRedirect(route('password.change'));
});

it('lets a flagged user reach the change-password screen itself', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->get(route('password.change'))
        ->assertOk();
});

it('clears the flag after the user sets a new password', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->put(route('password.change.update'), [
            'current_password' => 'password',
            'password' => 'fresh-secret-pass',
            'password_confirmation' => 'fresh-secret-pass',
        ])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('rejects a wrong temporary password', function () {
    $user = User::factory()->create(['must_change_password' => true]);

    $this->actingAs($user)
        ->put(route('password.change.update'), [
            'current_password' => 'not-the-temp',
            'password' => 'fresh-secret-pass',
            'password_confirmation' => 'fresh-secret-pass',
        ])
        ->assertSessionHasErrors('current_password');

    expect($user->fresh()->must_change_password)->toBeTrue();
});

it('does not redirect a normal user', function () {
    $user = User::factory()->create(); // must_change_password defaults to false

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});
