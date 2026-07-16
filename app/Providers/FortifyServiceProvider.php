<?php

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth lives on app.{domain}; after logout send the (now guest) user to
        // the public marketing landing on the apex rather than back to app./ —
        // which would just bounce them to the login screen. route('home') is a
        // server-generated apex URL, so there is no open-redirect surface.
        $this->app->instance(LogoutResponse::class, new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                return redirect()->route('home');
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthDiagnostics();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
        // Credential check that logs WHICH check failed (see AuthenticateUser).
        Fortify::authenticateUsing(new AuthenticateUser);
    }

    /**
     * Log lines for the login outcomes the credential check itself can't see:
     * throttling (rejected before credentials run) and successful logins that
     * are about to hit a wall (temporary password pending, no salon access).
     */
    private function configureAuthDiagnostics(): void
    {
        Event::listen(function (Lockout $event) {
            AuthLog::warn('throttled', (string) $event->request->input(Fortify::username()));
        });

        Event::listen(function (Login $event) {
            $user = $event->user;

            if (! $user instanceof User) {
                return;
            }

            if ($user->must_change_password) {
                // Signed in on a temporary password — every page will bounce
                // to the forced-change screen until they set their own.
                AuthLog::warn('must_change_password_pending', $user->email, $user);
            } elseif (! $user->hasAnySalonAccess()) {
                // Valid session, but nothing to reach: the dashboard shows an
                // empty state. The log is the breadcrumb for "I'm in but see
                // nothing" support calls.
                AuthLog::warn('no_salon_access', $user->email, $user);
            }
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        // No registerView — public registration is disabled (see config/fortify.php).
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return Limit::perMinute(10)->by(
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
