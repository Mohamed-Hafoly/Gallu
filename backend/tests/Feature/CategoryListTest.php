<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('requires authentication to list categories', function () {
    getJson('/api/categories')->assertUnauthorized();
});

it('lists all categories', function () {
    $user = User::factory()->create();
    Category::factory()->count(3)->create();

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'name_en', 'name_ar']]]);
});

// The admin screen needs the trashed rows to populate its "pending deletion"
// table. The picker endpoint is the one that filters them out.
it('includes soft-deleted categories in the listing', function () {
    $user = User::factory()->create();
    Category::factory()->count(2)->create();
    Category::factory()->create()->delete();

    $response = actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    expect(collect($response->json('data'))->whereNotNull('deleted_at'))->toHaveCount(1);
});

it('exposes the name of the user who created the category', function () {
    $user = User::factory()->create();
    $creator = User::factory()->create(['name' => 'Ada Lovelace']);
    Category::factory()->for($creator)->create();

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonPath('data.0.creator', 'Ada Lovelace');
});

it('still lists a category whose creator was deleted, with a null creator', function () {
    $user = User::factory()->create();
    $creator = User::factory()->create();
    Category::factory()->for($creator)->create();

    $creator->delete();

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.creator', null);
});
