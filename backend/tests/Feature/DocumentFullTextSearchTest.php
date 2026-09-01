<?php

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * DatabaseMigrations, not RefreshDatabase, and that is the whole reason this
 * file is separate.
 *
 * InnoDB processes full-text index updates at *commit* time. RefreshDatabase
 * wraps each test in a transaction it never commits, so MATCH ... AGAINST
 * cannot see a row the test just inserted - a LIKE finds it and a MATCH returns
 * nothing. Every assertion below would then be meaningless, and the negative
 * ones would pass for entirely the wrong reason.
 *
 * The cost is a migrate:fresh per test rather than a rollback, so keep this file
 * to cases that genuinely need a committed full-text index. Anything searching
 * title, creator or team is a plain LIKE and belongs in DocumentCrudTest.
 */
uses(DatabaseMigrations::class);

/**
 * A team whose title, team name and creator name share no substring with any
 * term searched here, so a hit can only have come from the description.
 *
 * @return array{admin: User, document: Document}
 */
function fullTextFixture(string $description): array
{
    seedRoles();

    $team = Team::factory()->create(['name' => 'Bravo']);
    $admin = User::factory()->create(['name' => 'Charlie']);
    $admin->assignToTeam($team, RoleName::Admin);

    $document = Document::factory()->for($admin)->create([
        'team_id' => $team->id,
        'title' => 'Alpha',
        'description' => $description,
    ]);

    return compact('admin', 'document');
}

it('searches the description as whole words', function () {
    ['admin' => $admin] = fullTextFixture('Analytical engine schematics with brass levers');

    actingAs($admin)
        ->getJson('/api/documents?search=engine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alpha');
});

// The consequence of indexing description rather than LIKEing it. Pinned so it
// is a decision on the record and not a bug report later: `title` keeps its
// wildcarded LIKE precisely because this is how the description now behaves.
it('does not match a description by fragment', function () {
    ['admin' => $admin] = fullTextFixture('Analytical engine schematics with brass levers');

    actingAs($admin)
        ->getJson('/api/documents?search=ngine')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

// innodb_ft_min_token_size is 3 by default, so a shorter term indexes nothing
// and matches nothing.
it('ignores a description term below the minimum token size', function () {
    ['admin' => $admin] = fullTextFixture('Analytical engine schematics with brass levers');

    actingAs($admin)
        ->getJson('/api/documents?search=en')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// "with" is in InnoDB's built-in stopword list, so it is never indexed even
// though it is plainly in the text.
it('ignores a description term on the stopword list', function () {
    ['admin' => $admin] = fullTextFixture('Analytical engine schematics with brass levers');

    actingAs($admin)
        ->getJson('/api/documents?search=with')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// The full-text clause is one branch of the same OR group as the rest, not a
// replacement for it: a title match must still win when the description misses.
it('still matches the title while the description is full-text', function () {
    ['admin' => $admin] = fullTextFixture('Analytical engine schematics with brass levers');

    actingAs($admin)
        // Mid-string, which the description could not match but the title can.
        ->getJson('/api/documents?search=lph')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alpha');
});

it('requires authentication like the rest of the listing', function () {
    fullTextFixture('Analytical engine schematics with brass levers');

    getJson('/api/documents?search=engine')->assertUnauthorized();
});
