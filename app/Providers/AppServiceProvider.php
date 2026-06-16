<?php

namespace App\Providers;

use App\Models\Salon;
use App\Models\User;
use App\Policies\SalonPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Wire the salon policy and a small set of named gates. These are the
     * server-side enforcement hooks the role-based features hang off in later
     * phases; defining them now keeps every check in one place.
     */
    protected function configureAuthorization(): void
    {
        Gate::policy(Salon::class, SalonPolicy::class);

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
