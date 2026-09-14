<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ], $overrides);
}

it('registers without an avatar and falls back to the default', function () {
    postJson('/api/register', registerPayload())
        ->assertSuccessful();

    $user = User::firstOrFail();

    expect($user->hasMedia(User::AVATAR_COLLECTION))->toBeFalse();
    expect($user->getFirstMediaUrl(User::AVATAR_COLLECTION))
        ->toBe(asset(User::DEFAULT_AVATAR_PATH));
});

it('registers with an avatar and attaches it', function () {
    $this->post('/api/register', registerPayload([
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ]))->assertSessionHasNoErrors();

    $user = User::firstOrFail();

    expect($user->getMedia(User::AVATAR_COLLECTION))->toHaveCount(1);

    $media = $user->getFirstMedia(User::AVATAR_COLLECTION);
    expect($media->file_name)->not->toBe('me.jpg');
    expect($media->file_name)->toEndWith('.jpg');
    expect($media->name)->toBe('Jane Doe');
});

it('rejects a non-image avatar and creates no user', function () {
    postJson('/api/register', registerPayload([
        'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
    ]))->assertJsonValidationErrorFor('avatar');

    expect(User::count())->toBe(0);
});

it('rejects an avatar over the size limit and creates no user', function () {
    postJson('/api/register', registerPayload([
        'avatar' => UploadedFile::fake()->image('huge.jpg')->size(11 * 1024),
    ]))->assertJsonValidationErrorFor('avatar');

    expect(User::count())->toBe(0);
});

it('still rejects a duplicate email once the avatar rule is in play', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    postJson('/api/register', registerPayload([
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ]))->assertJsonValidationErrorFor('email');

    expect(User::count())->toBe(1);
});

it('still requires a matching password confirmation', function () {
    postJson('/api/register', registerPayload([
        'password_confirmation' => 'something-else',
    ]))->assertJsonValidationErrorFor('password');

    expect(User::count())->toBe(0);
});
