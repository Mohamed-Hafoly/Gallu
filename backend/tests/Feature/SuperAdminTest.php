<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

// seedRoles() and superAdmin() live in tests/Pest.php, shared with UserCrudTest.
beforeEach(fn () => seedRoles());

// Only the per-team roles are spatie's. Super-admin is global and lives in the
// users.is_super_admin column, so seeding it would advertise a role nothing
// ever assigns.
it('seeds only the per-team roles, as global rows reusable by every team', function () {
    expect(Role::count())->toBe(2);

    foreach ([RoleName::Admin, RoleName::Member] as $role) {
        $model = Role::where('name', $role->value)->first();

        expect($model)->not->toBeNull();
        expect($model->team_id)->toBeNull();
    }

    expect(Role::where('name', RoleName::SuperAdmin->value)->exists())->toBeFalse();
});

it('does not duplicate roles when seeded twice', function () {
    seed(RoleSeeder::class);

    expect(Role::count())->toBe(2);
});

it('promotes an existing user to super admin', function () {
    $user = User::factory()->create();

    expect($user->is_super_admin)->toBeFalse();

    artisan('app:promote-super-admin', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->fresh()->is_super_admin)->toBeTrue();
});

it('is idempotent when promoting the same user twice', function () {
    $user = User::factory()->create();

    artisan('app:promote-super-admin', ['email' => $user->email])->assertSuccessful();
    artisan('app:promote-super-admin', ['email' => $user->email])->assertSuccessful();

    expect($user->fresh()->is_super_admin)->toBeTrue();
});

it('fails for an unknown email and promotes nobody', function () {
    artisan('app:promote-super-admin', ['email' => 'nobody@example.com'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});

// The flag is not fillable, so registration and the profile update cannot
// smuggle it in. This is the privilege-escalation guard.
it('refuses to mass-assign the super admin flag', function () {
    $user = User::create([
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'is_super_admin' => true,
    ]);

    expect($user->fresh()->is_super_admin)->toBeFalse();
});

it('grants a super admin an ability that has no gate or policy defined', function () {
    expect(superAdmin()->can('anything-at-all'))->toBeTrue();
});

it('denies a normal user that same undefined ability', function () {
    expect(User::factory()->create()->can('anything-at-all'))->toBeFalse();
});

// Regression guard for the documented caveat: Gate::before must return null
// rather than false for non-super-admins, or it overrides every policy.
it('lets a normal user still pass a gate that allows them', function () {
    Gate::define('some-ability', fn (User $user) => true);

    expect(User::factory()->create()->can('some-ability'))->toBeTrue();
});

it('overrides a gate that explicitly denies, for a super admin', function () {
    Gate::define('some-ability', fn (User $user) => false);

    expect(superAdmin()->can('some-ability'))->toBeTrue();
});

// The whole point of the column over a spatie role: a role assignment resolves
// only inside the team it was made in, so the answer would change with ambient
// context. A column cannot.
it('grants a super admin whatever team context is ambient', function () {
    $user = superAdmin();

    setPermissionsTeamId(99);

    expect($user->can('anything-at-all'))->toBeTrue();

    setPermissionsTeamId(null);
});

it('presents a super admin and a plain user under different role names', function () {
    expect(superAdmin()->role())->toBe(RoleName::SuperAdmin)
        ->and(User::factory()->create()->role())->toBe(RoleName::Member);
});
