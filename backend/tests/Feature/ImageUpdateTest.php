<?php

use App\Models\Category;
use App\Models\Image;
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

function createImageFor(User $user, array $overrides = []): Image
{
    $imageId = actingAs($user)
        ->postJson('/api/images', array_merge([
            'image' => UploadedFile::fake()->image('photo.jpg', 600, 600),
            'title' => 'Original title',
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

    patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertUnauthorized();
});

it('updates its own image', function () {
    $user = User::factory()->create();
    $image = createImageFor($user);
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

it('does not let a user update someone elses image', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $image = createImageFor($other);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload())
        ->assertNotFound();

    expect($image->fresh()->title)->toBe('Original title');
});

it('rejects a duplicate title against a different image of the same user', function () {
    $user = User::factory()->create();
    createImageFor($user, ['title' => 'Taken title']);
    $image = createImageFor($user, ['title' => 'Original title']);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Taken title']))
        ->assertJsonValidationErrorFor('title');
});

it('allows keeping the images own unchanged title', function () {
    $user = User::factory()->create();
    $image = createImageFor($user, ['title' => 'Same title']);

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Same title']))
        ->assertOk()
        ->assertJsonPath('data.title', 'Same title');
});

it('replaces the media file when a new image is uploaded', function () {
    $user = User::factory()->create();
    $image = createImageFor($user);
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
    $user = User::factory()->create();
    $image = createImageFor($user);
    $originalFileName = $image->getFirstMedia(Image::IMAGES_COLLECTION)->file_name;

    actingAs($user)
        ->patchJson("/api/images/{$image->id}", validUpdatePayload(['title' => 'Renamed title']))
        ->assertOk();

    $media = $image->fresh()->getFirstMedia(Image::IMAGES_COLLECTION);
    expect($media->file_name)->toBe($originalFileName);
    expect($media->name)->toBe('Renamed title');
});
