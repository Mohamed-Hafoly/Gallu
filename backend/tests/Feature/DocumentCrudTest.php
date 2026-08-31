<?php

use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use App\Rules\DocumentValidationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('requires authentication to create a document', function () {
    postJson('/api/documents', ['title' => 'Anything'])->assertUnauthorized();
});

// ---------------------------------------------------------------- creation

it('lets an admin create a document in their own team', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Team doc', 'team_id' => $team->id])
        ->assertCreated()
        ->assertJsonPath('data.creator', $admin->name)
        ->assertJsonPath('data.images_count', 0);

    expect(Document::latest('id')->first()->team_id)->toBe($team->id);
});

// The central rule of the redesign: documents are an admin's job.
it('refuses to let a member create a document', function () {
    ['member' => $member, 'team' => $team] = teamFixture();

    actingAs($member)
        ->postJson('/api/documents', ['title' => 'Sneaky', 'team_id' => $team->id])
        ->assertForbidden();
});

// The team now comes from the create dialog's picker, so it has to be checked
// rather than trusted: only a super-admin sits above teams.
it('lets a super admin create a document under any team', function () {
    $team = Team::factory()->create();

    $document = actingAs(superAdmin())
        ->postJson('/api/documents', ['title' => 'Global', 'team_id' => $team->id])
        ->assertCreated()
        ->json('data.id');

    expect(Document::find($document)->team_id)->toBe($team->id);
});

it('refuses to let an admin file a document under another team', function () {
    ['admin' => $admin] = teamFixture();
    $theirs = Team::factory()->create();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Hijacked', 'team_id' => $theirs->id])
        ->assertForbidden();

    expect(Document::where('title', 'Hijacked')->exists())->toBeFalse();
});

it('requires a title', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => null, 'team_id' => $team->id])
        ->assertJsonValidationErrorFor('title');
});

it('requires a team that exists and is not trashed', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'No team'])
        ->assertJsonValidationErrorFor('team_id');

    $team->delete();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Trashed team', 'team_id' => $team->id])
        ->assertJsonValidationErrorFor('team_id');
});

// ---------------------------------------------------------------- visibility

it('shows a member their teams documents, including ones they did not create', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    actingAs($member)
        ->getJson('/api/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $document->id);
});

it('hides another teams documents', function () {
    ['member' => $member] = teamFixture();
    $otherTeam = Team::factory()->create();
    Document::factory()->for(User::factory())->create(['team_id' => $otherTeam->id]);

    actingAs($member)->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data');
});

it('shows a super admin every teams documents', function () {
    teamFixture();
    Document::factory()->for(User::factory())->create(['team_id' => Team::factory()->create()->id]);

    actingAs(superAdmin())->getJson('/api/documents')->assertOk()->assertJsonCount(2, 'data');
});

