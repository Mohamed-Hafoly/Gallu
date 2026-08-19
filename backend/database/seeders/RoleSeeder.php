<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Seed the application's roles.
     *
     * Roles are created global — `team_id` null — so a single definition is
     * reusable by every team, per the package's teams documentation. The
     * ambient team context is cleared first so the roles don't inherit it.
     */
    public function run(): void
    {
        // The package caches role lookups; a stale cache hides a role that was
        // only just seeded.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId(null);

        // SuperAdmin is deliberately absent: it lives in the
        // `users.is_super_admin` column and is never assigned through spatie.
        foreach ([RoleName::Admin, RoleName::Member] as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        setPermissionsTeamId($previousTeamId);
    }
}
