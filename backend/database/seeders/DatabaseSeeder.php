<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Document;
use App\Models\Image;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Orchestration only - each kind of row is seeded by the class that owns it.
 *
 * Deliberately *not* `use WithoutModelEvents`. That trait is
 * `Model::withoutEvents()` around run(), which swaps the Eloquent dispatcher for
 * a NullDispatcher - and every booted() hook in this app (Team, Document,
 * Category and User) is an Eloquent delete or restore event. Muting them buys
 * nothing while the seed only inserts, and breaks binSamples() below the moment
 * it does not: a binned document would keep its images live, which is the exact
 * inconsistency those hooks exist to prevent.
 *
 * Nothing else depended on the trait. Media Library's conversions go through the
 * *application's* event dispatcher rather than the model one, so they were never
 * muted in the first place; and Scout's observer resolves to
 * DatabaseEngine::update(), which is a no-op under SCOUT_DRIVER=database.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Team-less users, so the admin users table has rows to page and sort
     * through beyond the ones that belong to a team.
     */
    private const UNASSIGNED_USERS = 100;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Roles first: anything assigning one needs them to exist already.
        $this->call(RoleSeeder::class);

        // Deliberately team-less: a super-admin sits above teams, and these are
        // the accounts used to sign in and see everything.
        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'johndoe@example.com',
            'password' => '12345678',
            'is_super_admin' => true,
        ]);
        User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'janedoe@example.com',
            'password' => '12345678',
            'is_super_admin' => true,
        ]);

        // Before the teams: ImageSeeder attaches categories to the images it
        // creates, and reads the list once. Seeded after the super-admins
        // because CategorySeeder attributes them to User::firstOrFail().
        //
        // The count is fixed at 20 in CategorySeeder and cannot simply be
        // raised: CategoryFactory picks from a pool of exactly 20 unique en/ar
        // pairs, so asking for a 21st throws an OverflowException.
        $this->call(CategorySeeder::class);

        // Teams bring their documents, and documents their images.
        $this->call(TeamSeeder::class);

        User::factory()->count(self::UNASSIGNED_USERS)->create();

        $this->binSamples();
    }

    /**
     * Put something in every bin.
     *
     * Without this every trash screen in the app renders empty on a fresh
     * install, and so does the retention sweep in routes/console.php - there is
     * nothing for it to find. Each row here is binned through the model rather
     * than by writing deleted_at, so the cascades run for real: the document
     * takes its images down with it, and the team takes both.
     *
     * deleted_at is now(), so every row sits well inside its retention window
     * (images 24h, documents 7d, teams and categories 30d) and is restorable.
     * Leave `composer dev` running for a day and the binned images really will
     * be pruned - that is the feature working, not the seed rotting.
     */
    private function binSamples(): void
    {
        // A document binned by hand, from a team that keeps its other documents
        // live - so the admin table shows a team with rows in both tables.
        $document = Document::query()->inRandomOrder()->first();
        $document?->delete();

        // An image binned on its own under a *live* document, which is the only
        // case that reaches the image bin on its own clock. Excludes anything
        // the delete above already took.
        Image::query()
            ->whereHas('document')
            ->inRandomOrder()
            ->first()
            ?->delete();

        // A whole team, cascading through its documents to their images. Chosen
        // from the teams the document above did not come from, so the two cases
        // stay legible side by side.
        Team::query()
            ->when($document !== null, fn ($query) => $query->whereKeyNot($document->team_id))
            ->inRandomOrder()
            ->first()
            ?->delete();

        Category::query()->inRandomOrder()->first()?->delete();

        // A user, whose documents and images deliberately stay put - binning a
        // member costs the authorship and nothing else. See User::booted().
        User::query()
            ->where('is_super_admin', false)
            ->inRandomOrder()
            ->first()
            ?->delete();
    }
}
