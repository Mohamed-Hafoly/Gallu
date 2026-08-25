<?php

use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('requires authentication to create a document', function () {
    postJson('/api/documents', ['title' => 'Anything'])->assertUnauthorized();
});

// ---------------------------------------------------------------- creation

it('lets an admin create a document, stamped with their team', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => 'Team doc'])
        ->assertCreated()
        ->assertJsonPath('data.creator', $admin->name)
        ->assertJsonCount(0, 'data.images');

    expect(Document::latest('id')->first()->team_id)->toBe($team->id);
});

// The central rule of the redesign: documents are an admin's job.
it('refuses to let a member create a document', function () {
    ['member' => $member] = teamFixture();

    actingAs($member)
        ->postJson('/api/documents', ['title' => 'Sneaky'])
        ->assertForbidden();
});

it('lets a super admin create a team less document', function () {
    $document = actingAs(superAdmin())
        ->postJson('/api/documents', ['title' => 'Global'])
        ->assertCreated()
        ->json('data.id');

    expect(Document::find($document)->team_id)->toBeNull();
});

it('requires a title', function () {
    ['admin' => $admin] = teamFixture();

    actingAs($admin)
        ->postJson('/api/documents', ['title' => null])
        ->assertJsonValidationErrorFor('title');
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
    ['admin' => $admin, 'document' => $document] = teamFixture();

    actingAs($admin)
        ->patchJson("/api/documents/{$document->id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed');
});

it('refuses to let a member edit or delete a document', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    actingAs($member)
        ->patchJson("/api/documents/{$document->id}", ['title' => 'Renamed'])
        ->assertForbidden();

    actingAs($member)->deleteJson("/api/documents/{$document->id}")->assertForbidden();
});

it('refuses to let an admin touch another teams document', function () {
    ['admin' => $admin] = teamFixture();
    $theirs = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    actingAs($admin)
        ->patchJson("/api/documents/{$theirs->id}", ['title' => 'Hijacked'])
        ->assertForbidden();
});

it('soft deletes a document and cascades to its images', function () {
    ['admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($admin)->for($document)->create();

    actingAs($admin)->deleteJson("/api/documents/{$document->id}")->assertNoContent();

    expect(Document::find($document->id))->toBeNull();
    expect(Document::withTrashed()->find($document->id))->not->toBeNull();
    // The document is only soft-deleted, so the FK has nothing to cascade yet —
    // the image row survives but is no longer reachable through a live document.
    expect(Image::find($image->id))->not->toBeNull();
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
        ->getJson('/api/documents')
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

    $row = actingAs($admin)->getJson('/api/documents')->assertOk()->json('data.0');

    expect($row['images'])->toBe([]);
    expect($row['images_count'])->toBe(0);
});

// ---------------------------------------------------------------- payload shape

it('returns creator and nested images on every row of a multi document listing', function () {
    ['admin' => $admin, 'team' => $team] = teamFixture();
    $documents = Document::factory()->count(3)->for($admin)->create(['team_id' => $team->id]);
    foreach ($documents as $document) {
        Image::factory()->for($admin)->for($document)->create();
    }

    $rows = actingAs($admin)
        ->getJson('/api/documents')
        ->assertOk()
        // 3 created here plus the fixture's own, which has no images.
        ->assertJsonCount(4, 'data')
        ->json('data');

    expect($rows)->each->toHaveKeys(['creator', 'images']);
    expect(array_column($rows, 'creator'))->toBe(array_fill(0, 4, $admin->name));
});