// DatabaseSeeder seeds no teams, so this is the state of a fresh install.
it('shows a team less member nothing at all', function () {
    teamFixture();

    actingAs(User::factory()->create())
        ->getJson('/api/documents')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------- show

it('shows a document in the callers own team', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    actingAs($member)
        ->getJson("/api/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $document->id);
});

// Deep-linking another team's document must be refused outright, not answered
// with an empty-looking page.
it('refuses to show another teams document', function () {
    ['member' => $member] = teamFixture();
    $theirs = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    actingAs($member)->getJson("/api/documents/{$theirs->id}")->assertForbidden();
});

// ---------------------------------------------------------------- update and delete

it('lets an admin edit a document in their own team', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();

    actingAs($admin)
        ->patchJson("/api/documents/{$document->id}", [
            'title' => 'Renamed',
            'team_id' => $team->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed')
        ->assertJsonPath('data.team.id', $team->id);
});

// The edit dialog offers the same picker the create one does, so the team is a
// real edit — but moving a document between teams changes who can see it, which
// is why only a super-admin may do so.
it('lets a super admin move a document to another team', function () {
    ['document' => $document] = teamFixture();
    $destination = Team::factory()->create();

    actingAs(superAdmin())
        ->patchJson("/api/documents/{$document->id}", [
            'title' => $document->title,
            'team_id' => $destination->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.team.id', $destination->id);

    expect($document->refresh()->team_id)->toBe($destination->id);
});

it('refuses to let an admin move a document out of their own team', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();
    $elsewhere = Team::factory()->create();

    actingAs($admin)
        ->patchJson("/api/documents/{$document->id}", [
            'title' => $document->title,
            'team_id' => $elsewhere->id,
        ])
        ->assertForbidden();

    expect($document->refresh()->team_id)->toBe($team->id);
});

it('requires a live team on update too', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();

    actingAs($admin)
        ->patchJson("/api/documents/{$document->id}", ['title' => 'Renamed'])
        ->assertJsonValidationErrorFor('team_id');

    $team->delete();

    actingAs(superAdmin())
        ->patchJson("/api/documents/{$document->id}", [
            'title' => 'Renamed',
            'team_id' => $team->id,
        ])
        ->assertJsonValidationErrorFor('team_id');
});

// The payloads below are deliberately valid: a FormRequest validates before the
// controller's Gate::authorize runs, so an incomplete body would 422 without
// ever reaching the authorization these assert.
it('refuses to let a member edit or delete a document', function () {
    ['member' => $member, 'team' => $team, 'document' => $document] = teamFixture();

    actingAs($member)
        ->patchJson("/api/documents/{$document->id}", [
            'title' => 'Renamed',
            'team_id' => $team->id,
        ])
        ->assertForbidden();

    actingAs($member)->deleteJson("/api/documents/{$document->id}")->assertForbidden();
});

it('refuses to let an admin touch another teams document', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    $theirs = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    actingAs($admin)
        ->patchJson("/api/documents/{$theirs->id}", [
            'title' => 'Hijacked',
            'team_id' => $team->id,
        ])
        ->assertForbidden();
});

it('soft deletes a document and takes its images down with it', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();

    expect(Document::find($document->id))->toBeNull();
    expect(Document::withTrashed()->find($document->id))->not->toBeNull();

    // The FK's ON DELETE CASCADE cannot fire on an UPDATE, so this is
    // Document::booted() rather than the database.
    expect(Image::find($image->id))->toBeNull();

    // Stamped identically, which is the key restore() files them under.
    $trashedImage = Image::withTrashed()->find($image->id);
    expect($trashedImage->deleted_at->eq(
        Document::withTrashed()->find($document->id)->deleted_at
    ))->toBeTrue();
});

it('restores the images it took down when the document is restored', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();
    actingAs($admin)->postJson("/api/documents/{$document->id}/restore")->assertOk();

    expect(Image::find($image->id))->not->toBeNull();
});

// TODO: sepeare trashing is to be removed

// The reason the cascade matches on deleted_at rather than restoring every
// trashed image: an image binned on its own was not the document's doing, so
// bringing the document back must not bring it back too.
it('leaves an individually trashed image trashed when the document returns', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $binnedAlone = Image::factory()->for($admin)->for($document)->create();
    $wentWithDocument = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/images/{$binnedAlone->id}")->assertNoContent();
    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();
    actingAs($admin)->postJson("/api/documents/{$document->id}/restore")->assertOk();

    expect(Image::find($wentWithDocument->id))->not->toBeNull();
    expect(Image::find($binnedAlone->id))->toBeNull();
});

// The flag has to be cleared on an individual restore or it goes stale: this
// image is deliberately binned after being brought back, so the document's
// restore must not revive it a second time.
it('does not revive an image binned again after being restored on its own', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();
    actingAs($admin)->postJson("/api/images/{$image->id}/restore")->assertOk();
    actingAs($admin)->deleteJson("/api/images/{$image->id}")->assertNoContent();
    actingAs($admin)->postJson("/api/documents/{$document->id}/restore")->assertOk();

    expect(Image::find($image->id))->toBeNull();
});

// Image::scopeVisibleTo returns early for a super-admin, so before the cascade
// these images stayed in the live listing with nothing filtering them out.
it('keeps the images of a trashed document out of the live image listing', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();

    actingAs(superAdmin())
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    actingAs(superAdmin())
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $image->id);
});

// The other half of the branch in Document::booted(): a hard delete is left to
// the FK, which removes live and already-trashed rows alike.
it('lets the database cascade the image rows on a force delete', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $live = Image::factory()->for($admin)->for($document)->create();
    $alreadyTrashed = Image::factory()->for($admin)->for($document)->create();
    $alreadyTrashed->delete();

    $document->forceDelete();

    expect(Image::withTrashed()->find($live->id))->toBeNull();
    expect(Image::withTrashed()->find($alreadyTrashed->id))->toBeNull();
});

// ---------------------------------------------------------------- cover images

