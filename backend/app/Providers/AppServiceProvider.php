<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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
        // Lazy loads, missing attributes and discarded mass-assignment throw in
        // dev and CI, but degrade to a slow query in production rather than a
        // 500 for a real user.
        //
        // Two things this deliberately does NOT catch:  a forgotten eager load behind whenLoaded(), which
        // drops the field before any relation is touched, and Media Library's
        // getFirstMediaUrl(), which does not go through the guarded path.
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::before(function (User $user, string $ability): ?bool {
            // True-or-null only: returning false here would override every
            // policy in the app rather than just denying this one check.
            // A plain column, not a spatie role: super-admin is global, and
            // every spatie assignment is scoped to a single team.
            return $user->is_super_admin ? true : null;
        });
    }
}
