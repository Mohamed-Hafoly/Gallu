<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Reset the role state Spatie caches per process, then seed the roles.
 *
 * The registrar memoises lookups for the life of the process, so without the
 * forget a role seeded in one test can answer in the next. Call from a
 * beforeEach in any file that needs roles.
 */
function seedRoles(): void
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId(User::GLOBAL_TEAM_ID);
    seed(RoleSeeder::class);
}

/**
 * A global super-admin.
 *
 * The flag is a plain column, so no role seeding or team context is needed —
 * PromoteSuperAdminCommand has its own coverage in SuperAdminTest.
 */
function superAdmin(): User
{
    return User::factory()->superAdmin()->create();
}