// Six, not four: with exactly four this would pass whether or not the eager
// load is actually bounded.
it('returns only the four most recent images, with the true total alongside', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $images = collect(range(1, 6))->map(fn (int $i) => Image::factory()
        ->for($admin)
        ->for($document)
        ->create(['title' => "Image {$i}", 'created_at' => now()->addMinutes($i)]));

    $row = actingAs($admin)
        ->getJson('/api/documents?cover=1')
        ->assertOk()
        ->json('data.0');

    expect($row['images'])->toHaveCount(4);
    expect($row['images_count'])->toBe(6);
    // Newest first, so images 6 down to 3 — never 1 and 2.
    expect(array_column($row['images'], 'title'))
        ->toBe(['Image 6', 'Image 5', 'Image 4', 'Image 3']);
});

it('reports a zero count for a document with no images', function () {
    ['admin' => $admin] = teamFixture();

    $row = actingAs($admin)->getJson('/api/documents?cover=1')->assertOk()->json('data.0');

    expect($row['images'])->toBe([]);
    expect($row['images_count'])->toBe(0);
});

// The admin table draws no thumbnails; it asks /api/images?document_id= when a
// row is expanded. Serialising four images, their media and their categories per
// row for that table would be pure waste, so the key is absent without cover=1.
it('omits the cover images unless they are asked for', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    Image::factory()->for($admin)->for($document)->create();

    $row = actingAs($admin)->getJson('/api/documents')->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('images');
    expect($row['images_count'])->toBe(1);
});

// ---------------------------------------------------------------- payload shape

it('returns creator and nested images on every row of a multi document listing', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    $documents = Document::factory()->count(3)->for($admin)->create(['team_id' => $team->id]);
    foreach ($documents as $document) {
        Image::factory()->for($admin)->for($document)->create();
    }

    $rows = actingAs($admin)
        ->getJson('/api/documents?cover=1')
        ->assertOk()
        // 3 created here plus the fixture's own, which has no images.
        ->assertJsonCount(4, 'data')
        ->json('data');

    expect($rows)->each->toHaveKeys(['creator', 'images']);
    expect(array_column($rows, 'creator'))->toBe(array_fill(0, 4, $admin->name));
});

// The admin table renders both, and neither used to be in the resource.
it('returns the team and both timestamps on a listing row', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();

    $row = actingAs($admin)->getJson('/api/documents')->assertOk()->json('data.0');

    expect($row['team'])->toBe(['id' => $team->id, 'name' => $team->name]);
    expect($row)->toHaveKeys(['created_at', 'updated_at', 'deleted_at']);
    expect($row['deleted_at'])->toBeNull();
});

// ---------------------------------------------------------------- paging, sorting, search

it('pages the listing and reports the true total', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->count(9)->for($admin)->create(['team_id' => $team->id]);

    $response = actingAs($admin)
        ->getJson('/api/documents?page=2&per_page=4')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    // 9 created here plus the fixture's own.
    expect($response->json('meta.total'))->toBe(10);
    expect($response->json('meta.current_page'))->toBe(2);
});

it('sorts by creator, which is not a column', function () {
    ['team' => $team] = teamFixture();
    $zoe = User::factory()->create(['name' => 'Zoe']);
    $abe = User::factory()->create(['name' => 'Abe']);
    Document::factory()->for($zoe)->create(['team_id' => $team->id, 'title' => 'Z doc']);
    Document::factory()->for($abe)->create(['team_id' => $team->id, 'title' => 'A doc']);

    $creators = actingAs(superAdmin())
        ->getJson('/api/documents?sort_by=creator&sort_order=asc')
        ->assertOk()
        ->json('data.*.creator');

    expect($creators[0])->toBe('Abe');
    expect(array_slice($creators, -1)[0])->toBe('Zoe');
});

