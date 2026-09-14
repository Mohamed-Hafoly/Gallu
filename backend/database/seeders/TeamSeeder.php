<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Teams, and everything filed under one: an admin who owns the documents, and
 * members who own the images inside them.
 *
 * Built as whole teams rather than flat lists because every rule worth
 * demonstrating is about one actor acting on another's row *within* a team -
 * "a member may not edit a teammate's image", "an admin sees only their own
 * team's entries". A seed of images-per-user cannot exercise any of them.
 */
class TeamSeeder extends Seeder
{
    private const TEAMS = 15;

    /**
     * Inclusive range of members per team, randomised so member counts and the
     * document lists that hang off them are not uniform.
     */
    private const MEMBERS_PER_TEAM = [3, 7];

    /**
     * Seed a full set of teams with their documents and images.
     *
     * Safe to run on its own (`db:seed --class=TeamSeeder`) to add more teams to
     * a database that already has some: it creates its own users and never
     * touches an existing one. Roles are seeded first when missing, because
     * assignToTeam() writes a NOT NULL role_id and there is no ordering
     * guarantee on the standalone path.
     *
     * Returns nothing, as a seeder must - Seeder::__invoke() discards whatever
     * run() hands back, so $this->call() could never see it. DatabaseSeeder
     * reads what it needs back out of the database instead.
     */
    public function run(): void
    {
        if (Role::query()->doesntExist()) {
            $this->call(RoleSeeder::class);
        }

        $documentSeeder = new DocumentSeeder;
        // One instance for the whole run: ImageSeeder memoises the category list
        // and the placeholder pool on itself, so sharing it across every team is
        // what keeps the expanded volume cheap.
        $imageSeeder = new ImageSeeder;

        for ($i = 0; $i < self::TEAMS; $i++) {
            $this->seedTeam($documentSeeder, $imageSeeder);
        }

        $imageSeeder->discardPlaceholders();
    }

    /**
     * One team: an admin, its members, and the documents and images they own.
     *
     * @return array{team: Team, admin: User, members: Collection<int, User>}
     */
    public function seedTeam(?DocumentSeeder $documentSeeder = null, ?ImageSeeder $imageSeeder = null): array
    {
        $documentSeeder ??= new DocumentSeeder;
        $imageSeeder ??= new ImageSeeder;

        $admin = User::factory()->create();
        $team = Team::factory()->for($admin)->create();

        // assignToTeam(), never assignRole(): it is the only writer of
        // membership, it clears any existing pivot rows - which is what enforces
        // one team per user - and it drops the cached `roles` relation around
        // the team-context switch that spatie memoises per process.
        $admin->assignToTeam($team, RoleName::Admin);

        $members = User::factory()->count(random_int(...self::MEMBERS_PER_TEAM))->create();
        foreach ($members as $member) {
            $member->assignToTeam($team, RoleName::Member);
        }

        $documentSeeder->seedInto($team, $admin, $members, $imageSeeder);

        return compact('team', 'admin', 'members');
    }
}
