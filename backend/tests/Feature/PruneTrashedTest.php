<?php

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Support\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * Run the sweep exactly as routes/console.php schedules it, order included.
 */
function prune(): void
{
    Artisan::call('model:prune', [
        '--model' => [
            Image::class,
            Document::class,
            Team::class,
            User::class,
            Category::class,
        ],
    ]);
}

/**
 * Backdate a trashed row's deleted_at.
 *
 * A raw update rather than travel(): the windows here run from twenty-four
 * hours to thirty days, and most of these tests need two rows sitting on
 * opposite sides of one boundary. Setting the column directly says which side
 * each is on without moving the clock under everything else.
 *
 * newQueryWithoutScopes() so the soft-delete scope does not hide the very row
 * being aged, and no model events fire - deleted_at is all that changes.
 */
function age(Model $model, string $ago): void
{
    $model->newQueryWithoutScopes()
        ->whereKey($model->getKey())
        ->update(['deleted_at' => now()->sub($ago)]);
}

/**
 * A trashed image carrying a real file on the fake disk.
 */
function trashedImageWithMedia(Document $document): Image
{
    $image = Image::factory()->for($document)->for(User::factory())->create();

    $image->addMedia(UploadedFile::fake()->image('photo.jpg', 100, 100))
        ->toMediaCollection(Image::IMAGES_COLLECTION);

    $image->delete();

    return $image->fresh();
}

/**
 * Whether the row is still in the table at all, trashed or not.
 */
function stillThere(Model $model): bool
{
    return $model->newQueryWithoutScopes()->whereKey($model->getKey())->exists();
}

// --------------------------------------------------------------- the windows

it('destroys a user once past thirty days and not before', function () {
    $doomed = User::factory()->create();
    $spared = User::factory()->create();
    $doomed->delete();
    $spared->delete();

    age($doomed, '31 days');
    age($spared, '29 days');

    prune();

    expect(stillThere($doomed))->toBeFalse();
    expect(stillThere($spared))->toBeTrue();
});

it('destroys a team once past thirty days and not before', function () {
    $doomed = Team::factory()->create();
    $spared = Team::factory()->create();
    $doomed->delete();
    $spared->delete();

    age($doomed, '31 days');
    age($spared, '29 days');

    prune();

    expect(stillThere($doomed))->toBeFalse();
    expect(stillThere($spared))->toBeTrue();
});

it('destroys a document once past seven days and not before', function () {
    $doomed = Document::factory()->for(User::factory())->create();
    $spared = Document::factory()->for(User::factory())->create();
    $doomed->delete();
    $spared->delete();

    age($doomed, '8 days');
    age($spared, '6 days');

    prune();

    expect(stillThere($doomed))->toBeFalse();
    expect(stillThere($spared))->toBeTrue();
});

it('destroys an image once past twenty four hours and not before', function () {
    $document = Document::factory()->for(User::factory())->create();
    $doomed = Image::factory()->for($document)->for(User::factory())->create();
    $spared = Image::factory()->for($document)->for(User::factory())->create();
    $doomed->delete();
    $spared->delete();

    age($doomed, '25 hours');
    age($spared, '23 hours');

    prune();

    expect(stillThere($doomed))->toBeFalse();
    expect(stillThere($spared))->toBeTrue();
});

it('destroys a category once past thirty days and not before', function () {
    $doomed = Category::factory()->create();
    $spared = Category::factory()->create();
    $doomed->delete();
    $spared->delete();

    age($doomed, '31 days');
    age($spared, '29 days');

    prune();

    expect(stillThere($doomed))->toBeFalse();
    expect(stillThere($spared))->toBeTrue();
});

it('leaves a live row alone whatever its age', function () {
    $user = User::factory()->create(['created_at' => now()->subYear()]);

    prune();

    expect(stillThere($user))->toBeTrue();
});

// ---------------------------------------------------- the cascade-aware rule

// The whole reason prunable() is conditional. Document::booted() stamps an
// image with the *document's* deleted_at, so this image is already past its own
// twenty-four hours the moment the document is binned - and the document has
// six days left to be restored whole.
it('spares an image binned with its document while the document survives', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = Image::factory()->for($document)->for(User::factory())->create();

    $document->delete();

    age($document, '2 days');
    age($image, '2 days');

    prune();

    expect(stillThere($image))->toBeTrue();
    expect(stillThere($document))->toBeTrue();
});

it('destroys that image with the document once the documents own window runs out', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = Image::factory()->for($document)->for(User::factory())->create();

    $document->delete();

    age($document, '8 days');
    age($image, '8 days');

    prune();

    expect(stillThere($document))->toBeFalse();
    expect(stillThere($image))->toBeFalse();
});

// The other side of the same rule: a live parent puts the child back on its own
// clock, which is what makes the image bin work at all.
it('destroys an image binned on its own under a live document', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = Image::factory()->for($document)->for(User::factory())->create();

    $image->delete();
    age($image, '25 hours');

    prune();

    expect(stillThere($image))->toBeFalse();
    expect(stillThere($document))->toBeTrue();
});

it('spares a document binned with its team while the team survives', function () {
    $team = Team::factory()->create();
    $document = Document::factory()->for(User::factory())->create(['team_id' => $team->id]);

    $team->delete();

    age($team, '10 days');
    age($document, '10 days');

    prune();

    expect(stillThere($document))->toBeTrue();
    expect(stillThere($team))->toBeTrue();
});

