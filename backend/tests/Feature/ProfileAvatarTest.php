<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * The SPA posts the profile form as multipart spoofing PUT, so the avatar
 * cases go through post() rather than put().
 */
function profilePayload(User $user, array $overrides = []): array
{
    return array_merge([
        '_method' => 'PUT',
        'name' => $user->name,
        'email' => $user->email,
    ], $overrides);
}

it('falls back to the default avatar for a user who has not uploaded one', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.has_avatar', false)
        ->assertJsonPath('data.avatar_url', asset(User::DEFAULT_AVATAR_PATH))
        ->assertJsonPath('data.avatar_thumb_url', asset(User::DEFAULT_AVATAR_PATH));
});

// TODO not tobe?
it('uploads an avatar with the profile form', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post('/api/user/profile-information', profilePayload($user, [
            'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
        ]))
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->getMedia(User::AVATAR_COLLECTION))->toHaveCount(1);

    $media = $user->getFirstMedia(User::AVATAR_COLLECTION);
    expect($media->file_name)->not->toBe('me.jpg');
    expect($media->file_name)->toEndWith('.jpg');

    actingAs($user)
        ->getJson('/api/user')
        ->assertJsonPath('data.has_avatar', true);
});

it('replaces the previous avatar instead of stacking a second one', function () {
    $user = User::factory()->create();

    foreach (['first.jpg', 'second.jpg'] as $name) {
        actingAs($user)
            ->post('/api/user/profile-information', profilePayload($user, [
                'avatar' => UploadedFile::fake()->image($name, 600, 600),
            ]))
            ->assertSessionHasNoErrors();
    }

    expect($user->refresh()->getMedia(User::AVATAR_COLLECTION))->toHaveCount(1);
});

it('rejects a non-image avatar', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/user/profile-information', profilePayload($user, [
            'avatar' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ]))
        ->assertJsonValidationErrorFor('avatar');

    expect($user->refresh()->hasMedia(User::AVATAR_COLLECTION))->toBeFalse();
});

it('rejects an avatar over the size limit', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/user/profile-information', profilePayload($user, [
            'avatar' => UploadedFile::fake()->image('huge.jpg')->size(11 * 1024),
        ]))
        ->assertJsonValidationErrorFor('avatar');

    expect($user->refresh()->hasMedia(User::AVATAR_COLLECTION))->toBeFalse();
});

it('removes an avatar and returns to the default', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/api/user/profile-information', profilePayload($user, [
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ]));

    expect($user->refresh()->hasMedia(User::AVATAR_COLLECTION))->toBeTrue();

    actingAs($user)
        ->post('/api/user/profile-information', profilePayload($user, [
            'remove_avatar' => '1',
        ]))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->hasMedia(User::AVATAR_COLLECTION))->toBeFalse();

    actingAs($user)
        ->getJson('/api/user')
        ->assertJsonPath('data.has_avatar', false)
        ->assertJsonPath('data.avatar_url', asset(User::DEFAULT_AVATAR_PATH));
});

it('keeps the existing avatar when the form carries neither a file nor a removal', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/api/user/profile-information', profilePayload($user, [
        'avatar' => UploadedFile::fake()->image('me.jpg', 600, 600),
    ]));

    $fileName = $user->refresh()->getFirstMedia(User::AVATAR_COLLECTION)->file_name;

    actingAs($user)
        ->putJson('/api/user/profile-information', [
            'name' => 'Renamed User',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->getFirstMedia(User::AVATAR_COLLECTION)->file_name)->toBe($fileName);
});

it('still updates name and email without any avatar fields', function () {
    $user = User::factory()->create(['name' => 'Old Name']);

    actingAs($user)
        ->putJson('/api/user/profile-information', [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->name)->toBe('New Name');
    expect($user->email)->toBe('new@example.com');
});

it('requires authentication to update the profile', function () {
    $this->putJson('/api/user/profile-information', [
        'name' => 'Somebody',
        'email' => 'somebody@example.com',
    ])->assertUnauthorized();
});
