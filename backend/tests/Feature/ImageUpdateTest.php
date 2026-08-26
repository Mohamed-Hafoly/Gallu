<?php

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

function createImageFor(User $user, Document $document, array $overrides = []): Image
{
    $imageId = actingAs($user)
        ->postJson('/api/images', array_merge([
            'image' => UploadedFile::fake()->image('photo.jpg', 600, 600),
            'title' => 'Original title',
            'document_id' => $document->id,
            'selected_category_ids' => [Category::factory()->create()->id],
        ], $overrides))
        ->json('data.id');

    return Image::findOrFail($imageId);
}

function validUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Updated title',
        'description' => 'Updated description',
        'selected_category_ids' => [Category::factory()->create()->id],
    ], $overrides);
}

it('requires authentication to update an image', function () {
    $image = Image::factory()->for(User::factory())->create();

    patchJson("/api/images/{$image->id}", validUpdatePayload())->assertUnauthorized();
});

it('updates its own image', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $image = createImageFor($user, $document);
    $categories = Category::factory()->count(2)->create();

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload([
            'selected_category_ids' => $categories->pluck('id')->all(),
        ]))
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated title')
        ->assertJsonPath('data.description', 'Updated description')
        ->assertJsonCount(2, 'data.categories');
});

// ------------------------------------------------------------ the new matrix

// A member may now *see* a teammate's image but not change it. 403, where this
// used to be a 404 from an ownership abort_if — the row is legitimately visible.
it('does not let a member update a teammates image', function () {
    ['member' => $member, 'other' => $other, 'document' => $document] = teamFixture();
    $image = createImageFor($other, $document);

    actingAs($member)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertForbidden();

    expect($image->fresh()->title)->toBe('Original title');
});

it('lets an admin update a team members image', function () {
    ['admin' => $admin, 'member' => $member, 'document' => $document] = teamFixture();
    $image = createImageFor($member, $document);

    actingAs($admin)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated title');
});

it('refuses an admin from another team', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    $image = createImageFor($member, $document);

    $outsider = User::factory()->create();
    $outsider->assignToTeam(Team::factory()->create(), RoleName::Admin);

    actingAs($outsider)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertForbidden();
});

it('lets a super admin update any image', function () {
    ['member' => $member, 'document' => $document] = teamFixture();
    $image = createImageFor($member, $document);

    actingAs(superAdmin())
        ->patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertOk();
});

// ------------------------------------------------------------ titles

it('rejects a duplicate title against a different image in the same document', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    createImageFor($user, $document, ['title' => 'Taken title']);
    $image = createImageFor($user, $document, ['title' => 'Original title']);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Taken title']))
        ->assertJsonValidationErrorFor('title');
});

// The point of scoping to the document rather than the owner: a title only has
// to be unique among the images it sits beside on the document page.
it('allows a title already used in a different document', function () {
    ['member' => $user, 'admin' => $admin, 'team' => $team, 'document' => $document] = teamFixture();
    $another = Document::factory()->for($admin)->create(['team_id' => $team->id]);

    createImageFor($user, $another, ['title' => 'Front cover']);
    $image = createImageFor($user, $document, ['title' => 'Original title']);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Front cover']))
        ->assertOk()
        ->assertJsonPath('data.title', 'Front cover');
});

it('allows keeping the images own unchanged title', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $image = createImageFor($user, $document, ['title' => 'Same title']);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Same title']))
        ->assertOk()
        ->assertJsonPath('data.title', 'Same title');
});

// Uniqueness follows the document, not either user, so who owns the image and
// who is editing it are both irrelevant - only where the image lives counts.
it('rejects a collision with another owners title in the same document', function () {
    ['admin' => $admin, 'member' => $member, 'document' => $document] = teamFixture();
    createImageFor($admin, $document, ['title' => 'Admins own title']);
    $image = createImageFor($member, $document, ['title' => 'Members title']);

    actingAs($admin)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Admins own title']))
        ->assertJsonValidationErrorFor('title');
});

// Same two users, same two titles - only the document differs, and that is
// enough to make the collision disappear.
it('allows another owners title when the images are in different documents', function () {
    ['admin' => $admin, 'member' => $member, 'team' => $team, 'document' => $document] = teamFixture();
    $another = Document::factory()->for($admin)->create(['team_id' => $team->id]);

    createImageFor($admin, $another, ['title' => 'Admins own title']);
    $image = createImageFor($member, $document, ['title' => 'Members title']);

    actingAs($admin)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Admins own title']))
        ->assertOk();
});

// ------------------------------------------------------------ media handling

it('replaces the media file when a new image is uploaded', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $image = createImageFor($user, $document);
    $originalFileName = $image->getFirstMedia(Image::IMAGES_COLLECTION)->file_name;

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload([
            'image' => UploadedFile::fake()->image('new-photo.jpg', 600, 600),
        ]))
        ->assertOk();

    $media = $image->fresh()->getFirstMedia(Image::IMAGES_COLLECTION);
    expect($media->file_name)->not->toBe($originalFileName);
    expect($media->file_name)->not->toBe('new-photo.jpg');
    expect($media->name)->toBe('Updated title');
    expect($image->fresh()->media()->count())->toBe(1);
});

it('keeps the existing media but syncs its name when no new image is uploaded', function () {
    ['member' => $user, 'document' => $document] = teamFixture();
    $image = createImageFor($user, $document);
    $originalFileName = $image->getFirstMedia(Image::IMAGES_COLLECTION)->file_name;

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Renamed title']))
        ->assertOk();

    $media = $image->fresh()->getFirstMedia(Image::IMAGES_COLLECTION);
    expect($media->file_name)->toBe($originalFileName);
    expect($media->name)->toBe('Renamed title');
});