it('destroys a teams documents and images with the team', function () {
    $team = Team::factory()->create();
    $document = Document::factory()->for(User::factory())->create(['team_id' => $team->id]);
    $image = Image::factory()->for($document)->for(User::factory())->create();

    $team->delete();

    age($team, '31 days');
    age($document, '31 days');
    age($image, '31 days');

    prune();

    expect(stillThere($team))->toBeFalse();
    expect(stillThere($document))->toBeFalse();
    expect(stillThere($image))->toBeFalse();
});

// ----------------------------------------------------------------- the media

it('removes an images file and media row when it prunes', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = trashedImageWithMedia($document);
    age($image, '25 hours');

    expect(Media::count())->toBeOne();

    prune();

    expect(stillThere($image))->toBeFalse();
    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

// The regression Document::pruning() exists for. images.document_id is
// ON DELETE CASCADE, so without the hook the rows vanish in SQL, spatie's
// deleting hook never fires, and the files are orphaned on disk for good - the
// media table carries no foreign key, so nothing else would ever collect them.
it('removes the files of a documents images when it prunes', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = trashedImageWithMedia($document);

    $document->delete();
    age($document, '8 days');
    age($image, '8 days');

    prune();

    expect(stillThere($image))->toBeFalse();
    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

// Two levels of SQL cascade now that documents.team_id is cascadeOnDelete, so
// the same trap one rung higher.
it('removes the files of a teams images when it prunes', function () {
    $team = Team::factory()->create();
    $document = Document::factory()->for(User::factory())->create(['team_id' => $team->id]);
    $image = trashedImageWithMedia($document);

    $team->delete();
    age($team, '31 days');
    age($document, '31 days');
    age($image, '31 days');

    prune();

    expect(stillThere($team))->toBeFalse();
    expect(stillThere($image))->toBeFalse();
    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

it('removes a pruned users avatar', function () {
    $user = User::factory()->create();
    $user->addMedia(UploadedFile::fake()->image('me.jpg', 100, 100))
        ->toMediaCollection(User::AVATAR_COLLECTION);
    $user->delete();
    age($user, '31 days');

    prune();

    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

// ---------------------------------------------------- a user is no container

// The deliberate non-cascade, one step on from the soft delete. Both
// scopeVisibleTo() methods scope by team, so the content belongs to the team:
// destroying a member costs the authorship and nothing else.
it('leaves a pruned users content behind with the authorship dropped', function () {
    ['team' => $team, 'admin' => $admin, 'document' => $document] = teamFixture();
    $image = Image::factory()->for($document)->for($admin)->create();

    $admin->delete();
    age($admin, '31 days');

    prune();

    expect(stillThere($admin))->toBeFalse();
    expect($document->fresh()->user_id)->toBeNull();
    expect($document->fresh()->team_id)->toBe($team->id);
    expect($image->fresh()->user_id)->toBeNull();
});

// model_has_roles carries a foreign key on role_id only, so nothing at the
// database level would remove a row keyed by model_id.
it('drops a pruned users membership row', function () {
    ['admin' => $admin] = teamFixture();

    $admin->delete();
    age($admin, '31 days');

    prune();

    expect(
        DB::table(Config::modelHasRolesTable())
            ->where(Config::morphKey(), $admin->getKey())
            ->where('model_type', $admin->getMorphClass())
            ->exists()
    )->toBeFalse();
});

it('drops a pruned teams membership rows', function () {
    ['team' => $team] = teamFixture();

    $team->delete();
    age($team, '31 days');

    prune();

    expect(
        DB::table(Config::modelHasRolesTable())
            ->where(Config::teamForeignKey(), $team->getKey())
            ->exists()
    )->toBeFalse();
});

// The members themselves are not the team's to destroy - they may be moved into
// another team tomorrow.
it('leaves a pruned teams members alone', function () {
    ['team' => $team, 'admin' => $admin] = teamFixture();

    $team->delete();
    age($team, '31 days');

    prune();

    expect(stillThere($admin))->toBeTrue();
    expect($admin->fresh()->teamAssignment())->toBe([]);
});

// ------------------------------------------------------- the RESTRICT pivot

// category_image.category_id is the one foreign key in this schema with no
// onDelete clause, so a force delete raises an integrity constraint violation
// unless Category::pruning() clears the pivot first.
it('detaches a pruned categorys images rather than failing on the pivot', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = Image::factory()->for($document)->for(User::factory())->create();
    $category = Category::factory()->create();
    $image->categories()->attach($category);

    $category->delete();
    age($category, '31 days');

    prune();

    expect(stillThere($category))->toBeFalse();
    expect(stillThere($image))->toBeTrue();
    expect(DB::table('category_image')->where('category_id', $category->id)->exists())->toBeFalse();
});

// A trashed category keeps its images attached, so a restore is lossless - the
// pivot must survive the sweep that spares it.
it('leaves the pivot alone for a category inside its window', function () {
    $document = Document::factory()->for(User::factory())->create();
    $image = Image::factory()->for($document)->for(User::factory())->create();
    $category = Category::factory()->create();
    $image->categories()->attach($category);

    $category->delete();
    age($category, '29 days');

    prune();

    expect(DB::table('category_image')->where('category_id', $category->id)->exists())->toBeTrue();
});

// --------------------------------------------------------------- the wiring

// The windows are worth nothing if nothing runs them. Pins the cadence and the
// fact that there is exactly one such entry.
it('registers the sweep on the scheduler every five minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'model:prune'));

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('*/5 * * * *');
});
