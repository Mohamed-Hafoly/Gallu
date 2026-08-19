<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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
        // Default context for requests that aren't team-scoped. Once team
        // middleware exists it overrides this per request; until then it is
        // what lets the super-admin's no-team assignment resolve at all.
        setPermissionsTeamId(User::GLOBAL_TEAM_ID);

        Gate::before(function (User $user, string $ability): ?bool {
            // True-or-null only: returning false here would override every
            // policy in the app rather than just denying this one check.
            // A plain column, not a spatie role: super-admin is global, and
            // every spatie assignment is scoped to a single team.
            return $user->is_super_admin ? true : null;
        });
    }
}
