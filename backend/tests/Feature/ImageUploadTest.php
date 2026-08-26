<?php

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

function validImagePayload(Document $document, array $overrides = []): array
{
    return array_merge([
        'image' => UploadedFile::fake()->image('photo.jpg', 600, 600),
        'title' => 'My photo',
        'document_id' => $document->id,
        'selected_category_ids' => [Category::factory()->create()->id],
    ], $overrides);
}

it('requires authentication to upload an image', function () {
    $document = Document::factory()->for(User::factory())->create();

    postJson('/api/images', validImagePayload($document))->assertUnauthorized();
});

// ------------------------------------------------------------ upload

it('uploads an image into a document', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document))
        ->assertCreated()
        ->assertJsonPath('data.title', 'My photo')
        ->assertJsonPath('data.creator', $user->name)
        ->assertJsonPath('data.document_id', $document->id);

    expect(Image::count())->toBe(1);

    $media = Image::first()->getFirstMedia(Image::IMAGES_COLLECTION);
    expect($media->name)->toBe('My photo');
    expect($media->file_name)->not->toBe('photo.jpg');
    expect($media->file_name)->toEndWith('.jpg');
});

// The core of the redesign: an image cannot exist outside a document.
it('refuses an upload with no document', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document, ['document_id' => null]))
        ->assertJsonValidationErrorFor('document_id');

    expect(Image::count())->toBe(0);
});

it('refuses a document belonging to another team', function () {
    ['member' => $user] = teamFixture();
    $theirs = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);

    actingAs($user)
        ->postJson('/api/images', validImagePayload($theirs))
        ->assertJsonValidationErrorFor('document_id');

    expect(Image::count())->toBe(0);
});

it('refuses a soft deleted document', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $document->delete();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document))
        ->assertJsonValidationErrorFor('document_id');
});

it('rejects a non-image upload', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document, [
            'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]))
        ->assertJsonValidationErrorFor('image');

    expect(Image::count())->toBe(0);
});

it('requires a title', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document, ['title' => null]))
        ->assertJsonValidationErrorFor('title');
});

it('requires at least one category', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document, ['selected_category_ids' => []]))
        ->assertJsonValidationErrorFor('selected_category_ids');
});

it('rejects a duplicate title in the same document', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertCreated();
    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertJsonValidationErrorFor('title');

    expect(Image::count())->toBe(1);
});

// The hole the per-owner scope left open: a title is how an image is told apart
// from its siblings on the document page, so a teammate must not reuse one.
it('rejects a duplicate title from a different user in the same document', function () {
    ['member' => $user, 'other' => $other, 'document' => $document] = teamFixture();

    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertCreated();
    actingAs($other)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertJsonValidationErrorFor('title');

    expect(Image::count())->toBe(1);
});

// And the behaviour bought in exchange: the same title in another document is
// not a collision at all.
it('allows the same title in a different document', function () {
    ['member' => $user, 'admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();
    $another = Document::factory()->for($admin)->create(['team_id' => $team->id]);

    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertCreated();
    actingAs($user)->postJson('/api/images', validImagePayload($another, ['title' => 'Beach']))
        ->assertCreated();

    expect(Image::count())->toBe(2);
});

// The stock unique message would say only "already been taken", which reads as
// a lie now that another document may hold that very title.
it('names the document in the duplicate title message', function () {
    ['member' => $user, 'document' => $document] = teamFixture();

    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertCreated();

    actingAs($user)->postJson('/api/images', validImagePayload($document, ['title' => 'Beach']))
        ->assertJsonPath('errors.title.0', __('image.duplicateTitle'));
});

it('attaches categories on upload', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $categories = Category::factory()->count(2)->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload($document, [
            'selected_category_ids' => $categories->pluck('id')->all(),
        ]))
        ->assertCreated()
        ->assertJsonCount(2, 'data.categories');
});

// ------------------------------------------------------------ visibility

// The behaviour change that matters most: images are team-scoped now, not
// owner-scoped, so a member sees what their teammates uploaded.
it('shows a member their teammates images', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();
    Image::factory()->for($other)->for($document)->create(['title' => 'Theirs']);

    actingAs($member)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Theirs');
});

it('hides images from another team', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    Image::factory()->for($member)->for($document)->create(['title' => 'Mine']);

    $theirDocument = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);
    Image::factory()->for(User::factory())->for($theirDocument)->create(['title' => 'Theirs']);

    actingAs($member)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');
});

it('shows a super admin every teams images', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    Image::factory()->for($member)->for($document)->create();

    $theirDocument = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);
    Image::factory()->for(User::factory())->for($theirDocument)->create();

    actingAs(superAdmin())->getJson('/api/images')->assertOk()->assertJsonCount(2, 'data');
});

it('shows a team less member nothing at all', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    Image::factory()->for($member)->for($document)->create();

    actingAs(User::factory()->create())
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ------------------------------------------------------------ document filter

it('filters the listing to one document', function () {
    ['member' => $member, 'admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();
    Image::factory()->for($member)->for($document)->create(['title' => 'In this doc']);

    $another = Document::factory()->for($admin)->create(['team_id' => $team->id]);
    Image::factory()->for($member)->for($another)->create(['title' => 'In the other doc']);

    actingAs($member)
        ->getJson("/api/images?document_id={$document->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'In this doc');
});

// The filter narrows, it must never widen: it is applied after scopeVisibleTo.
it('returns nothing when filtering by another teams document', function () {
    ['member' => $member] = teamFixture();
    $theirDocument = Document::factory()->for(User::factory())->create([
        'team_id' => Team::factory()->create()->id,
    ]);
    Image::factory()->for(User::factory())->for($theirDocument)->create();

    actingAs($member)
        ->getJson("/api/images?document_id={$theirDocument->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ------------------------------------------------------------ delete

it('lets a member soft delete their own image', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($member)->for($document)->create();

    actingAs($member)->deleteJson("/api/images/{$image->id}")->assertNoContent();

    expect(Image::find($image->id))->toBeNull();
    expect(Image::withTrashed()->find($image->id))->not->toBeNull();
});

it('refuses to let a member delete a teammates image, but lets an admin', function () {
    ['admin' => $admin, 'member' => $member, 'other' => $other, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($other)->for($document)->create();

    actingAs($member)->deleteJson("/api/images/{$image->id}")->assertForbidden();
    expect(Image::find($image->id))->not->toBeNull();

    actingAs($admin)->deleteJson("/api/images/{$image->id}")->assertNoContent();
    expect(Image::find($image->id))->toBeNull();
});

// ------------------------------------------------------------ payload shape

// Three rows, not one: Builder::hydrate() only arms the lazy-loading guard for
// queries returning more than one model.
it('returns a creator on every row of a multi image listing', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    Image::factory()->count(3)->for($member)->for($document)->create();

    $rows = actingAs($member)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->json('data');

    expect($rows)->each->toHaveKeys(['creator', 'document_id']);
    expect(array_column($rows, 'creator'))->toBe(array_fill(0, 3, $member->name));
});
