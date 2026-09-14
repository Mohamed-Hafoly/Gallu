<?php

use App\Enums\RoleName;
use App\Http\Middleware\SetPermissionsTeam;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRoles());

/** Run the middleware against a request resolving to the given user. */
function runTeamMiddleware(?User $user): void
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    (new SetPermissionsTeam)->handle($request, fn () => response('ok'));
}

/**
 * A probe on the same stack the real endpoints use. No endpoint reads the
 * ambient team yet, so there is nothing else to assert it through.
 */
function registerTeamProbe(): void
{
    Route::middleware(['auth:sanctum', SetPermissionsTeam::class])
        ->get('/api/test-team-context', fn () => [
            'team_id' => getPermissionsTeamId(),
            'is_team_admin' => request()->user()->hasRole(RoleName::Admin),
        ]);
}

it('sets the ambient team to the user own team', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignToTeam($team, RoleName::Admin);

    runTeamMiddleware($user->fresh());

    expect(getPermissionsTeamId())->toBe($team->id);
});

// Null rather than a sentinel: it matches no pivot row on a read, and makes an
// assignRole() without a team fail loudly against the NOT NULL column instead of
// writing an orphan row that belongs to no team.
it('sets a null ambient team for a user in no team', function () {
    runTeamMiddleware(User::factory()->create());

    expect(getPermissionsTeamId())->toBeNull();
});

it('sets a null ambient team when nobody is authenticated', function () {
    runTeamMiddleware(null);

    expect(getPermissionsTeamId())->toBeNull();
});

// teamAssignment() excludes soft-deleted teams, so the ambient context follows.
it('sets a null ambient team while the user team is trashed', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignToTeam($team, RoleName::Admin);
    $team->delete();

    runTeamMiddleware($user->fresh());

    expect(getPermissionsTeamId())->toBeNull();
});

// The payoff, and the thing that silently returned false before the middleware
// existed: spatie's own APIs now answer for the team the user is actually in.
it('resolves hasRole for a team admin inside a real request', function () {
    registerTeamProbe();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignToTeam($team, RoleName::Admin);

    actingAs($user->fresh())
        ->getJson('/api/test-team-context')
        ->assertOk()
        ->assertJsonPath('team_id', $team->id)
        ->assertJsonPath('is_team_admin', true);
});

it('does not report a plain member as a team admin', function () {
    registerTeamProbe();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $user->assignToTeam($team, RoleName::Member);

    actingAs($user->fresh())
        ->getJson('/api/test-team-context')
        ->assertOk()
        ->assertJsonPath('is_team_admin', false);
});

it('leaves an unauthenticated request out of the stack entirely', function () {
    registerTeamProbe();

    getJson('/api/test-team-context')->assertUnauthorized();
});

// Why null beats the old 0 sentinel: a role assigned with no ambient team is a
// bug, and it should surface rather than land in a row no team owns.
it('refuses to assign a role with no ambient team', function () {
    setPermissionsTeamId(null);
    $user = User::factory()->create();

    expect(fn () => $user->assignRole(RoleName::Member->value))
        ->toThrow(Exception::class);

    expect(DB::table('model_has_roles')->count())->toBe(0);
});

// assignToTeam() saves and restores the ambient id around its own switch, and
// that value being null is now normal rather than a sentinel.
it('restores whatever ambient team was set before an assignment', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $user = User::factory()->create();

    setPermissionsTeamId($first->id);
    $user->assignToTeam($second, RoleName::Member);

    expect(getPermissionsTeamId())->toBe($first->id);
});
