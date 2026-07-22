<?php

namespace App\Providers;

use App\Models\Agency;
use App\Models\Salon;
use App\Models\User;
use App\Policies\AgencyPolicy;
use App\Policies\SalonPolicy;
use App\Support\Notifications\MailTemporaryPasswordChannel;
use App\Support\Notifications\TemporaryPasswordChannel;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The one app-sent message (temp passwords) goes through a swappable
        // channel. Default delivers via Laravel Mail (log driver locally);
        // Phase 6 can rebind this to a GHL-routed channel without touching
        // any call site. See App\Support\Notifications\TemporaryPasswordChannel.
        $this->app->bind(
            TemporaryPasswordChannel::class,
            MailTemporaryPasswordChannel::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // RUNTIME demo-mail guard (belt to the per-action braces): demo
        // accounts live only on reserved non-routable domains; any message
        // addressed to one is cancelled before a transport ever sees it.
        Event::listen(function (MessageSending $event): ?bool {
            foreach ($event->message->getTo() as $address) {
                $to = strtolower($address->getAddress());
                if (str_ends_with($to, '.invalid') || str_ends_with($to, '@demo.test') || str_ends_with($to, '.test')) {
                    return false;
                }
            }

            return null;
        });

        // Frozen clock for the launch-video capture harness: LOCAL ONLY.
        // The capture server runs with APP_FAKE_NOW pinned to the
        // LaunchSalonSeeder anchor so every screenshot shows the same
        // "today". Any other environment ignores the variable entirely.
        if ($this->app->environment('local') && is_string(config('app.fake_now')) && config('app.fake_now') !== '') {
            Date::setTestNow(Date::parse(config('app.fake_now')));
            CarbonImmutable::setTestNow(CarbonImmutable::parse(config('app.fake_now')));
        }

        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
    }

    /**
     * Rate limiters. Calendar clients poll the ICS feed periodically, so it gets
     * a generous-but-bounded per-IP limit to absorb polling while blocking abuse
     * / token-guessing floods.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('calendar-feed', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('ghl-webhook', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Booking API: per token (hashed — never the raw token as a cache
        // key), falling back to per-IP for unauthenticated probes.
        RateLimiter::for('booking-api', function (Request $request) {
            $bearer = $request->bearerToken();

            return Limit::perMinute((int) config('booking_api.rate_limit'))
                ->by($bearer !== null ? hash('sha256', $bearer) : 'ip:'.$request->ip());
        });

        // Public booking widget: no credential exists in a visitor's browser,
        // so the key is IP + salon host (one hot salon can't starve others
        // behind a shared IP, and one IP can't hammer every salon).
        RateLimiter::for('widget-api', function (Request $request) {
            return Limit::perMinute((int) config('booking_api.widget_rate_limit'))
                ->by($request->ip().'|'.$request->getHost());
        });
    }

    /**
     * Wire the salon policy and a small set of named gates. These are the
     * server-side enforcement hooks the role-based features hang off in later
     * phases; defining them now keeps every check in one place.
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Salon::class, SalonPolicy::class);
        Gate::policy(Agency::class, AgencyPolicy::class);

        // Convenience gate aliases that delegate to the policy (which applies
        // the privileged-agency `before` check). Use whichever reads clearer.
        Gate::define('manage-salon', fn (User $user, Salon $salon) => $user->can('manage', $salon));
        Gate::define('view-master-calendar', fn (User $user, Salon $salon) => $user->can('viewMasterCalendar', $salon));
        Gate::define('connect-ghl', fn (User $user, Salon $salon) => $user->can('connectGhl', $salon));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Production sits behind Hostinger's TLS-terminating proxy: even if a
        // forwarded-proto header goes missing, every generated URL (routes,
        // signed URLs, the webhook/widget/voice/calendar URLs shown in the
        // UI) must be https. Local dev stays on plain http.
        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        // TRUSTED_PROXIES pins the proxies whose X-Forwarded-* we honour
        // (e.g. Cloudflare's published ranges) instead of bootstrap's '*'
        // default. Applied here — after config loads, config:cache-safe —
        // via the middleware's static override; boot() runs before any
        // request is handled.
        $trustedProxies = (string) config('app.trusted_proxies', '*');
        if ($trustedProxies !== '' && $trustedProxies !== '*') {
            TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))));
        }

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
