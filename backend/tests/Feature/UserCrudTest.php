<?php

use App\Enums\RoleName;
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

it('searches on the name and on the email', function (string $term) {
    $admin = superAdmin();
    User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
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
]);

it('treats an empty search as no search at all', function () {
    $admin = superAdmin();
    User::factory()->count(3)->create();

    actingAs($admin)
        ->getJson('/api/users?search=')
        ->assertOk()
        ->assertJsonPath('meta.total', 4);
});

it('exposes the columns the admin table renders', function () {
    $admin = superAdmin();

    $row = actingAs($admin)->getJson('/api/users')->assertOk()->json('data.0');

    expect($row)->toHaveKeys([
        'id', 'name', 'email', 'role', 'avatar_thumb_url', 'created_at', 'updated_at', 'is_super_admin',
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

it('permanently deletes a user', function () {
    $admin = superAdmin();
    $target = User::factory()->create();

    actingAs($admin)
        ->deleteJson("/api/users/{$target->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('users', ['id' => $target->id]);
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
