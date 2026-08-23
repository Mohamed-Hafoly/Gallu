<?php

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(fn () => seedRoles());

/**
 * How many team assignments a user holds. One is the invariant assignToTeam()
 * enforces; zero means they belong to no team.
 */
function assignmentCount(User $user): int
{
    return DB::table(config('permission.table_names')['model_has_roles'])
        ->where(config('permission.column_names')['model_morph_key'], $user->id)
        ->where('model_type', $user->getMorphClass())
        ->count();
}

/**
 * The body the sync endpoint takes: the whole desired membership.
 *
 * @param  User  ...$users  Defaults everyone to plain member; pass roles by
 *                          building the array inline where they matter.
 * @return array<string, mixed>
 */
function memberList(User ...$users): array
{
    return [
        'members' => array_map(
            fn (User $user) => ['user_id' => $user->id, 'role' => RoleName::Member->value],
            $users,
        ),
    ];
}

it('requires authentication for every member endpoint', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();

    getJson("/api/teams/{$team->id}/members")->assertUnauthorized();
    putJson("/api/teams/{$team->id}/members", memberList($user))->assertUnauthorized();
});

it('forbids a signed-in user who is not a super admin', function () {
    $actor = User::factory()->create();
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Member);

    actingAs($actor)->getJson("/api/teams/{$team->id}/members")->assertForbidden();
    actingAs($actor)->putJson("/api/teams/{$team->id}/members", memberList())->assertForbidden();

    expect(assignmentCount($member->fresh()))->toBe(1);
});

it('lists the members of a team with their in-team role', function () {
    $team = Team::factory()->create();

    $lead = User::factory()->create(['name' => 'Aaron Lead']);
    $lead->assignToTeam($team, RoleName::Admin);

    $rank = User::factory()->create(['name' => 'Zoe Member']);
    $rank->assignToTeam($team, RoleName::Member);

    // Another team's member must not leak into this listing.
    User::factory()->create()->assignToTeam(Team::factory()->create(), RoleName::Member);

    $data = actingAs(superAdmin())
        ->getJson("/api/teams/{$team->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->json('data');

    // Ordered by name, so the admin comes first.
    expect($data[0]['id'])->toBe($lead->id)
        ->and($data[0]['role'])->toBe(RoleName::Admin->value)
        ->and($data[0]['team']['id'])->toBe($team->id)
        ->and($data[1]['id'])->toBe($rank->id)
        ->and($data[1]['role'])->toBe(RoleName::Member->value);
});

it('cannot manage the members of a trashed team', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Member);
    $team->delete();

    $admin = superAdmin();

    actingAs($admin)->getJson("/api/teams/{$team->id}/members")->assertNotFound();
    actingAs($admin)->putJson("/api/teams/{$team->id}/members", memberList())->assertNotFound();

    // The assignment survives, so restoring the team brings the member back.
    expect(assignmentCount($member->fresh()))->toBe(1);
});

it('lists members without a query per row', function () {
    $team = Team::factory()->create();
    $admin = superAdmin();

    User::factory()->count(3)->create()->each(fn (User $user) => $user->assignToTeam($team, RoleName::Member));

    // Warm up first: the first request of a test also resolves config, routes
    // and the session, which would otherwise be counted as member queries.
    actingAs($admin)->getJson("/api/teams/{$team->id}/members")->assertOk();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    actingAs($admin)->getJson("/api/teams/{$team->id}/members")->assertOk();
    $baseline = $queries;

    User::factory()->count(20)->create()->each(fn (User $user) => $user->assignToTeam($team, RoleName::Member));

    $queries = 0;
    actingAs($admin)->getJson("/api/teams/{$team->id}/members")->assertOk()->assertJsonCount(23, 'data');

    expect($queries)->toBe($baseline);
});

it('creates a team with its starting membership', function () {
    $lead = User::factory()->create();
    $rank = User::factory()->create();

    $teamId = actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'description' => 'The platform team',
            'members' => [
                ['user_id' => $lead->id, 'role' => RoleName::Admin->value],
                ['user_id' => $rank->id, 'role' => RoleName::Member->value],
            ],
        ])
        ->assertCreated()
        // Counted on the way out, so the table row is right without a refetch.
        ->assertJsonPath('data.members_count', 2)
        ->json('data.id');

    expect(Team::find($teamId)->name)->toBe('Platform')
        ->and($lead->fresh()->role())->toBe(RoleName::Admin)
        ->and($rank->fresh()->role())->toBe(RoleName::Member)
        ->and($lead->fresh()->teamAssignment()['team_id'])->toBe($teamId)
        ->and($rank->fresh()->teamAssignment()['team_id'])->toBe($teamId);
});

it('creates a team with no members when the key is absent', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', teamPayload())
        ->assertCreated()
        ->assertJsonPath('data.members_count', 0);
});

it('moves a member off their previous team when creating', function () {
    $origin = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($origin, RoleName::Admin);

    $teamId = actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'members' => [['user_id' => $target->id, 'role' => RoleName::Member->value]],
        ])
        ->assertCreated()
        ->json('data.id');

    expect(assignmentCount($target->fresh()))->toBe(1)
        ->and($target->fresh()->teamAssignment()['team_id'])->toBe($teamId);
});

/**
 * Rejected by validation rather than by the controller, so nothing is written —
 * unlike the add-member endpoint, where the team already exists.
 */