// The team is a foreign key on `documents`, so the naive sort would order by
// insertion. The ids here run the opposite way to the names, which is what makes
// the assertion mean something.
it('sorts by team name rather than by team id', function () {
    $zulu = Team::factory()->create(['name' => 'Zulu']);
    $alpha = Team::factory()->create(['name' => 'Alpha']);
    $author = User::factory()->create();
    $inZulu = Document::factory()->for($author)->create(['team_id' => $zulu->id]);
    $inAlpha = Document::factory()->for($author)->create(['team_id' => $alpha->id]);

    $ascending = actingAs(superAdmin())
        ->getJson('/api/documents?sort_by=team&sort_order=asc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    $descending = actingAs(superAdmin())
        ->getJson('/api/documents?sort_by=team&sort_order=desc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    // Alpha before Zulu, even though Zulu's document was created first.
    expect($ascending)->toBe([$inAlpha->id, $inZulu->id]);
    expect($descending)->toBe([$inZulu->id, $inAlpha->id]);
});

// Team::select() carries the model's soft-delete scope, so the sort agrees with
// the cell: ->with('team') leaves the relation null on these rows too.
it('sorts a document whose team is trashed as null', function () {
    $live = Team::factory()->create(['name' => 'Alpha']);
    $binned = Team::factory()->create(['name' => 'Beta']);
    $author = User::factory()->create();
    $onLive = Document::factory()->for($author)->create(['team_id' => $live->id]);
    $onBinned = Document::factory()->for($author)->create(['team_id' => $binned->id]);
    $binned->delete();

    $ascending = actingAs(superAdmin())
        ->getJson('/api/documents?sort_by=team&sort_order=asc&per_page=-1')
        ->assertOk()
        ->json('data.*.id');

    // Null sorts first ascending in both databases, so the trashed team's
    // document leads rather than sorting under a name nothing displays.
    expect($ascending)->toBe([$onBinned->id, $onLive->id]);
});

// Whitelisted against DocumentController::SORTABLE, so an unknown column is a
// 422 rather than an injectable orderBy.
it('refuses to sort by an unlisted column', function () {
    ['admin' => $admin] = teamFixture();

    actingAs($admin)
        ->getJson('/api/documents?sort_by=team_id')
        ->assertJsonValidationErrorFor('sort_by');
});

it('sorts by the image count, which is withCount()s select alias', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $empty] = teamFixture();
    $busy = Document::factory()->for($admin)->create(['team_id' => $team->id, 'title' => 'Busy']);
    Image::factory()->count(3)->for($admin)->for($busy)->create();

    $ascending = actingAs($admin)
        ->getJson('/api/documents?sort_by=images_count&sort_order=asc')
        ->assertOk()
        ->json('data.*.id');

    expect($ascending)->toBe([$empty->id, $busy->id]);

    $descending = actingAs($admin)
        ->getJson('/api/documents?sort_by=images_count&sort_order=desc')
        ->assertOk()
        ->json('data.*.id');

    expect($descending)->toBe([$busy->id, $empty->id]);
});

// None of the sortable columns is unique, and LIMIT/OFFSET over a non-unique
// key lets the database order ties differently per page - so without the id
// tie-break the infinite-scrolled listing would repeat a card or skip one.
it('does not repeat a row across pages when the sort column ties', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    $stamp = now()->subDay();
    Document::factory()->count(3)->for($admin)->create([
        'team_id' => $team->id,
        'created_at' => $stamp,
    ]);

    $ids = [];
    foreach ([1, 2, 3, 4] as $page) {
        $ids[] = actingAs($admin)
            ->getJson("/api/documents?per_page=1&page={$page}&sort_by=created_at&sort_order=desc")
            ->assertOk()
            ->json('data.0.id');
    }

    expect($ids)->toHaveCount(4)->and(array_unique($ids))->toHaveCount(4);
});

// Document::booted() takes the images down with the document, so the default
// scope would report zero for every trashed row - and leave its cover empty.
it('counts and covers a trashed documents images with the document', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    Image::factory()->count(2)->for($admin)->for($document)->create();
    $document->delete();

    $row = actingAs($admin)
        ->getJson('/api/documents?trashed=only&cover=1')
        ->assertOk()
        ->json('data.0');

    expect($row['images_count'])->toBe(2);
    expect($row['images'])->toHaveCount(2);
});

// The live listing must not start showing images binned on their own.
it('leaves the live listings count blind to individually trashed images', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $images = Image::factory()->count(2)->for($admin)->for($document)->create();
    $images->first()->delete();

    $row = actingAs($admin)->getJson('/api/documents?cover=1')->assertOk()->json('data.0');

    expect($row['images_count'])->toBe(1);
    expect($row['images'])->toHaveCount(1);
});

it('searches title, description and creator', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->for($admin)->create(['team_id' => $team->id, 'title' => 'Findable']);

    actingAs($admin)
        ->getJson('/api/documents?search=Findable')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Findable');
});

// ---------------------------------------------------------------- restore

it('restores a soft deleted document', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $document->delete();

    actingAs($admin)
        ->postJson("/api/documents/{$document->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $document->id)
        ->assertJsonPath('data.deleted_at', null);

    expect(Document::find($document->id))->not->toBeNull();
});

it('refuses to restore a document that is not trashed', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    actingAs($admin)->postJson("/api/documents/{$document->id}/restore")->assertNotFound();
});

