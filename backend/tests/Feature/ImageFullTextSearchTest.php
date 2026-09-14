<?php

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * DatabaseMigrations, not RefreshDatabase, and that is the whole reason this
 * file is separate from ImageTrashTest.
 *
 * InnoDB processes full-text index updates at *commit* time. RefreshDatabase
 * wraps each test in a transaction it never commits, so MATCH ... AGAINST
 * cannot see a row the test just inserted - a LIKE finds it and a MATCH returns
 * nothing. Every assertion below would then be meaningless, and the negative
 * ones would pass for entirely the wrong reason.
 *
 * The cost is a migrate:fresh per test, so keep this file to cases that
 * genuinely need a committed full-text index. Anything searching title or
 * creator is a plain LIKE and belongs in ImageTrashTest. Same split as
 * DocumentFullTextSearchTest.
 */
uses(DatabaseMigrations::class);

/**
 * One image whose title and creator name share no substring with any term
 * searched here, so a hit can only have come from the description.
 *
 * @return array{admin: User, image: Image}
 */
function imageFullTextFixture(string $description): array
{
    seedRoles();

    $team = Team::factory()->create(['name' => 'Bravo']);
    $admin = User::factory()->create(['name' => 'Charlie']);
    $admin->assignToTeam($team, RoleName::Admin);

    $document = Document::factory()->for($admin)->create(['team_id' => $team->id]);

    $image = Image::factory()->for($admin)->for($document)->create([
        'title' => 'Alpha',
        'description' => $description,
    ]);

    return compact('admin', 'image');
}

const IMAGE_DESCRIPTION = 'Analytical engine schematics with brass levers';

it('searches the description as whole words', function () {
    ['admin' => $admin, 'image' => $image] = imageFullTextFixture(IMAGE_DESCRIPTION);

    actingAs($admin)
        ->getJson('/api/images?search=engine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $image->id);
});

// The consequence of indexing description rather than LIKEing it. Pinned so it
// is a decision on the record and not a bug report later: `title` keeps its
// wildcarded LIKE precisely because this is how the description now behaves.
it('does not match a description by fragment', function () {
    ['admin' => $admin] = imageFullTextFixture(IMAGE_DESCRIPTION);

    actingAs($admin)
        ->getJson('/api/images?search=ngine')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// innodb_ft_min_token_size is 3 by default, so a shorter term indexes nothing
// and matches nothing.
it('ignores a description term below the minimum token size', function () {
    ['admin' => $admin] = imageFullTextFixture(IMAGE_DESCRIPTION);

    actingAs($admin)
        ->getJson('/api/images?search=en')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// "with" is in InnoDB's built-in stopword list, so it is never indexed even
// though it is plainly in the text.
it('ignores a description term on the stopword list', function () {
    ['admin' => $admin] = imageFullTextFixture(IMAGE_DESCRIPTION);

    actingAs($admin)
        ->getJson('/api/images?search=with')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// The full-text clause is one branch of the same OR group as the rest, not a
// replacement for it: a title match must still win when the description misses.
it('still matches the title while the description is full-text', function () {
    ['admin' => $admin, 'image' => $image] = imageFullTextFixture(IMAGE_DESCRIPTION);

    actingAs($admin)
        // Mid-string, which the description could not match but the title can.
        ->getJson('/api/images?search=lph')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $image->id);
});

// A description match must not escape scopeVisibleTo() any more than a title one
// does - the full-text clause sits inside the same group the team scope ANDs
// with.
it('keeps a description match inside the callers team', function () {
    ['admin' => $admin] = imageFullTextFixture(IMAGE_DESCRIPTION);

    $otherTeam = Team::factory()->create(['name' => 'Zulu']);
    $stranger = User::factory()->create(['name' => 'Outsider']);
    $stranger->assignToTeam($otherTeam, RoleName::Admin);
    $otherDocument = Document::factory()->for($stranger)->create(['team_id' => $otherTeam->id]);

    Image::factory()->for($stranger)->for($otherDocument)->create([
        'title' => 'Foxtrot',
        'description' => 'Another analytical engine entirely',
    ]);

    actingAs($admin)
        ->getJson('/api/images?search=engine')
        ->assertOk()
        // Only the caller's own team's image, never the stranger's.
        ->assertJsonCount(1, 'data');
});

it('requires authentication like the rest of the listing', function () {
    imageFullTextFixture(IMAGE_DESCRIPTION);

    getJson('/api/images?search=engine')->assertUnauthorized();
});