it('rejects a super admin among the members, creating nothing', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'members' => [['user_id' => superAdmin()->id, 'role' => RoleName::Member->value]],
        ])
        ->assertJsonValidationErrorFor('members.0.user_id');

    $this->assertDatabaseMissing('teams', ['name' => 'Platform']);
});

it('rejects the same user twice, creating nothing', function () {
    $target = User::factory()->create();

    actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'members' => [
                ['user_id' => $target->id, 'role' => RoleName::Member->value],
                ['user_id' => $target->id, 'role' => RoleName::Admin->value],
            ],
        ])
        ->assertJsonValidationErrorFor('members.1.user_id');

    $this->assertDatabaseMissing('teams', ['name' => 'Platform']);
});

it('rejects a member role outside the in-team enum, creating nothing', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'members' => [
                ['user_id' => User::factory()->create()->id, 'role' => RoleName::SuperAdmin->value],
            ],
        ])
        ->assertJsonValidationErrorFor('members.0.role');

    $this->assertDatabaseMissing('teams', ['name' => 'Platform']);
});

it('rejects a member that does not exist, creating nothing', function () {
    actingAs(superAdmin())
        ->postJson('/api/teams', [
            'name' => 'Platform',
            'members' => [['user_id' => 999_999, 'role' => RoleName::Member->value]],
        ])
        ->assertJsonValidationErrorFor('members.0.user_id');

    $this->assertDatabaseMissing('teams', ['name' => 'Platform']);
});

it('adds a member the list did not previously contain', function () {
    $team = Team::factory()->create();
    $existing = User::factory()->create();
    $existing->assignToTeam($team, RoleName::Admin);
    $joiner = User::factory()->create();

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", [
            'members' => [
                ['user_id' => $existing->id, 'role' => RoleName::Admin->value],
                ['user_id' => $joiner->id, 'role' => RoleName::Member->value],
            ],
        ])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($joiner->fresh()->teamAssignment()['team_id'])->toBe($team->id)
        ->and($joiner->fresh()->role())->toBe(RoleName::Member)
        // Untouched members keep their role rather than being reset.
        ->and($existing->fresh()->role())->toBe(RoleName::Admin);
});

it('removes anyone the list leaves out', function () {
    $team = Team::factory()->create();
    $kept = User::factory()->create();
    $kept->assignToTeam($team, RoleName::Member);
    $dropped = User::factory()->create();
    $dropped->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", memberList($kept))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $kept->id);

    expect(assignmentCount($dropped->fresh()))->toBe(0)
        ->and($dropped->fresh()->teamAssignment())->toBe([]);
});

it('changes the role of an existing member', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Member);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", [
            'members' => [['user_id' => $member->id, 'role' => RoleName::Admin->value]],
        ])
        ->assertOk()
        ->assertJsonPath('data.0.role', RoleName::Admin->value);

    expect(assignmentCount($member->fresh()))->toBe(1)
        ->and($member->fresh()->role())->toBe(RoleName::Admin);
});

it('moves a member off the team they were in', function () {
    $origin = Team::factory()->create();
    $destination = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($origin, RoleName::Admin);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$destination->id}/members", memberList($target))
        ->assertOk();

    expect(assignmentCount($target->fresh()))->toBe(1)
        ->and($target->fresh()->teamAssignment()['team_id'])->toBe($destination->id);

    actingAs(superAdmin())
        ->getJson("/api/teams/{$origin->id}/members")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

/**
 * `present`, not `required`, on the members key — an empty list is a real
 * instruction, and `required` would reject it.
 */
it('empties the team when handed an empty list', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", ['members' => []])
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect(assignmentCount($member->fresh()))->toBe(0);
});

it('requires the members key at all', function () {
    $team = Team::factory()->create();

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", [])
        ->assertJsonValidationErrorFor('members');
});

it('leaves the team unchanged when the list matches what is already there', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", [
            'members' => [['user_id' => $member->id, 'role' => RoleName::Admin->value]],
        ])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(assignmentCount($member->fresh()))->toBe(1)
        ->and($member->fresh()->role())->toBe(RoleName::Admin);
});

// The payloads are closures, not arrays: a dataset is built at collection time,
// before the database exists, so the users they need cannot be created inline.
it('rejects an invalid sync without touching the existing membership', function (Closure $members, string $field) {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->putJson("/api/teams/{$team->id}/members", ['members' => $members()])
        ->assertJsonValidationErrorFor($field);

    // The whole request is refused, so the team is exactly as it was.
    expect(assignmentCount($member->fresh()))->toBe(1)
        ->and($member->fresh()->role())->toBe(RoleName::Admin);
})->with([
    'a super admin' => [
        fn () => [['user_id' => superAdmin()->id, 'role' => RoleName::Member->value]],
        'members.0.user_id',
    ],
    'a user that does not exist' => [
        fn () => [['user_id' => 999_999, 'role' => RoleName::Member->value]],
        'members.0.user_id',
    ],
    'the same user twice' => [
        function () {
            $duplicate = User::factory()->create();

            return [
                ['user_id' => $duplicate->id, 'role' => RoleName::Member->value],
                ['user_id' => $duplicate->id, 'role' => RoleName::Admin->value],
            ];
        },
        'members.1.user_id',
    ],
    'the global role' => [
        fn () => [[
            'user_id' => User::factory()->create()->id,
            'role' => RoleName::SuperAdmin->value,
        ]],
        'members.0.role',
    ],
]);
