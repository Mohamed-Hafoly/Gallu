<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const TEAMS = 4;

    private const MEMBERS_PER_TEAM = 5;

    private const DOCUMENTS_PER_TEAM = 3;

    private const IMAGES_PER_DOCUMENT = 4;

    /**
     * Seed the application's database.
     *
     * Content is created inside the team that owns it, because every rule worth
     * demonstrating is about one actor acting on another's row within a team.
     * A flat seed of images-per-user cannot exercise any of them.
     */
    public function run(): void
    {
        // Roles first: anything assigning one needs them to exist already.
        $this->call(RoleSeeder::class);

        // Deliberately team-less: a super-admin sits above teams, and this is
        // the account used to sign in and see everything.
        User::factory()->create([
            'name' => 'John Doe',
            'email' => 'johndoe@example.com',
            'password' => '12345678',
            'is_super_admin' => true,
        ]);

        $this->call(CategorySeeder::class);

        $imageSeeder = new ImageSeeder;

        for ($i = 0; $i < self::TEAMS; $i++) {
            $this->seedTeam($imageSeeder);
        }

        // Team-less bulk, so the admin users table still has rows to page and
        // sort through.
        User::factory()->count(100)->create();
    }

    /**
     * One team: an admin who owns its documents, members who own the images in
     * them, so "a teammate's image" is a real case in the UI.
     */
    private function seedTeam(ImageSeeder $imageSeeder): void
    {
        $admin = User::factory()->create();
        $team = Team::factory()->for($admin)->create();

        // assignToTeam() is the only writer of membership — it clears any
        // existing pivot rows, which is what enforces one team per user.
        $admin->assignToTeam($team, RoleName::Admin);

        $members = User::factory()->count(self::MEMBERS_PER_TEAM)->create();
        foreach ($members as $member) {
            $member->assignToTeam($team, RoleName::Member);
        }

        $documents = Document::factory()
            ->count(self::DOCUMENTS_PER_TEAM)
            ->for($admin)
            ->create(['team_id' => $team->id]);

        foreach ($documents as $index => $document) {
            // Rotated rather than random so every team reliably has images from
            // more than one member, which is what the "cannot edit a teammate's
            // image" rule needs to be visible.
            $owner = $members[$index % $members->count()];

            $imageSeeder->seedInto($document, $owner, self::IMAGES_PER_DOCUMENT);
        }
    }
}
