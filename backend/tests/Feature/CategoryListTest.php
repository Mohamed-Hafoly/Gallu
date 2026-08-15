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

it('excludes soft-deleted categories from the listing', function () {
    $user = User::factory()->create();
    Category::factory()->count(2)->create();
    Category::factory()->create()->delete();

    actingAs($user)
        ->getJson('/api/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
