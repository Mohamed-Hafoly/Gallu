<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
});

// Regression: `team` was built with whenNotNull()'s second argument, which is
// the fallback for a null value rather than a transformer. Laravel invokes it
// with no arguments, so every team-less user 500'd on serialization — which is
// every user until teams are assigned, including straight after login.
it('serializes a user who belongs to no team', function () {
    $user = User::factory()->create();

    expect($user->team())->toBeNull();

    $response = actingAs($user)->getJson('/api/user');

    $response->assertOk();
    $response->assertJsonPath('data.id', $user->id);
    // Present and null, not absent: whenNotNull() drops the key entirely, which
    // left the SPA with a field it had to guard rather than a stable shape.
    $response->assertJsonPath('data.team', null);
});

it('serializes a user who belongs to a team as an id and name', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    setPermissionsTeamId($team->id);
    $user->assignRole(RoleName::Member->value);
    $user->unsetRelation('roles');
    setPermissionsTeamId(null);

    $response = actingAs($user)->getJson('/api/user');

    $response->assertOk();
    $response->assertJsonPath('data.team', [
        'id' => $team->id,
        'name' => $team->name,
    ]);
});
