<?php

use App\Models\Salon;
use App\Models\SalonMembership;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

/*
| Every distinct login failure must (a) log its real reason so support can
| answer "why can't this person log in?" from one grep, and (b) tell the USER
| the truth exactly when it's safe: pre-auth failures stay generic (account
| enumeration), post-auth blocks are specific and actionable.
*/

/** @return Collection<int, MessageLogged> live-updating capture of "Auth: " log lines */
function captureAuthLogs(): Collection
{
    $logs = new Collection;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs) {
        if (str_starts_with($event->message, 'Auth: ')) {
            $logs->push($event);
        }
    });

    return $logs;
}

function logReasons(Collection $logs): array
{
    return $logs->map(fn (MessageLogged $e) => $e->context['reason'] ?? null)->all();
}

// ---------------------------------------------------------------------------
// Pre-auth: generic to the user, specific in the log
// ---------------------------------------------------------------------------

it('shows one indistinguishable message for unknown email and wrong password, but logs them apart', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create();

    // Unknown email.
    $this->post(route('login.store'), ['email' => 'ghost@example.com', 'password' => 'whatever-wrong'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    // Known email, wrong password — the EXACT same user-facing string.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'whatever-wrong'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);

    $this->assertGuest();

    // …while the log tells them apart, with lookup context.
    expect(logReasons($logs))->toBe(['user_not_found', 'bad_password']);
    expect($logs[0]->context['email'])->toBe('ghost@example.com');
    expect($logs[0]->context)->toHaveKeys(['ip', 'host', 'user_id']);
    expect($logs[1]->context['email'])->toBe($user->email);
    expect($logs[1]->context['user_id'])->toBe($user->id);
});

// ---------------------------------------------------------------------------
// Post-auth blocks: specific, actionable, logged
// ---------------------------------------------------------------------------

it('tells a deactivated user the truth, refuses the session, and logs account_inactive', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create(['password' => 'correct-horse-9']);
    SalonMembership::factory()->for($user)->for(Salon::factory()->create())->stylist()
        ->create(['active' => false]);

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-9']);

    // Password was RIGHT — enumeration is moot, so the message is honest
    // (never the credentials-mismatch gaslight) and offers a way forward.
    $response->assertSessionHasErrors('email');
    $message = session('errors')->first('email');
    expect($message)->toContain('deactivated');
    expect($message)->toContain('Contact your salon owner');
    expect($message)->not->toBe(trans('auth.failed'));

    $this->assertGuest();
    expect(logReasons($logs))->toBe(['account_inactive']);
});

it('lets a user with no salon access in to an honest empty state, and logs no_salon_access', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create(['password' => 'correct-horse-9']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-9']);
    $this->assertAuthenticatedAs($user);
    expect(logReasons($logs))->toBe(['no_salon_access']);

    // The way forward lives on the dashboard's empty state.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(__('No salons yet'))
        ->assertSee(__('You are not a member of any salon. An administrator will add you to one.'));
});

it('logs must_change_password_pending on temp-password login and _blocked when the forced screen rejects', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create(['password' => 'temp-pass-1234', 'must_change_password' => true]);
    SalonMembership::factory()->for($user)->for(Salon::factory()->create())->stylist()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'temp-pass-1234']);
    $this->assertAuthenticatedAs($user);
    expect(logReasons($logs))->toBe(['must_change_password_pending']);

    // The trap that locked out a production user: forced screen, temp
    // password no longer known. The message must name the escape route.
    $response = $this->put(route('password.change.update'), [
        'current_password' => 'not-what-they-were-emailed',
        'password' => 'their-own-choice-9',
        'password_confirmation' => 'their-own-choice-9',
    ]);

    $response->assertSessionHasErrors('current_password');
    $message = session('errors')->first('current_password');
    expect($message)->toContain('temporary password is incorrect');
    expect($message)->toContain('Forgot password');

    expect(logReasons($logs))->toBe(['must_change_password_pending', 'must_change_password_blocked']);
    expect($logs[1]->context['email'])->toBe($user->email);
});

// ---------------------------------------------------------------------------
// Throttling: told clearly, logged
// ---------------------------------------------------------------------------

it('tells a throttled user how long to wait, and logs throttled', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-'.$i]);
    }

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-6'])
        ->assertSessionHasErrors('email');

    $message = session('errors')->first('email');
    expect($message)->toContain('Too many login attempts');
    expect($message)->toMatch('/\d+/'); // says how long, in seconds

    expect(logReasons($logs))->toBe([
        'bad_password', 'bad_password', 'bad_password', 'bad_password', 'bad_password',
        'throttled',
    ]);
});

// ---------------------------------------------------------------------------
// Hygiene: a clean login logs nothing; no password ever reaches a log
// ---------------------------------------------------------------------------

it('logs nothing for a healthy login', function () {
    $logs = captureAuthLogs();
    $user = User::factory()->create(['password' => 'correct-horse-9']);
    SalonMembership::factory()->for($user)->for(Salon::factory()->create())->stylist()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-9']);
    $this->assertAuthenticatedAs($user);

    expect($logs)->toBeEmpty();
});

it('never lets a password reach the log, on any path', function () {
    $all = new Collection;
    Event::listen(MessageLogged::class, fn (MessageLogged $e) => $all->push($e));

    $secret = 'S3cr3t-Unique-Pa55word';
    $user = User::factory()->create(['password' => $secret, 'must_change_password' => true]);
    SalonMembership::factory()->for($user)->for(Salon::factory()->create())->stylist()
        ->create(['active' => false]);

    // Wrong password, unknown email, correct-password-but-blocked, forced-
    // change rejection — every logging path, fed the secret.
    $this->post(route('login.store'), ['email' => 'ghost@example.com', 'password' => $secret]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-'.$secret]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => $secret]);

    expect($all)->not->toBeEmpty();
    foreach ($all as $entry) {
        $line = $entry->message.' '.json_encode($entry->context);
        expect($line)->not->toContain($secret);
    }
});
