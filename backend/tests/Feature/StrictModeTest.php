<?php

use App\Models\Image;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('runs the suite with eloquent strict mode enabled', function () {
    // AppServiceProvider enables this for every non-production environment,
    // which includes `testing`. Asserted so that turning it off is a failing
    // test rather than a silent loss of coverage for every other test here.
    expect(Model::preventsLazyLoading())->toBeTrue()
        ->and(Model::preventsSilentlyDiscardingAttributes())->toBeTrue()
        ->and(Model::preventsAccessingMissingAttributes())->toBeTrue();
});

it('lists images without tripping a lazy loading violation', function () {
    $user = User::factory()->create();
    // Three rows, not one: Builder::hydrate() only arms the lazy-loading guard
    // when a query returns more than one model, so a single-image fixture would
    // pass this test without ever exercising the check.
    Image::factory()->count(3)->for($user)->create();

    actingAs($user)
        ->getJson('/api/images')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});
