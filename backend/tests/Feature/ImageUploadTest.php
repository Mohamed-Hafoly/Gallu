<?php

use App\Models\Category;
use App\Models\Image;
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

function validImagePayload(array $overrides = []): array
{
    return array_merge([
        'image' => UploadedFile::fake()->image('photo.jpg', 600, 600),
        'title' => 'My photo',
        'selected_category_ids' => [Category::factory()->create()->id],
    ], $overrides);
}

it('requires authentication to upload an image', function () {
    postJson('/api/images', validImagePayload())
        ->assertUnauthorized();
});

it('uploads an image into the images table', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload())
        ->assertCreated()
        ->assertJsonPath('data.title', 'My photo');

    expect(Image::count())->toBe(1);

    $media = Image::first()->getFirstMedia(Image::IMAGES_COLLECTION);
    expect($media->name)->toBe('My photo');
    expect($media->file_name)->not->toBe('photo.jpg');
    expect($media->file_name)->toEndWith('.jpg');
});

it('rejects a non-image upload', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload([
            'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]))
        ->assertJsonValidationErrorFor('image');

    expect(Image::count())->toBe(0);
});

it('requires a title', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload(['title' => null]))
        ->assertJsonValidationErrorFor('title');

    expect(Image::count())->toBe(0);
});

it('rejects a duplicate title for the same user', function () {
    $user = User::factory()->create();

    actingAs($user)->postJson('/api/images', validImagePayload(['title' => 'Beach Sunset']))
        ->assertCreated();

    actingAs($user)->postJson('/api/images', validImagePayload(['title' => 'Beach Sunset']))
        ->assertJsonValidationErrorFor('title');

    expect(Image::count())->toBe(1);
});

it('allows different users to share the same title', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    actingAs($user)->postJson('/api/images', validImagePayload(['title' => 'Beach Sunset']))
        ->assertCreated();

    actingAs($other)->postJson('/api/images', validImagePayload(['title' => 'Beach Sunset']))
        ->assertCreated();

    expect(Image::count())->toBe(2);
});

it('attaches categories to an image on upload', function () {
    $user = User::factory()->create();
    $categories = Category::factory()->count(2)->create();

    $response = actingAs($user)
        ->postJson('/api/images', validImagePayload([
            'selected_category_ids' => $categories->pluck('id')->all(),
        ]))
        ->assertCreated()
        ->assertJsonCount(2, 'data.categories');

    expect($response->json('data.categories.0.id'))->toBeIn($categories->pluck('id')->all());
});

it('rejects a nonexistent category id', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload(['selected_category_ids' => [999]]))
        ->assertJsonValidationErrorFor('selected_category_ids.0');
});

it('rejects a soft-deleted category id', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $category->delete();

    actingAs($user)
        ->postJson('/api/images', validImagePayload(['selected_category_ids' => [$category->id]]))
        ->assertJsonValidationErrorFor('selected_category_ids.0');

    expect(Image::count())->toBe(0);
});

it('requires at least one category', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/images', validImagePayload(['selected_category_ids' => null]))
        ->assertJsonValidationErrorFor('selected_category_ids');

    actingAs($user)
        ->postJson('/api/images', validImagePayload(['selected_category_ids' => []]))
        ->assertJsonValidationErrorFor('selected_category_ids');

    expect(Image::count())->toBe(0);
});

it('lists only the images belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    actingAs($user)->postJson('/api/images', validImagePayload(['title' => 'Mine']));
    actingAs($other)->postJson('/api/images', validImagePayload(['title' => 'Theirs']));

    actingAs($user)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Mine');
});

it('does not let a user delete someone elses image', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $imageId = actingAs($other)
        ->postJson('/api/images', validImagePayload(['image' => UploadedFile::fake()->image('theirs.jpg')]))
        ->json('data.id');

    actingAs($user)
        ->deleteJson("/api/images/{$imageId}")
        ->assertNotFound();

    expect(Image::withTrashed()->find($imageId))->not->toBeNull();
});

it('soft deletes an own image', function () {
    $user = User::factory()->create();

    $imageId = actingAs($user)
        ->postJson('/api/images', validImagePayload(['image' => UploadedFile::fake()->image('mine.jpg')]))
        ->json('data.id');

    actingAs($user)
        ->deleteJson("/api/images/{$imageId}")
        ->assertNoContent();

    expect(Image::find($imageId))->toBeNull();
    expect(Image::withTrashed()->find($imageId))->not->toBeNull();
});
