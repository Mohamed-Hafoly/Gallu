<?php

use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * The images listing's read-side filters. The document page pages through this
 * endpoint 20 rows at a time and offers an "All / Yours" chip, so two things
 * have to hold: `owner` narrows to the caller's own rows, and it narrows the
 * team-scoped list rather than replacing the scope with an ownership test.
 */

// ------------------------------------------------------------ owner filter

it('returns the whole team\'s images when no owner filter is sent', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    Image::factory()->for($member)->for($document)->create();
    Image::factory()->for($other)->for($document)->create();

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('narrows the listing to the caller\'s own images', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();

    $mine = Image::factory()->for($member)->for($document)->create();
    Image::factory()->for($other)->for($document)->create();

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}&owner=mine")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

// The filter composes with scopeVisibleTo(), it does not replace it: an image
// the caller owns but which now lives in another team's document must stay out.
it('does not let the owner filter reach outside the caller\'s team', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    $mine = Image::factory()->for($member)->for($document)->create();

    $otherTeam = Team::factory()->create();
    $otherDocument = Document::factory()
        ->for(User::factory()->create())
        ->create(['team_id' => $otherTeam->id]);

    Image::factory()->for($member)->for($otherDocument)->create();

    actingAs($member)
        ->getJson('/api/images?owner=mine')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

it('rejects an unknown owner value', function () {
    ['member' => $member] = teamFixture();

    actingAs($member)
        ->getJson('/api/images?owner=theirs')
        ->assertStatus(422)
        ->assertJsonValidationErrors('owner');
});

// ------------------------------------------------------------ pagination

it('pages the listing and reports an honest total', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    Image::factory()->count(25)->for($member)->for($document)->create();

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}&per_page=20&page=1")
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}&per_page=20&page=2")
        ->assertOk()
        ->assertJsonCount(5, 'data');
});

// ------------------------------------------------------------ stable paging

/**
 * Paging over a non-unique sort key is only safe with a tie-break: the database
 * may order ties differently per page, so without one page 2 can repeat a row
 * from page 1. The gallery's sort control makes created_at reachable, and
 * images created in one batch share it.
 */
it('does not repeat a row across pages when the sort key ties', function () {
    ['member' => $member, 'document' => $document] = teamFixture();

    $sharedTimestamp = now()->subDay();

    Image::factory()->count(6)->for($member)->for($document)->create([
        'created_at' => $sharedTimestamp,
    ]);

    $seen = [];

    foreach ([1, 2, 3] as $page) {
        $response = actingAs($member)
            ->getJson("/api/images?document_id={$document->id}&sort_by=created_at&sort_order=desc&per_page=2&page={$page}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $seen = [...$seen, ...collect($response->json('data'))->pluck('id')->all()];
    }

    expect($seen)->toHaveCount(6);
    expect(array_unique($seen))->toHaveCount(6);
});