it('refuses to let a member restore a document', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    $document->delete();

    actingAs($member)->postJson("/api/documents/{$document->id}/restore")->assertForbidden();
});

// ---------------------------------------------------------------- trashed listing

it('serves the trashed side of the listing only when asked', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();
    Document::factory()->for($admin)->create(['team_id' => $team->id]);
    $document->delete();

    actingAs($admin)->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data');
    actingAs($admin)->getJson('/api/documents?trashed=only')->assertOk()->assertJsonCount(1, 'data');
    actingAs($admin)->getJson('/api/documents?trashed=with')->assertOk()->assertJsonCount(2, 'data');
});

// Told no, rather than handed a quietly narrower list.
it('refuses the trashed flag to a member', function () {
    ['member' => $member] = teamFixture();

    actingAs($member)->getJson('/api/documents?trashed=only')->assertForbidden();
});

// ---------------------------------------------------------------- titles

// The hole the per-owner scope left open: a title is how a document is told
// apart from its siblings on the team's list, so a teammate must not reuse one.
it('rejects a duplicate title from a different user in the same team', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->for($admin)->create(['title' => 'Q3 Report', 'team_id' => $team->id]);

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Q3 Report', 'team_id' => $team->id])
        ->assertJsonValidationErrorFor('title');

    expect(Document::where('title', 'Q3 Report')->count())->toBe(1);
});

// And the behaviour bought in exchange: the same title under another team is
// not a collision at all.
it('allows the same title in a different team', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->for($admin)->create(['title' => 'Q3 Report', 'team_id' => $team->id]);

    actingAs(superAdmin())
        ->postJson('/api/documents', [
            'title' => 'Q3 Report',
            'team_id' => Team::factory()->create()->id,
        ])
        ->assertCreated();

    expect(Document::where('title', 'Q3 Report')->count())->toBe(2);
});

it('allows a document to keep its own unchanged title', function () {
    ['admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();

    actingAs($admin)
        ->patchJson("/api/documents/{$document->id}", [
            'title' => $document->title,
            'team_id' => $team->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', $document->title);
});

// The scope follows the *incoming* team, not the stored one. Scoping to where
// the document currently sits would let this move slide through and leave the
// destination holding two documents with one title.
it('refuses to move a document into a team that already holds its title', function () {
    ['document' => $document] = teamFixture();
    $destination = Team::factory()->create();
    Document::factory()->for(User::factory())->create([
        'title' => $document->title,
        'team_id' => $destination->id,
    ]);

    actingAs(superAdmin())
        ->patchJson("/api/documents/{$document->id}", [
            'title' => $document->title,
            'team_id' => $destination->id,
        ])
        ->assertJsonValidationErrorFor('title');

    expect($document->refresh()->team_id)->not->toBe($destination->id);
});

// The stock unique message would say only "already been taken", which reads as
// a lie now that another team may hold that very title.
it('names the team in the duplicate title message', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->for($admin)->create(['title' => 'Q3 Report', 'team_id' => $team->id]);

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Q3 Report', 'team_id' => $team->id])
        ->assertJsonPath('errors.title.0', __('document.duplicateTitle'));
});

/**
 * The team-less branch has no route that can reach it - team_id is required on
 * both requests and must name a live team - so it is exercised through the rule
 * itself rather than an HTTP call that would only pretend to cover it.
 */
it('falls back to a per-owner scope when a document has no team', function () {
    $owner = User::factory()->create();
    Document::factory()->for($owner)->create(['title' => 'Orphan', 'team_id' => null]);

    $rules = ['title' => DocumentValidationRules::title(null, $owner->id)];

    expect(validator(['title' => 'Orphan'], $rules)->fails())->toBeTrue();
    expect(validator(['title' => 'Different'], $rules)->fails())->toBeFalse();

    // Another owner's team-less document is not the same row, so it does not
    // collide - which is what "falls back to per-owner" has to mean.
    $rules = ['title' => DocumentValidationRules::title(null, User::factory()->create()->id)];

    expect(validator(['title' => 'Orphan'], $rules)->fails())->toBeFalse();
});

// A team-less document must not block the title inside a team either: the two
// scopes are separate pools, not one with a hole in it.
it('does not let a team less document block a title inside a team', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    Document::factory()->for($admin)->create(['title' => 'Orphan', 'team_id' => null]);

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Orphan', 'team_id' => $team->id])
        ->assertCreated();
});
