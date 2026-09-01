<?php

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRoles();
    Storage::fake('public');
});

/**
 * What the create dialog posts. Overrides let each case bend one field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Created User',
        'email' => 'created@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

it('requires authentication to list, update or delete users', function () {
    $user = User::factory()->create();

    getJson('/api/users')->assertUnauthorized();
    patchJson("/api/users/{$user->id}", ['name' => 'Nope'])->assertUnauthorized();
    deleteJson("/api/users/{$user->id}")->assertUnauthorized();
});

it('forbids a signed-in user who is not a super admin', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    actingAs($actor)->getJson('/api/users')->assertForbidden();
    actingAs($actor)
        ->patchJson("/api/users/{$target->id}", ['name' => 'Nope Nope', 'email' => $target->email])
        ->assertForbidden();
    actingAs($actor)->deleteJson("/api/users/{$target->id}")->assertForbidden();
});

it('pages the listing and reports the unfiltered total', function () {
    $admin = superAdmin();
    User::factory()->count(24)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=10&page=2')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.current_page', 2);
});

// The table always sends per_page, so this only pins what an ad-hoc request
// gets: Laravel's model default, since the controller passes 0 straight through.
it('falls back to the model per-page when none is asked for, ordered by id', function () {
    $admin = superAdmin();
    User::factory()->count(20)->create();

    actingAs($admin)
        ->getJson('/api/users')
        ->assertOk()
        ->assertJsonCount((new User)->getPerPage(), 'data')
        ->assertJsonPath('data.0.id', $admin->id);
});

it('sorts on every whitelisted column, in both directions', function (string $column) {
    $admin = superAdmin();

    // Staggered rather than count(4): factory rows all land in the same second,
    // and a tie on created_at/updated_at orders identically in both directions.
    foreach (range(1, 4) as $daysAgo) {
        User::factory()->create([
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    $ascending = actingAs($admin)
        ->getJson("/api/users?sort_by={$column}&sort_order=asc&per_page=100")
        ->assertOk()
        ->json('data.*.id');

    $descending = actingAs($admin)
        ->getJson("/api/users?sort_by={$column}&sort_order=desc&per_page=100")
        ->assertOk()
        ->json('data.*.id');

    expect($descending)->toBe(array_reverse($ascending));
})->with(['id', 'name', 'email', 'created_at', 'updated_at']);

it('rejects a sort column that is not whitelisted', function () {
    actingAs(superAdmin())
        ->getJson('/api/users?sort_by=password')
        ->assertJsonValidationErrorFor('sort_by');
});

it('rejects an unknown sort direction', function () {
    actingAs(superAdmin())
        ->getJson('/api/users?sort_order=sideways')
        ->assertJsonValidationErrorFor('sort_order');
});

it('rejects a per_page of zero, which would page nothing', function () {
    actingAs(superAdmin())
        ->getJson('/api/users?per_page=0')
        ->assertJsonValidationErrorFor('per_page');
});

it('rejects a per_page below the "All" sentinel', function () {
    actingAs(superAdmin())
        ->getJson('/api/users?per_page=-2')
        ->assertJsonValidationErrorFor('per_page');
});

// Vuetify's footer emits -1 for "All".
it('returns every row on one page when per_page is -1', function () {
    $admin = superAdmin();
    User::factory()->count(24)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1')
        ->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 1);
});

it('still honours the search when asked for every row', function () {
    $admin = superAdmin();
    User::factory()->create(['name' => 'Ada Lovelace']);
    User::factory()->count(5)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&search=Lovelace')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

it('searches on the name, the email and the team', function (string $term) {
    $admin = superAdmin();
    $ada = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    // Ada is the only member: TeamFactory fills the team's `user_id` with a
    // fresh user, and creating a team is not joining it, so that user must not
    // turn up in a team search.
    $ada->assignToTeam(Team::factory()->create(['name' => 'Analytical Engines']), RoleName::Member);

    User::factory()->count(5)->create();

    actingAs($admin)
        ->getJson('/api/users?search='.urlencode($term))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'ada@example.com')
        ->assertJsonPath('meta.total', 1);
})->with([
    'name fragment' => ['Lovelace'],
    'email fragment' => ['ada@example'],
    // Mid-string, so a missing trailing wildcard would fail this.
    'team fragment' => ['lytical Eng'],
]);

// teamAssignmentQuery() excludes trashed teams, so the search agrees with what
// the Team column shows and with what the team sort orders by: a binned team is
// no team at all.
it('does not match a user through a trashed teams name', function () {
    $admin = superAdmin();
    $team = Team::factory()->create(['name' => 'Analytical Engines']);
    User::factory()->create(['name' => 'Ada Lovelace'])->assignToTeam($team, RoleName::Member);

    $team->delete();

    actingAs($admin)
        ->getJson('/api/users?search=Analytical')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

// The grouped where is what keeps this true: an ungrouped OR would escape the
// search and hand back rows nobody asked for.
it('returns every member of a team the search names', function () {
    $admin = superAdmin();
    $team = Team::factory()->create(['name' => 'Analytical Engines']);

    $members = User::factory()->count(3)->create();
    foreach ($members as $member) {
        $member->assignToTeam($team, RoleName::Member);
    }

    User::factory()->count(4)->create();

    $ids = actingAs($admin)
        ->getJson('/api/users?search=Analytical&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    expect($ids)->toEqualCanonicalizing($members->pluck('id')->all());
});

it('treats an empty search as no search at all', function () {
    $admin = superAdmin();
    User::factory()->count(3)->create();

    actingAs($admin)
        ->getJson('/api/users?search=')
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
});

// Scout's database engine ORs on the primary key when the term is all digits
// and the key is one of the searchable columns, which is why User's
// toSearchableArray() lists `id`: the admin table shows the number, so typing
// it should land on that row.
it('matches a user by id', function () {
    // Spelled out rather than left to the factory, whose names and emails carry
    // digits of their own and would widen the result for the wrong reason.
    $admin = User::factory()->superAdmin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $target = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    actingAs($admin)
        ->getJson('/api/users?search='.$target->id)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id)
        ->assertJsonPath('meta.total', 1);
});

// Equality, not a LIKE: the engine drops the id column's LIKE once it decides
// the term is a key. Searching "1" with ids running past 9 is what tells the
// two apart - a wildcarded id would drag in 10 through 15 as well.
it('matches an id exactly rather than as a fragment', function () {
    // Digit-free throughout, or the name and email halves of the search would
    // match "1" on their own and the assertion would prove nothing.
    $admin = User::factory()->superAdmin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    foreach (range(1, 14) as $index) {
        User::factory()->create([
            'name' => 'Member '.str_repeat('x', $index),
            'email' => str_repeat('x', $index).'@example.com',
        ]);
    }

    $rows = actingAs($admin)
        ->getJson('/api/users?per_page=-1&search='.$admin->id)
        ->assertOk()
        ->json('data.*.id');

    expect($rows)->toBe([$admin->id]);
});

// The id clause is a no-op for a term that is not a number: the engine only
// treats it as a key when the whole term is digits, and `id LIKE '%ada%'`
// matches nothing.
it('leaves the id clause out of the way of a text search', function () {
    $admin = User::factory()->superAdmin()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    User::factory()->count(5)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&search=Lovelace')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $admin->id);
});

// The "All" page is built by hand from the result set, because Scout's
// paginate() has nowhere to put a pre-counted total. An empty result would make
// per_page 0 without the max() guard, which LengthAwarePaginator divides by.
it('reports an honest page when a per_page of -1 matches nothing', function () {
    $admin = superAdmin();
    User::factory()->count(3)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&search=nobody-by-that-name')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1);
});

it('reports the matched rows as the page size when per_page is -1', function () {
    $admin = superAdmin();
    User::factory()->count(4)->create();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1);
});

it('exposes the columns the admin table renders', function () {
    $admin = superAdmin();

    $row = actingAs($admin)->getJson('/api/users')->assertOk()->json('data.0');

    expect($row)->toHaveKeys([
        'id', 'name', 'email', 'role', 'team', 'avatar_thumb_url', 'created_at', 'updated_at', 'is_super_admin',
    ]);
});

it('flags the super admin and only the super admin', function () {
    $admin = superAdmin();
    User::factory()->create();

    $rows = collect(actingAs($admin)->getJson('/api/users')->json('data'))->keyBy('id');

    expect($rows[$admin->id]['is_super_admin'])->toBeTrue()
        ->and($rows->except($admin->id)->first()['is_super_admin'])->toBeFalse();
});

it('renames a user', function () {
    $admin = superAdmin();
    $target = User::factory()->create(['name' => 'Old Name']);

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['name' => 'New Name', 'email' => $target->email])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');

    $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'New Name']);
});

it('changes the email', function () {
    $admin = superAdmin();
    $target = User::factory()->create(['email' => 'old@example.com']);

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => 'new@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com');

    $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => 'new@example.com']);
});

it('rejects a missing or malformed email', function (?string $email) {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['name' => $target->name, 'email' => $email])
        ->assertJsonValidationErrorFor('email');
})->with([
    'missing' => [null],
    'no domain' => ['not-an-email'],
    'no local part' => ['@example.com'],
]);

it('rejects an email another user already holds', function () {
    $admin = superAdmin();
    User::factory()->create(['email' => 'taken@example.com']);
    $target = User::factory()->create(['email' => 'mine@example.com']);

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => 'taken@example.com',
        ])
        ->assertJsonValidationErrorFor('email');

    $this->assertDatabaseHas('users', ['id' => $target->id, 'email' => 'mine@example.com']);
});

// The ignore() path. Without the bound user excluded from the unique rule, a
// rename that leaves the address alone would 422 against the user's own row.
it('lets a user keep its own email while changing something else', function () {
    $admin = superAdmin();
    $target = User::factory()->create(['email' => 'mine@example.com', 'name' => 'Old Name']);

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'mine@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'mine@example.com');
});

it('leaves the role and avatar alone when only the email changes', function () {
    $admin = superAdmin();
    $target = User::factory()->superAdmin()->create(['email' => 'old@example.com']);
    $target->setAvatarFromFile(UploadedFile::fake()->image('me.jpg', 600, 600));

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => 'new@example.com',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@example.com')
        ->assertJsonPath('data.is_super_admin', true)
        ->assertJsonPath('data.has_avatar', true);
});

it('requires a name of at least four characters', function (?string $name) {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['name' => $name])
        ->assertJsonValidationErrorFor('name');
})->with([
    'missing' => [null],
    'too short' => ['Al'],
]);

// Multipart spoofing PATCH, which is exactly what stores/user.ts sends.
it('uploads an avatar with the update', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->post("/api/users/{$target->id}", [
            '_method' => 'PATCH',
            'name' => $target->name,
            'email' => $target->email,
            'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ])
        ->assertOk()
        ->assertJsonPath('data.has_avatar', true);

    expect($target->fresh()->getMedia(User::AVATAR_COLLECTION))->toHaveCount(1);
});

it('rejects a non-image avatar', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->post("/api/users/{$target->id}", [
            '_method' => 'PATCH',
            'name' => $target->name,
            'email' => $target->email,
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            // Multipart can't go through postJson(), so the Accept header is what
            // makes the failure come back as a 422 body instead of a redirect.
        ], ['Accept' => 'application/json'])
        ->assertJsonValidationErrorFor('avatar');
});

it('clears the avatar and falls back to the default image', function () {
    $admin = superAdmin();
    $target = User::factory()->create();
    $target->setAvatarFromFile(UploadedFile::fake()->image('me.jpg', 600, 600));

    actingAs($admin)
        ->post("/api/users/{$target->id}", [
            '_method' => 'PATCH',
            'name' => $target->name,
            'email' => $target->email,
            'remove_avatar' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('data.has_avatar', false)
        ->assertJsonPath('data.avatar_url', asset(User::DEFAULT_AVATAR_PATH));

    expect($target->fresh()->getMedia(User::AVATAR_COLLECTION))->toHaveCount(0);
});

// Users were the last entity still hard deleted, against req.txt's "Every entry
// should be soft deleted". The avatar survives on purpose: Media Library's own
// deleting hook returns early unless the model is being force deleted, so a
// restore brings the user back whole.
it('soft deletes a user, keeping their avatar for a restore', function () {
    $admin = superAdmin();
    $target = User::factory()->create();
    $target->setAvatarFromFile(UploadedFile::fake()->image('me.jpg', 600, 600));

    actingAs($admin)
        ->deleteJson("/api/users/{$target->id}")
        ->assertNoContent();

    $this->assertSoftDeleted('users', ['id' => $target->id]);

    // fresh() carries the global scope, so a binned user needs withTrashed().
    $binned = User::withTrashed()->find($target->id);
    expect($binned->getMedia(User::AVATAR_COLLECTION))->toHaveCount(1);
});

// Membership is the model_has_roles row, which the soft delete never touches -
// it is only hidden, because Team::members() is a morphedByMany onto User and
// so inherits the global scope. That is what makes this restore lossless.
it('restores a user with their team and role intact', function () {
    $admin = superAdmin();
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Admin);

    actingAs($admin)->deleteJson("/api/users/{$target->id}")->assertNoContent();

    actingAs($admin)
        ->postJson("/api/users/{$target->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $target->id)
        ->assertJsonPath('data.deleted_at', null);

    $restored = User::find($target->id);
    expect($restored)->not->toBeNull();
    expect($restored->teamAssignment()['team_id'] ?? null)->toBe($team->id);
    expect($restored->role())->toBe(RoleName::Admin);
});

it('refuses to restore a user who is not trashed', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)->postJson("/api/users/{$target->id}/restore")->assertNotFound();
});

it('refuses to let a plain user restore anyone', function () {
    $target = User::factory()->create();
    $target->delete();

    actingAs(User::factory()->create())
        ->postJson("/api/users/{$target->id}/restore")
        ->assertForbidden();
});

// EloquentUserProvider::retrieveById() goes through newModelQuery(), which
// applies global scopes - so this needs no code of its own, and a test because
// of that rather than in spite of it.
it('refuses to log a soft deleted user in', function () {
    $target = User::factory()->create(['email' => 'gone@example.com']);
    $target->delete();

    postJson('/api/login', ['email' => 'gone@example.com', 'password' => 'password'])
        ->assertStatus(422);
});

// Rule::unique is a raw query-builder check that ignores global scopes, and
// users_email_unique is absolute either way. Deliberate: the address stays
// reserved so a restore always returns the same user.
it('still treats a soft deleted users email as taken', function () {
    $admin = superAdmin();
    $target = User::factory()->create(['email' => 'gone@example.com']);
    $target->delete();

    actingAs($admin)
        ->postJson('/api/users', userPayload(['email' => 'gone@example.com']))
        ->assertJsonValidationErrorFor('email');
});

it('drops a soft deleted user from their teams member count', function () {
    $admin = superAdmin();
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Member);

    $count = fn () => actingAs($admin)->getJson('/api/teams')->json('data.0.members_count');

    expect($count())->toBe(1);

    $target->delete();

    expect($count())->toBe(0);
});

// The whole point of the design: a user is not a container for their content,
// the team is. Binning a member must not bin the documents and images their
// team still works with - only the authorship goes.
it('leaves a soft deleted users documents and images in place, with no creator', function () {
    $team = Team::factory()->create();
    $author = User::factory()->create(['name' => 'Ada Lovelace']);
    $author->assignToTeam($team, RoleName::Admin);

    $document = Document::factory()->for($author)->create(['team_id' => $team->id]);
    Image::factory()->for($author)->for($document)->create();

    $author->delete();

    $admin = superAdmin();

    actingAs($admin)
        ->getJson('/api/documents?per_page=-1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $document->id)
        ->assertJsonPath('data.0.creator', null);

    actingAs($admin)
        ->getJson('/api/images?per_page=-1')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.creator', null);
});

// Search has to agree with what the reader sees. The creator joins in
// newScoutQuery() filter binned authors out in their ON clause, so their name
// stops matching, while the row itself stays in the listing.
it('stops matching a soft deleted authors name in search', function (string $endpoint) {
    $team = Team::factory()->create();
    $author = User::factory()->create(['name' => 'Ada Lovelace']);
    $author->assignToTeam($team, RoleName::Admin);

    $document = Document::factory()->for($author)->create([
        'team_id' => $team->id,
        'title' => 'Alpha',
        'description' => null,
    ]);
    Image::factory()->for($author)->for($document)->create([
        'title' => 'Alpha',
        'description' => null,
    ]);

    $admin = superAdmin();

    // Findable by the author while they are live.
    actingAs($admin)->getJson($endpoint.'?per_page=-1&search=Lovelace')->assertJsonCount(1, 'data');

    $author->delete();

    actingAs($admin)->getJson($endpoint.'?per_page=-1&search=Lovelace')->assertJsonCount(0, 'data');
    // Still listed, just no longer attributable.
    actingAs($admin)->getJson($endpoint.'?per_page=-1')->assertJsonCount(1, 'data');
})->with(['/api/documents', '/api/images']);

it('serves the trashed side of the users listing only when asked', function () {
    $admin = superAdmin();
    $binned = User::factory()->create();
    $binned->delete();

    $ids = fn (string $query) => actingAs($admin)
        ->getJson('/api/users?per_page=-1&'.$query)
        ->assertOk()
        ->json('data.*.id');

    expect($ids(''))->toBe([$admin->id]);
    expect($ids('trashed=only'))->toBe([$binned->id]);
    expect($ids('trashed=with'))->toEqualCanonicalizing([$admin->id, $binned->id]);
});

// Regression, and the reason the team name is a join in newScoutQuery() rather
// than an engine callback. A callback appends at the top level, where the
// engine's own deleted_at test also lands - so the query read
// `(name OR email) OR EXISTS(team) AND deleted_at IS NULL` and the soft-delete
// test covered only the last branch. A binned user came back the moment their
// name was searched. Not caught by scope nesting: withoutTrashed() is a Builder
// macro, not a local scope.
it('keeps a binned user out of a searched live listing', function () {
    $admin = superAdmin();
    $binned = User::factory()->create(['name' => 'Ada Lovelace']);
    $binned->delete();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&search=Lovelace')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

// The same leak in the other direction, and the louder half: with the callback
// in place, `trashed=only` plus a term matching live users returned every one of
// them, so the bin filled with users who were never deleted.
it('keeps live users out of a searched trash listing', function () {
    $admin = superAdmin();
    User::factory()->count(3)->create(['name' => 'Ada Lovelace']);
    $binned = User::factory()->create(['name' => 'Ada Lovelace']);
    $binned->delete();

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&trashed=only&search=Lovelace')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $binned->id);
});

// The join must not multiply rows: nothing at the database level stops a user
// holding more than one role row, which a plain join onto the pivot would turn
// into duplicate listing rows.
it('returns one row per user when searching by team', function () {
    $admin = superAdmin();
    $team = Team::factory()->create(['name' => 'Analytical Engines']);
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Member);

    actingAs($admin)
        ->getJson('/api/users?per_page=-1&search='.urlencode('lytical Eng'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $member->id);
});

it('rejects a trashed mode outside the enum', function () {
    actingAs(superAdmin())
        ->getJson('/api/users?trashed=everything')
        ->assertJsonValidationErrorFor('trashed');
});

it('404s when updating or deleting a trashed user', function () {
    $admin = superAdmin();
    $target = User::factory()->create();
    $target->delete();

    actingAs($admin)->patchJson("/api/users/{$target->id}", ['name' => 'Ghost'])->assertNotFound();
    actingAs($admin)->deleteJson("/api/users/{$target->id}")->assertNotFound();
});

it('refuses to let a super admin delete themselves', function () {
    $admin = superAdmin();

    actingAs($admin)
        ->deleteJson("/api/users/{$admin->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

it('returns 404 for a user that does not exist', function () {
    actingAs(superAdmin())
        ->patchJson('/api/users/9999', ['name' => 'Ghost User'])
        ->assertNotFound();
});

it('reports the role each user is presented as', function () {
    $admin = superAdmin();
    $member = User::factory()->create();

    $rows = collect(actingAs($admin)->getJson('/api/users')->json('data'))->keyBy('id');

    expect($rows[$admin->id]['role'])->toBe(RoleName::SuperAdmin->value)
        ->and($rows[$member->id]['role'])->toBe(RoleName::Member->value);
});

// `team` is not a column either: it sorts on the select alias
// withTeamAssignment() adds, which holds the team's *name*.
it('sorts by team name, with the team less last', function () {
    $zulu = Team::factory()->create(['name' => 'Zulu']);
    $alpha = Team::factory()->create(['name' => 'Alpha']);

    $inZulu = User::factory()->create();
    $inAlpha = User::factory()->create();
    $inZulu->assignToTeam($zulu, RoleName::Member);
    $inAlpha->assignToTeam($alpha, RoleName::Member);

    // The super-admin belongs to no team, so it is the null row.
    $admin = superAdmin();

    $ascending = actingAs($admin)
        ->getJson('/api/users?sort_by=team&sort_order=asc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    // Relative positions rather than the whole array: TeamFactory creates a
    // user for each team's `user_id`, so the listing carries team-less rows
    // this test never asked for.
    $ascendingAt = array_flip($ascending);

    // Alpha before Zulu — by name, not by the ids, which run the other way —
    // and the team-less admin ahead of both, since null sorts first.
    expect($ascendingAt[$inAlpha->id])->toBeLessThan($ascendingAt[$inZulu->id])
        ->and($ascendingAt[$admin->id])->toBeLessThan($ascendingAt[$inAlpha->id]);

    $descending = actingAs($admin)
        ->getJson('/api/users?sort_by=team&sort_order=desc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    $descendingAt = array_flip($descending);

    expect($descendingAt[$inZulu->id])->toBeLessThan($descendingAt[$inAlpha->id])
        ->and($descendingAt[$inAlpha->id])->toBeLessThan($descendingAt[$admin->id]);
});

// A team name is shared by everyone in it, and LIMIT/OFFSET over a non-unique
// key lets the database order ties differently per page — so without the id
// tie-break the table repeats a row or skips one.
it('does not repeat a user across pages when the team ties', function () {
    $team = Team::factory()->create(['name' => 'Design']);

    foreach (User::factory()->count(3)->create() as $member) {
        $member->assignToTeam($team, RoleName::Member);
    }

    $admin = superAdmin();

    $ids = [];
    foreach ([1, 2, 3, 4] as $page) {
        $ids[] = actingAs($admin)
            ->getJson("/api/users?per_page=1&page={$page}&sort_by=team&sort_order=asc")
            ->assertOk()
            ->json('data.0.id');
    }

    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4);
});

// role is not a column on `users` — it resolves to the flag subquery, so it
// needs its own coverage rather than riding the whitelist dataset above.
it('sorts by role, putting super admins at one end', function () {
    $admin = superAdmin();
    User::factory()->count(4)->create();

    $ascending = actingAs($admin)
        ->getJson('/api/users?sort_by=role&sort_order=asc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    $descending = actingAs($admin)
        ->getJson('/api/users?sort_by=role&sort_order=desc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    // The column is 0 for plain users, so ascending ends on the admin and
    // descending starts with it.
    expect(end($ascending))->toBe($admin->id)
        ->and($descending[0])->toBe($admin->id);
});

// `media` is eager loaded so avatar_url does not go looking per row, and the
// role now comes off the row itself.
it('costs the same number of queries whatever the row count', function () {
    $admin = superAdmin();

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $measure = function () use ($admin, &$queries) {
        $queries = 0;
        actingAs($admin)->getJson('/api/users?per_page=-1')->assertOk();

        return $queries;
    };

    // Discarded: the first request warms the permission registrar's cache, so
    // measuring it would compare warm-up against steady state.
    $measure();
    $withOne = $measure();

    User::factory()->count(20)->create();

    expect($measure())->toBe($withOne);
});

it('promotes another user to super admin', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'is_super_admin' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true)
        ->assertJsonPath('data.role', RoleName::SuperAdmin->value);

    expect($target->fresh()->is_super_admin)->toBeTrue();
});

it('demotes another super admin', function () {
    $admin = superAdmin();
    $target = User::factory()->superAdmin()->create();

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'is_super_admin' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', false)
        ->assertJsonPath('data.role', RoleName::Member->value);

    expect($target->fresh()->is_super_admin)->toBeFalse();
});

// The dialog hides the toggle on your own row; this is the server saying the
// same thing, whichever way the flag is pointed.
it('refuses to let a super admin change their own flag', function (bool $value) {
    $admin = superAdmin();

    actingAs($admin)
        ->patchJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_super_admin' => $value,
        ])
        ->assertForbidden();

    expect($admin->fresh()->is_super_admin)->toBeTrue();
})->with([
    'demoting' => [false],
    'promoting' => [true],
]);

it('leaves the flag alone when the update does not mention it', function () {
    $admin = superAdmin();
    $target = User::factory()->superAdmin()->create();

    actingAs($admin)
        ->patchJson("/api/users/{$target->id}", ['name' => 'Renamed Admin', 'email' => $target->email])
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true);

    expect($target->fresh()->is_super_admin)->toBeTrue();
});

it('does not let a plain user promote anyone, themselves included', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    actingAs($actor)
        ->patchJson("/api/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'is_super_admin' => true])
        ->assertForbidden();

    actingAs($actor)
        ->patchJson("/api/users/{$actor->id}", ['name' => $actor->name, 'email' => $actor->email, 'is_super_admin' => true])
        ->assertForbidden();

    expect($actor->fresh()->is_super_admin)->toBeFalse()
        ->and($target->fresh()->is_super_admin)->toBeFalse();
});

// Multipart sends booleans as "1"/"0", which is what the SPA actually posts.
it('accepts the flag as the string multipart sends', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->post("/api/users/{$target->id}", [
            '_method' => 'PATCH',
            'name' => $target->name,
            'email' => $target->email,
            'is_super_admin' => '1',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_super_admin', true);
});

it('requires authentication to create a user', function () {
    postJson('/api/users', userPayload())->assertUnauthorized();
});

it('forbids a signed-in user who is not a super admin from creating one', function () {
    actingAs(User::factory()->create())
        ->postJson('/api/users', userPayload())
        ->assertForbidden();

    $this->assertDatabaseMissing('users', ['email' => 'created@example.com']);
});

it('creates a plain user', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Created User')
        ->assertJsonPath('data.email', 'created@example.com')
        // False, not null: the flag is set explicitly rather than left to the
        // column default, which the in-memory model would not carry.
        ->assertJsonPath('data.is_super_admin', false)
        ->assertJsonPath('data.role', RoleName::Member->value);

    $this->assertDatabaseHas('users', ['email' => 'created@example.com', 'is_super_admin' => false]);
});

it('creates a super admin when the role says so', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload(['is_super_admin' => true]))
        ->assertCreated()
        ->assertJsonPath('data.is_super_admin', true)
        ->assertJsonPath('data.role', RoleName::SuperAdmin->value);

    expect(User::where('email', 'created@example.com')->first()->is_super_admin)->toBeTrue();
});

it('hashes the password rather than storing it raw', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload())
        ->assertCreated();

    $created = User::where('email', 'created@example.com')->first();

    expect($created->password)->not->toBe('password123')
        ->and(Hash::check('password123', $created->password))->toBeTrue();
});

it('never returns the password', function () {
    $data = actingAs(superAdmin())
        ->postJson('/api/users', userPayload())
        ->assertCreated()
        ->json('data');

    expect($data)->not->toHaveKey('password');
});

it('rejects a password that does not match its confirmation', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload(['password_confirmation' => 'something-else']))
        ->assertJsonValidationErrorFor('password');
});

it('rejects a password under the minimum length', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload(['password' => 'short', 'password_confirmation' => 'short']))
        ->assertJsonValidationErrorFor('password');
});

it('rejects an email that already exists', function () {
    User::factory()->create(['email' => 'created@example.com']);

    actingAs(superAdmin())
        ->postJson('/api/users', userPayload())
        ->assertJsonValidationErrorFor('email');
});

it('requires every field the create dialog offers', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', [])
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

it('lists a newly created user straight away', function () {
    $admin = superAdmin();

    actingAs($admin)->postJson('/api/users', userPayload())->assertCreated();

    actingAs($admin)
        ->getJson('/api/users?search=created@example.com')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'created@example.com');
});

// The create dialog offers an optional avatar, exactly as registration does.
// Multipart, but no `_method` spoofing — this route is already POST.
it('attaches an avatar supplied with the new user', function () {
    actingAs(superAdmin())
        ->post('/api/users', userPayload([
            'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ]))
        ->assertCreated()
        ->assertJsonPath('data.has_avatar', true);

    expect(User::where('email', 'created@example.com')->first()->getMedia(User::AVATAR_COLLECTION))
        ->toHaveCount(1);
});

it('falls back to the default image when no avatar is supplied', function () {
    actingAs(superAdmin())
        ->postJson('/api/users', userPayload())
        ->assertCreated()
        ->assertJsonPath('data.has_avatar', false)
        ->assertJsonPath('data.avatar_url', asset(User::DEFAULT_AVATAR_PATH));
});

it('rejects a non-image avatar on create, and stores nothing', function () {
    actingAs(superAdmin())
        ->post('/api/users', userPayload([
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            // Multipart cannot go through postJson(), so the Accept header is what
            // makes the failure come back as a 422 body instead of a redirect.
        ]), ['Accept' => 'application/json'])
        ->assertJsonValidationErrorFor('avatar');

    $this->assertDatabaseMissing('users', ['email' => 'created@example.com']);
});

it('assigns a user to a team', function (string $role) {
    $team = Team::factory()->create(['name' => 'Design']);
    $target = User::factory()->create();

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'team_id' => $team->id,
            'team_role' => $role,
        ])
        ->assertOk()
        ->assertJsonPath('data.team.id', $team->id)
        ->assertJsonPath('data.team.name', 'Design')
        ->assertJsonPath('data.role', $role);

    expect($target->fresh()->teamAssignment()['team_id'])->toBe($team->id);
})->with([
    'as a team admin' => [RoleName::Admin->value],
    'as a member' => [RoleName::Member->value],
]);

// One team per user is the app's rule, not spatie's — spatie would happily hold
// an assignment per team, so assignToTeam() clears before it writes.
it('replaces the assignment when a user moves teams', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($first, RoleName::Admin);

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'team_id' => $second->id,
            'team_role' => RoleName::Member->value,
        ])
        ->assertOk()
        ->assertJsonPath('data.team.id', $second->id);

    expect(DB::table('model_has_roles')->where('model_id', $target->id)->count())->toBe(1);
});

it('clears the team when team_id is null', function () {
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'team_id' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.team', null)
        ->assertJsonPath('data.role', RoleName::Member->value);

    expect(DB::table('model_has_roles')->where('model_id', $target->id)->count())->toBe(0);
});

it('leaves the team alone when the update does not mention it', function () {
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", ['name' => 'Renamed', 'email' => $target->email])
        ->assertOk()
        ->assertJsonPath('data.team.id', $team->id)
        ->assertJsonPath('data.role', RoleName::Admin->value);
});

// req.txt puts the super-admin above teams, so promoting drops the membership
// rather than leaving a stale row behind.
it('clears the team when a user is promoted to super admin', function () {
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Admin);

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'is_super_admin' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.team', null)
        ->assertJsonPath('data.role', RoleName::SuperAdmin->value);

    expect(DB::table('model_has_roles')->where('model_id', $target->id)->count())->toBe(0);
});

it('rejects a team that does not exist', function () {
    $target = User::factory()->create();

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'team_id' => 9999,
        ])
        ->assertJsonValidationErrorFor('team_id');
});

it('rejects a team role outside the enum', function () {
    $team = Team::factory()->create();
    $target = User::factory()->create();

    actingAs(superAdmin())
        ->patchJson("/api/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'team_id' => $team->id,
            'team_role' => 'super-admin',
        ])
        ->assertJsonValidationErrorFor('team_role');
});

// Membership is a row against the team, so trashing the team hides it without
// destroying it — the row has to survive for a restore to mean anything.
it('reports no team while the team is trashed, and again once restored', function () {
    $team = Team::factory()->create();
    $target = User::factory()->create();
    $target->assignToTeam($team, RoleName::Admin);

    $team->delete();

    expect($target->fresh()->teamAssignment())->toBe([])
        ->and($target->fresh()->role())->toBe(RoleName::Member);

    $team->restore();

    expect($target->fresh()->teamAssignment()['team_id'])->toBe($team->id)
        ->and($target->fresh()->role())->toBe(RoleName::Admin);
});

it('lists each user with their team', function () {
    $team = Team::factory()->create(['name' => 'Design']);
    $member = User::factory()->create();
    $member->assignToTeam($team, RoleName::Admin);
    User::factory()->create();

    $rows = collect(actingAs(superAdmin())->getJson('/api/users?per_page=-1')->json('data'))
        ->keyBy('id');

    expect($rows[$member->id]['team']['name'])->toBe('Design')
        ->and($rows[$member->id]['role'])->toBe(RoleName::Admin->value)
        ->and($rows->except($member->id)->firstWhere('is_super_admin', false)['team'])->toBeNull();
});
