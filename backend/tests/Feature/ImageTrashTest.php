<?php

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * The admin images screen lists live and trashed rows in one request and puts
 * the trashed ones in a second table, the way the teams screen does; the
 * document page's "Recently deleted" chip asks the same endpoint for
 * `trashed=only`.
 *
 * That rests on three things being true: `trashed` widens the list for
 * *everyone* signed in, it never widens it past the caller's team, and what it
 * widens to depends on who is asking - a member reaches their own deleted
 * images, an admin the whole team's. The flag is scoped, not refused.
 */

// ------------------------------------------------------------ listing

it('excludes soft-deleted images from the plain listing', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $live = Image::factory()->for($admin)->for($document)->create();
    $trashed = Image::factory()->for($admin)->for($document)->create();
    $trashed->delete();

    actingAs($admin)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $live->id);
});

it('includes soft-deleted images for an admin who asks for them', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $live = Image::factory()->for($admin)->for($document)->create();
    $trashed = Image::factory()->for($admin)->for($document)->create();
    $trashed->delete();

    $response = actingAs($admin)
        ->getJson('/api/images?trashed=with')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$live->id]['deleted_at'])->toBeNull();
    expect($rows[$trashed->id]['deleted_at'])->not->toBeNull();
});

// Was a 403. A member now has a trash of their own - they can delete their own
// images, so they must be able to find them again.
it('serves a member their own trashed images', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    $trashed = Image::factory()->for($member)->for($document)->create();
    $trashed->delete();

    actingAs($member)
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $trashed->id);
});

