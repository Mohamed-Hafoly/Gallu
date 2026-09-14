<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Teams are super-admin only, per req.txt.
 *
 * Every method denies, exactly as UserPolicy does: `Gate::before` in
 * AppServiceProvider already short-circuits to true for a super-admin, so this
 * policy's only job is to deny everyone else. A role check here would be dead
 * code — the gate never reaches it for the one role that passes.
 */
class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Team $team): bool
    {
        return false;
    }

    public function delete(User $user, Team $team): bool
    {
        return false;
    }

    public function restore(User $user, Team $team): bool
    {
        return false;
    }
}
