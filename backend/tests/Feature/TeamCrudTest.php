<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRoles());

/**
 * What the create dialog posts.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function teamPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Design',
        'description' => 'The design team',
    ], $overrides);
}

it('requires authentication for every team endpoint', function () {
    $team = Team::factory()->create();

    getJson('/api/teams')->assertUnauthorized();
    getJson('/api/teams/picker')->assertUnauthorized();
    postJson('/api/teams', teamPayload())->assertUnauthorized();
    patchJson("/api/teams/{$team->id}", teamPayload())->assertUnauthorized();
    deleteJson("/api/teams/{$team->id}")->assertUnauthorized();
    postJson("/api/teams/{$team->id}/restore")->assertUnauthorized();
});

it('forbids a signed-in user who is not a super admin', function () {
    $actor = User::factory()->create();
    $team = Team::factory()->create();

    actingAs($actor)->getJson('/api/teams')->assertForbidden();
    actingAs($actor)->getJson('/api/teams/picker')->assertForbidden();
    actingAs($actor)->postJson('/api/teams', teamPayload())->assertForbidden();
    actingAs($actor)->patchJson("/api/teams/{$team->id}", teamPayload())->assertForbidden();
    actingAs($actor)->deleteJson("/api/teams/{$team->id}")->assertForbidden();

    $this->assertDatabaseMissing('teams', ['name' => 'Design']);
});

it('creates a team and reports the authenticated user as its creator', function () {
    $admin = User::factory()->superAdmin()->create(['name' => 'Ada Lovelace']);

    actingAs($admin)
        ->postJson('/api/teams', teamPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Design')
        ->assertJsonPath('data.description', 'The design team')
        ->assertJsonPath('data.creator', 'Ada Lovelace')
        ->assertJsonPath('data.members_count', 0)
        ->assertJsonPath('data.deleted_at', null);

    $this->assertDatabaseHas('teams', ['name' => 'Design', 'user_id' => $admin->id]);
});

it('accepts a team without a description', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload(['description' => null]))
        ->assertCreated()
        ->assertJsonPath('data.description', null);
});

it('requires a name', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload(['name' => null]))
        ->assertJsonValidationErrorFor('name');
});

it('rejects a name longer than 40 characters', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload(['name' => str_repeat('a', 41)]))
        ->assertJsonValidationErrorFor('name');
});

it('rejects a duplicate name', function () {
    Team::factory()->create(['name' => 'Design']);

    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload())
        ->assertJsonValidationErrorFor('name');
});

// The unique index ignores deleted_at, so validation has to as well — see
// StoreTeamRequest. Without this the write would fail on the index instead.
it('rejects a name that a trashed team still holds', function () {
    Team::factory()->create(['name' => 'Design'])->delete();

    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload())
        ->assertJsonValidationErrorFor('name');
});

it('renames a team', function () {
    $team = Team::factory()->create(['name' => 'Design']);

    actingAs(superAdmin())
        ->patchJson("/api/teams/{$team->id}", teamPayload(['name' => 'Product']))
        ->assertOk()
        ->assertJsonPath('data.name', 'Product');

    $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Product']);
});

it('lets a team keep its own name while updating', function () {
    $team = Team::factory()->create(['name' => 'Design']);

    actingAs(superAdmin())
        ->patchJson("/api/teams/{$team->id}", teamPayload(['description' => 'Changed']))
        ->assertOk()
        ->assertJsonPath('data.description', 'Changed');
});

it('rejects renaming onto another team, trashed or not', function (bool $trashed) {
    $other = Team::factory()->create(['name' => 'Taken']);
    $team = Team::factory()->create(['name' => 'Design']);

    if ($trashed) {
        $other->delete();
    }

    actingAs(superAdmin())
        ->patchJson("/api/teams/{$team->id}", teamPayload(['name' => 'Taken']))
        ->assertJsonValidationErrorFor('name');
})->with([
    'live team' => [false],
    'trashed team' => [true],
]);

it('soft deletes a team', function () {
    $team = Team::factory()->create();

    actingAs(superAdmin())
        ->deleteJson("/api/teams/{$team->id}")
        ->assertNoContent();

    $this->assertSoftDeleted($team);
});

it('returns 404 when updating or deleting an already trashed team', function () {
    $team = Team::factory()->create();
    $team->delete();

    actingAs(superAdmin())->patchJson("/api/teams/{$team->id}", teamPayload())->assertNotFound();
    actingAs(superAdmin())->deleteJson("/api/teams/{$team->id}")->assertNotFound();
});

it('restores a trashed team and frees its name again', function () {
    $team = Team::factory()->create(['name' => 'Design']);
    $team->delete();

    actingAs(superAdmin())
        ->postJson("/api/teams/{$team->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $team->id)
        ->assertJsonPath('data.deleted_at', null);

    actingAs(superAdmin())->getJson('/api/teams')->assertJsonCount(1, 'data');
});

it('returns 404 when restoring a team that is not trashed', function () {
    $team = Team::factory()->create();

    actingAs(superAdmin())
        ->postJson("/api/teams/{$team->id}/restore")
        ->assertNotFound();
});

it('lists live and trashed teams together, for the screen to split', function () {
    Team::factory()->count(2)->create();
    Team::factory()->create()->delete();

    actingAs(superAdmin())
        ->getJson('/api/teams')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('exposes every field the admin table renders', function () {
    Team::factory()->create();

    $row = actingAs(superAdmin())->getJson('/api/teams')->assertOk()->json('data.0');

    expect($row)->toHaveKeys([
        'id', 'name', 'description', 'creator', 'members_count',
        'created_at', 'updated_at', 'deleted_at',
    ]);
});

it('still lists a team whose creator was deleted, with a null creator', function () {
    Team::factory()->creatorless()->create();

    actingAs(superAdmin())
        ->getJson('/api/teams')
        ->assertOk()
        ->assertJsonPath('data.0.creator', null);
});

it('counts a team members', function () {
    $team = Team::factory()->create();
    User::factory()->create()->assignToTeam($team, RoleName::Admin);
    User::factory()->create()->assignToTeam($team, RoleName::Member);

    actingAs(superAdmin())
        ->getJson('/api/teams')
        ->assertOk()
        ->assertJsonPath('data.0.members_count', 2);
});

it('serves only live teams to the picker', function () {
    Team::factory()->count(2)->create();
    Team::factory()->create()->delete();

    actingAs(superAdmin())
        ->getJson('/api/teams/picker')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('treats a padded name as a duplicate, since TrimStrings runs globally', function () {
    Team::factory()->create(['name' => 'Design']);

    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload(['name' => '  Design  ']))
        ->assertJsonValidationErrorFor('name');
});
