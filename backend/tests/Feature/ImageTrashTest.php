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

it('searches title, description, creator and document id', function () {
    ['team' => $team, 'admin' => $admin, 'document' => $document] = teamFixture();

    $photographer = User::factory()->create(['name' => 'Ansel Adams']);
    $photographer->assignToTeam($team, RoleName::Member);

    $byTitle = Image::factory()->for($admin)->for($document)
        ->create(['title' => 'Harbour at dawn', 'description' => null]);
    $byDescription = Image::factory()->for($admin)->for($document)
        ->create(['title' => 'Untitled', 'description' => 'Taken from the harbour wall']);
    $byCreator = Image::factory()->for($photographer)->for($document)
        ->create(['title' => 'Untitled', 'description' => null]);

    $titleHits = actingAs($admin)->getJson('/api/images?search=Harbour')->assertOk()->json('data');
    expect(collect($titleHits)->pluck('id'))
        ->toContain($byTitle->id)
        ->toContain($byDescription->id);

    actingAs($admin)
        ->getJson('/api/images?search=Ansel')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $byCreator->id);

    actingAs($admin)
        ->getJson("/api/images?search={$document->id}")
        ->assertOk()
        ->assertJsonCount(3, 'data');
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

// ------------------------------------------------------------ restore

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