// The narrowing is the whole point: a member's trash is theirs, not the team's.
it('hides a teammates trashed image from a member', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    $mine = Image::factory()->for($member)->for($document)->create();
    $mine->delete();

    $theirs = Image::factory()->for($other)->for($document)->create();
    $theirs->delete();

    actingAs($member)
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

// An admin is not narrowed: the team's trash is theirs to manage.
it('serves an admin the whole teams trash', function () {
    ['admin' => $admin, 'member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    foreach ([$member, $other] as $owner) {
        Image::factory()->for($owner)->for($document)->create()->delete();
    }

    actingAs($admin)
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

// The chips are separate buckets: All and Yours are live-only, and no amount of
// deleted rows may leak into a listing that did not ask for them.
it('keeps deleted images out of a listing that does not ask for them', function () {
    ['admin' => $admin, 'member' => $member, 'document' => $document] = teamFixture();

    Image::factory()->for($member)->for($document)->create()->delete();
    $live = Image::factory()->for($member)->for($document)->create();

    foreach ([$member, $admin] as $caller) {
        actingAs($caller)
            ->getJson("/api/images?document_id={$document->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id);
    }

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}&owner=mine")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $live->id);
});

// Restore follows the same rule as delete - ImagePolicy::restore delegates to
// ::update - so a member may put back what they binned, and nothing else.
it('lets a member restore their own image but not a teammates', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    $mine = Image::factory()->for($member)->for($document)->create();
    $mine->delete();

    $theirs = Image::factory()->for($other)->for($document)->create();
    $theirs->delete();

    actingAs($member)->postJson("/api/images/{$mine->id}/restore")->assertOk();
    actingAs($member)->postJson("/api/images/{$theirs->id}/restore")->assertForbidden();

    expect($mine->refresh()->trashed())->toBeFalse();
    expect($theirs->refresh()->trashed())->toBeTrue();
});

it('still serves the plain listing to a member', function () {
    ['member' => $member, 'admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->for($admin)->for($document)->create();

    actingAs($member)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lets a super-admin see every team\'s trashed images', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $trashed = Image::factory()->for($admin)->for($document)->create();
    $trashed->delete();

    actingAs(superAdmin())
        ->getJson('/api/images?trashed=with')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $trashed->id);
});

// The widening must survive scopeVisibleTo(), never replace it.
it('does not leak another team\'s trashed images to an admin', function () {
    ['admin' => $admin] = teamFixture();

    $otherTeam = Team::factory()->create();
    $stranger = User::factory()->create();
    $otherDocument = Document::factory()
        ->for($stranger)
        ->create(['team_id' => $otherTeam->id]);

    $trashed = Image::factory()->for($stranger)->for($otherDocument)->create();
    $trashed->delete();

    actingAs($admin)
        ->getJson('/api/images?trashed=with')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// The trashed table's mode: only deleted rows, so the two admin tables never
// show the same image twice.
it('serves only trashed rows for trashed=only', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->for($admin)->for($document)->create();
    $trashed = Image::factory()->for($admin)->for($document)->create();
    $trashed->delete();

    actingAs($admin)
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $trashed->id);
});

// Also once a 403. A member's trashed=only is served but empty until they have
// deleted something of their own - scoped, not refused.
it('serves an empty trash to a member with nothing deleted', function () {
    ['member' => $member, 'admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->for($admin)->for($document)->create()->delete();

    actingAs($member)
        ->getJson('/api/images?trashed=only')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects a trashed mode outside the enum', function () {
    ['admin' => $admin] = teamFixture();

    actingAs($admin)
        ->getJson('/api/images?trashed=everything')
        ->assertStatus(422);
});

// ------------------------------------------------------------ paging

it('pages the listing and reports the total', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->count(15)->for($admin)->for($document)->create();

    actingAs($admin)
        ->getJson('/api/images?per_page=10&page=1')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.total', 15);

    actingAs($admin)
        ->getJson('/api/images?per_page=10&page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

// -1 is the footer's "All". paginate(-1) would emit OFFSET with no LIMIT, which
// is a syntax error in both SQLite and MySQL.
it('serves every row for per_page=-1', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->count(12)->for($admin)->for($document)->create();

    actingAs($admin)
        ->getJson('/api/images?per_page=-1')
        ->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('meta.total', 12);
});

it('counts only the filtered rows in the total', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->for($admin)->for($document)->create(['title' => 'Sunset over the pier']);
    Image::factory()->count(4)->for($admin)->for($document)->create(['title' => 'Something else']);

    actingAs($admin)
        ->getJson('/api/images?search=Sunset')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

// The gallery sends no per_page and expects a document's whole set. Falling back
// to the model's per-page would cap it at 15 and silently drop images.
it('serves every row when no per_page is given', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->count(20)->for($admin)->for($document)->create();

    actingAs($admin)
        ->getJson("/api/images?document_id={$document->id}")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 20);
});

it('rejects per_page=0 rather than treating it as a default', function () {
    ['admin' => $admin] = teamFixture();

    actingAs($admin)
        ->getJson('/api/images?per_page=0')
        ->assertStatus(422);
});

// ------------------------------------------------------------ sort and search

it('sorts by creator, which lives on users rather than images', function () {
    ['team' => $team, 'admin' => $admin, 'document' => $document] = teamFixture();

    $zoe = User::factory()->create(['name' => 'Zoe Zander']);
    $abe = User::factory()->create(['name' => 'Abe Abbott']);
    $zoe->assignToTeam($team, RoleName::Member);
    $abe->assignToTeam($team, RoleName::Member);

    $zoeImage = Image::factory()->for($zoe)->for($document)->create();
    $abeImage = Image::factory()->for($abe)->for($document)->create();

    actingAs($admin)
        ->getJson('/api/images?sort_by=creator&sort_order=asc')
        ->assertOk()
        ->assertJsonPath('data.0.id', $abeImage->id)
        ->assertJsonPath('data.1.id', $zoeImage->id);

    actingAs($admin)
        ->getJson('/api/images?sort_by=creator&sort_order=desc')
        ->assertOk()
        ->assertJsonPath('data.0.id', $zoeImage->id);
});

it('rejects a sort column outside the whitelist', function () {
    ['admin' => $admin] = teamFixture();

    actingAs($admin)
        ->getJson('/api/images?sort_by=user_id')
        ->assertStatus(422);
});

// The description half of this search now goes through a FULLTEXT index, which
// a RefreshDatabase transaction cannot see - InnoDB processes full-text updates
// at commit time - so it lives in ImageFullTextSearchTest instead. What is left
// here is the LIKE side.
it('searches on the title and the creator', function () {
    ['team' => $team, 'admin' => $admin, 'document' => $document] = teamFixture();

    $photographer = User::factory()->create(['name' => 'Ansel Adams']);
    $photographer->assignToTeam($team, RoleName::Member);

    $byTitle = Image::factory()->for($admin)->for($document)
        ->create(['title' => 'Harbour at dawn', 'description' => null]);
    $byCreator = Image::factory()->for($photographer)->for($document)
        ->create(['title' => 'Untitled', 'description' => null]);

    actingAs($admin)
        ->getJson('/api/images?search=Harbour')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $byTitle->id);

    actingAs($admin)
        ->getJson('/api/images?search=Ansel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $byCreator->id);
});

// This listing used to match document_id exactly for an all-digit term. It no
// longer does: Scout takes its searchable columns from the model, and can only
// LIKE them - `document_id LIKE '%1%'` would match documents 1, 10, 11 and 21,
// which is the broken filter the old code called out. Nothing is lost in the UI,
// where the gallery is always pinned to one document by the document_id filter.
it('does not match an image by document id', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    // Digit-free throughout, or the title and creator clauses would match the
    // term on their own and the assertion would prove nothing.
    $admin->forceFill(['name' => 'Echo'])->save();

    foreach (['Alpha', 'Bravo', 'Delta'] as $title) {
        Image::factory()->for($admin)->for($document)->create([
            'title' => $title,
            'description' => null,
        ]);
    }

    actingAs($admin)
        ->getJson("/api/images?search={$document->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

// scout.soft_delete is on, so Scout owns the trashed state: search() seeds a
// __soft_deleted = 0 where, the controller's withTrashed()/onlyTrashed() on the
// *Scout* builder drop or flip it, and constrainForSoftDeletes() turns whichever
// survives into the matching Eloquent constraint.
//
// The assertion below pins the config because the two halves only work
// together. Turn it off and the seeded where disappears, leaving the builder
// calls with nothing to act on; move the calls into query() and
// constrainForSoftDeletes() - which runs *after* that callback - overrides them,
// asking for deleted_at both null and not null so trashed=only returns nothing.
// Either half alone is a silently empty bin.
it('honours the trashed flag alongside a search', function () {
    expect(config('scout.soft_delete'))->toBeTrue();

    ['admin' => $admin, 'document' => $document] = teamFixture();

    $live = Image::factory()->for($admin)->for($document)->create(['title' => 'Findable live']);
    $binned = Image::factory()->for($admin)->for($document)->create(['title' => 'Findable binned']);
    $binned->delete();

    $ids = fn (string $query) => actingAs($admin)
        ->getJson('/api/images?search=Findable&'.$query)
        ->assertOk()
        ->json('data.*.id');

    expect($ids(''))->toBe([$live->id]);
    expect($ids('trashed=only'))->toBe([$binned->id]);
    expect($ids('trashed=with'))->toEqualCanonicalizing([$binned->id, $live->id]);
});

// The engine appends an id tie-break of its own only when no column is declared
// full-text - and `description` is - so this listing has no implicit one at all.
// The existing tie-break test above does not search, so it exercises the
// no-join path; this one covers the joined, searched query specifically.
it('does not repeat an image across pages when the sort ties, while searching', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->count(4)->for($admin)->for($document)->create([
        'title' => 'Findable',
        // Tied to the second, which is what makes the tie-break load-bearing.
        'created_at' => now(),
    ]);

    $ids = [];
    foreach (range(1, 4) as $page) {
        $ids = array_merge($ids, actingAs($admin)
            ->getJson("/api/images?search=Findable&sort_by=created_at&per_page=1&page={$page}")
            ->assertOk()
            ->json('data.*.id'));
    }

    expect($ids)->toHaveCount(4);
    expect(array_unique($ids))->toHaveCount(4);
});

// A search must never widen the team scope: the ORs are grouped for this.
it('does not let a search escape the team scope', function () {
    ['admin' => $admin] = teamFixture();

    $otherTeam = Team::factory()->create();
    $stranger = User::factory()->create(['name' => 'Outsider']);
    $otherDocument = Document::factory()->for($stranger)->create(['team_id' => $otherTeam->id]);
    Image::factory()->for($stranger)->for($otherDocument)->create(['title' => 'Outsider photo']);

    actingAs($admin)
        ->getJson('/api/images?search=Outsider')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// As on documents: a blank term returns early from addTextSearchConstraints(),
// so the unsearched listing reaches constrainForSoftDeletes() by its own route.
it('honours the trashed flag without a search', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $live = Image::factory()->for($admin)->for($document)->create();
    $binned = Image::factory()->for($admin)->for($document)->create();
    $binned->delete();

    $ids = fn (string $query) => actingAs($admin)
        ->getJson('/api/images?per_page=-1&'.$query)
        ->assertOk()
        ->json('data.*.id');

    expect($ids(''))->toBe([$live->id]);
    expect($ids('search='))->toBe([$live->id]);
    expect($ids('trashed=only'))->toBe([$binned->id]);
    expect($ids('trashed=with'))->toEqualCanonicalizing([$binned->id, $live->id]);
});

// ------------------------------------------------------------ restore

// The rule DocumentController::restore enforces one level up: a live child
// always has a live parent, so an image cannot come back into a document that is
// itself in the bin.
it('refuses to restore an image whose document is trashed', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($admin)->for($document)->create();
    $document->delete();

    actingAs(superAdmin())
        ->postJson("/api/images/{$image->id}/restore")
        ->assertStatus(409)
        ->assertJsonPath('message', __('image.documentTrashed'));

    expect($image->fresh()->trashed())->toBeTrue();
});

// Why the guard is an abort_if in the controller rather than an ImagePolicy
// rule: ::restore delegates to ::update, whose first branch returns true for the
// owner without ever consulting the document. A policy could not refuse this
// caller, any more than it could refuse a super-admin past Gate::before.
it('refuses the images own uploader the same restore', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($member)->for($document)->create();
    // Binned on its own first, so the guard is reading the document's current
    // state rather than how this image came to be in the bin.
    $image->delete();
    $document->delete();

    actingAs($member)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertStatus(409);
});

// The third caller, and the one the two above cannot stand in for: an admin
// restoring a *teammate's* image. A super-admin never reaches ImagePolicy at all
// (Gate::before), and the owner returns from ImagePolicy::update()'s first
// branch - so only this path asks the policy which team the image is in.
//
// It used to answer with `$image->document->team_id`, and document() carries
// Document's soft-delete scope: with the document binned that read is a fatal on
// null, so the 409 below arrived as a 500. ImagePolicy::teamOfImage() reads the
// column withTrashed() instead, which both authorises the admin - they may act
// on this image - and lets the controller's guard say "not yet".
it('answers an admin restoring a teammates image under a trashed document with a 409', function () {
    ['admin' => $admin, 'member' => $member, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($member)->for($document)->create();
    $document->delete();

    actingAs($admin)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertStatus(409)
        ->assertJsonPath('message', __('image.documentTrashed'));

    expect($image->fresh()->trashed())->toBeTrue();
});

// The way out the message points at - and the two paths must not double up.
it('brings the image back with the document instead', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($admin)->for($document)->create();
    $document->delete();

    actingAs($admin)->postJson("/api/documents/{$document->id}/restore")->assertOk();

    expect($image->fresh()->trashed())->toBeFalse();

    actingAs($admin)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertNotFound();
});

it('restores a soft-deleted image', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($admin)->for($document)->create();
    $image->delete();

    actingAs($admin)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $image->id)
        ->assertJsonPath('data.deleted_at', null);

    expect($image->fresh()->trashed())->toBeFalse();
});

// The route binds withTrashed(), so a live image resolves here perfectly well
// and would otherwise be "restored" to no effect.
it('404s when restoring an image that is not deleted', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertNotFound();
});

