<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('requires authentication to create, update, delete or restore a category', function () {
    $category = Category::factory()->create();

    postJson('/api/categories', ['name_en' => 'Sports', 'name_ar' => 'رياضة'])->assertUnauthorized();
    patchJson("/api/categories/{$category->id}", ['name_en' => 'Sports'])->assertUnauthorized();
    deleteJson("/api/categories/{$category->id}")->assertUnauthorized();
    postJson("/api/categories/{$category->id}/restore")->assertUnauthorized();
});

it('creates a category and reports the authenticated user as its creator', function () {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    actingAs($user)
        ->postJson('/api/categories', ['name_en' => 'Sports', 'name_ar' => 'رياضة'])
        ->assertCreated()
        ->assertJsonPath('data.name_en', 'Sports')
        ->assertJsonPath('data.name_ar', 'رياضة')
        ->assertJsonPath('data.creator', 'Ada Lovelace')
        ->assertJsonPath('data.deleted_at', null);

    $this->assertDatabaseHas('categories', [
        'name_en' => 'Sports',
        'user_id' => $user->id,
    ]);
});

it('rejects a category whose name is already taken', function (string $field, string $value) {
    $user = User::factory()->create();
    Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    actingAs($user)
        ->postJson('/api/categories', ['name_en' => 'Fresh', 'name_ar' => 'جديد', $field => $value])
        ->assertJsonValidationErrorFor($field);
})->with([
    'english name' => ['name_en', 'Sports'],
    'arabic name' => ['name_ar', 'رياضة'],
]);

it('rejects a name that a soft-deleted category still holds', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة'])->delete();

    actingAs($user)
        ->postJson('/api/categories', ['name_en' => 'Sports', 'name_ar' => 'مختلف'])
        ->assertJsonValidationErrorFor('name_en');
});

it('requires both names to create a category', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/categories', [])
        ->assertJsonValidationErrors(['name_en', 'name_ar']);
});

it('rejects names longer than 40 characters', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/api/categories', ['name_en' => str_repeat('a', 41), 'name_ar' => 'جديد'])
        ->assertJsonValidationErrorFor('name_en');
});

it('updates one name at a time, leaving the other untouched', function (string $field, string $value) {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    actingAs($user)
        ->patchJson("/api/categories/{$category->id}", [$field => $value])
        ->assertOk()
        ->assertJsonPath("data.{$field}", $value);

    $untouched = $field === 'name_en' ? ['name_ar' => 'رياضة'] : ['name_en' => 'Sports'];

    $this->assertDatabaseHas('categories', ['id' => $category->id, $field => $value] + $untouched);
})->with([
    'english name' => ['name_en', 'Athletics'],
    'arabic name' => ['name_ar', 'ألعاب'],
]);

// What the edit dialog sends whenever both fields were changed.
it('updates both names in one request', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    actingAs($user)
        ->patchJson("/api/categories/{$category->id}", [
            'name_en' => 'Athletics',
            'name_ar' => 'ألعاب',
        ])
        ->assertOk()
        ->assertJsonPath('data.name_en', 'Athletics')
        ->assertJsonPath('data.name_ar', 'ألعاب');

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name_en' => 'Athletics',
        'name_ar' => 'ألعاب',
    ]);
});

it('rejects an update that sends neither name', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    actingAs($user)
        ->patchJson("/api/categories/{$category->id}", [])
        ->assertJsonValidationErrors(['name_en', 'name_ar']);
});

it('lets a category keep its own name while updating', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    actingAs($user)
        ->patchJson("/api/categories/{$category->id}", ['name_en' => 'Sports', 'name_ar' => 'ألعاب'])
        ->assertOk()
        ->assertJsonPath('data.name_ar', 'ألعاب');
});

it('rejects renaming onto another category, trashed or not', function (bool $trashed) {
    $user = User::factory()->create();
    $other = Category::factory()->create(['name_en' => 'Taken', 'name_ar' => 'محجوز']);
    $category = Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    if ($trashed) {
        $other->delete();
    }

    actingAs($user)
        ->patchJson("/api/categories/{$category->id}", ['name_en' => 'Taken'])
        ->assertJsonValidationErrorFor('name_en');
})->with([
    'live category' => [false],
    'trashed category' => [true],
]);

it('soft deletes a category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    actingAs($user)
        ->deleteJson("/api/categories/{$category->id}")
        ->assertNoContent();

    $this->assertSoftDeleted($category);
});

it('returns 404 when updating or deleting an already trashed category', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $category->delete();

    actingAs($user)->patchJson("/api/categories/{$category->id}", ['name_en' => 'Nope'])->assertNotFound();
    actingAs($user)->deleteJson("/api/categories/{$category->id}")->assertNotFound();
});

it('reports deleted_at as null for a live category', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name_en' => 'Live', 'name_ar' => 'حي']);

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonPath('data.0.deleted_at', null);
});

it('restores a trashed category and frees its name again', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);
    $category->delete();

    actingAs($user)
        ->postJson("/api/categories/{$category->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $category->id)
        ->assertJsonPath('data.deleted_at', null);

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('serves every live category unpaginated to the picker, without the creator', function () {
    $user = User::factory()->create();
    Category::factory()->count(15)->create();
    Category::factory()->create()->delete();

    $response = actingAs($user)
        ->getJson('/api/categories/picker')
        ->assertOk()
        ->assertJsonCount(15, 'data');

    expect($response->json('data.0'))->not->toHaveKey('creator');
});

it('treats a padded name as a duplicate, since TrimStrings runs globally', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name_en' => 'Sports', 'name_ar' => 'رياضة']);

    actingAs($user)
        ->postJson('/api/categories', ['name_en' => '  Sports  ', 'name_ar' => 'جديد'])
        ->assertJsonValidationErrorFor('name_en');
});

it('requires authentication to use the picker', function () {
    getJson('/api/categories/picker')->assertUnauthorized();
});

it('returns 404 when restoring a category that is not trashed', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    actingAs($user)
        ->postJson("/api/categories/{$category->id}/restore")
        ->assertNotFound();
});