it('lets a member restore their own image but not a teammate\'s', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    $own = Image::factory()->for($member)->for($document)->create();
    $own->delete();

    $theirs = Image::factory()->for($other)->for($document)->create();
    $theirs->delete();

    actingAs($member)
        ->postJson("/api/images/{$own->id}/restore")
        ->assertOk();

    actingAs($member)
        ->postJson("/api/images/{$theirs->id}/restore")
        ->assertForbidden();
});

it('requires authentication to restore', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $image = Image::factory()->for($admin)->for($document)->create();
    $image->delete();

    postJson("/api/images/{$image->id}/restore")->assertUnauthorized();
});

it('refuses a restore across teams', function () {
    ['admin' => $admin] = teamFixture();

    $otherTeam = Team::factory()->create();
    $stranger = User::factory()->create();
    $otherDocument = Document::factory()
        ->for($stranger)
        ->create(['team_id' => $otherTeam->id]);

    $image = Image::factory()->for($stranger)->for($otherDocument)->create();
    $image->delete();

    actingAs($admin)
        ->postJson("/api/images/{$image->id}/restore")
        ->assertForbidden();
});

// Regression guard for the whole design: the gallery shares this endpoint, so
// a trashed image must never reach it.
it('keeps trashed images out of a document listing', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    $trashed = Image::factory()->for($admin)->for($document)->create();
    $trashed->delete();

    actingAs($admin)
        ->getJson("/api/images?document_id={$document->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('serves updated_at and deleted_at on every image', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();

    Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'created_at', 'updated_at', 'deleted_at']]]);
});
